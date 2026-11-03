import { loadConfig } from './config.js';
import { WhatsAppConnection } from './connection.js';
import { createPool, migrate } from './db.js';
import { SessionLock } from './lock.js';
import { createLogger } from './logger.js';
import { Outbox } from './outbox.js';
import { STATUS } from './policy.js';
import { createApp } from './server.js';

let config;
try {
  config = loadConfig();
} catch (err) {
  console.error(`Konfigurasi tidak valid: ${err.message}`);
  process.exit(1);
}

const logger = createLogger(config.logLevel);
const pool = createPool(config, logger);
const lock = new SessionLock(pool, logger);
const connection = new WhatsAppConnection({ pool, config, logger });
const outbox = new Outbox({ pool, config, logger, connection });

// Baileys diketahui kadang meninggalkan promise yang ditolak tanpa ditangani
// (mis. query init habis waktu) walau koneksinya sendiri tetap sehat; cukup
// dicatat. uncaughtException berarti state proses tidak bisa dipercaya lagi,
// jadi keluar dan biarkan Render menyalakan ulang (sesi aman di Postgres).
process.on('unhandledRejection', (reason) => {
  logger.error({ err: { message: reason?.message ?? String(reason) } }, 'unhandledRejection');
});
process.on('uncaughtException', (err) => {
  logger.fatal({ err: { message: err.message, stack: err.stack } }, 'uncaughtException, proses dihentikan');
  process.exit(1);
});

async function holdLockAndConnect() {
  connection.setStatus(STATUS.WAITING_LOCK);
  const acquired = await lock.acquire();
  if (!acquired) {
    return;
  }
  await connection.start();
}

lock.on('lost', () => {
  connection
    .stop(STATUS.WAITING_LOCK)
    .then(() => {
      lock.stopped = false;
      return holdLockAndConnect();
    })
    .catch((err) => logger.error({ err: { message: err.message } }, 'Gagal memulihkan setelah lock hilang'));
});

async function main() {
  await migrate(pool, config.schema);
  await outbox.recoverInterrupted();

  const app = createApp({ config, logger, connection, outbox, lock });
  // HTTP menyala lebih dulu supaya health check Render lolos walau gateway
  // masih menunggu lock dari instance lama saat deploy.
  const server = app.listen(config.port, config.host, () => {
    logger.info({ host: config.host, port: config.port }, 'Gateway HTTP siap');
  });
  server.requestTimeout = 30000;
  server.headersTimeout = 15000;

  outbox.start();
  holdLockAndConnect().catch((err) => {
    logger.error({ err: { message: err.message } }, 'Gagal memulai koneksi WhatsApp');
  });

  let shuttingDown = false;
  const shutdown = async (signal) => {
    if (shuttingDown) {
      return;
    }
    shuttingDown = true;
    logger.info({ signal }, 'Menghentikan gateway');
    const force = setTimeout(() => process.exit(1), 15000);
    force.unref();
    server.close();
    outbox.stop();
    try {
      await connection.stop();
      await lock.release();
      await pool.end();
    } catch (err) {
      logger.error({ err: { message: err.message } }, 'Kesalahan saat berhenti');
    }
    process.exit(0);
  };
  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('SIGINT', () => shutdown('SIGINT'));
}

main().catch((err) => {
  logger.fatal({ err: { message: err.message, code: err.code } }, 'Gateway gagal dijalankan');
  process.exit(1);
});
