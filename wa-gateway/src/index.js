import { loadConfig } from './config.js';
import { WhatsAppConnection } from './connection.js';
import { createPool, migrate } from './db.js';
import { SessionLock } from './lock.js';
import { createLogger } from './logger.js';
import { Outbox } from './outbox.js';
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

process.on('unhandledRejection', (reason) => {
  logger.error({ err: { message: reason?.message ?? String(reason) } }, 'unhandledRejection');
});
process.on('uncaughtException', (err) => {
  logger.error({ err: { message: err.message, stack: err.stack } }, 'uncaughtException');
});

async function main() {
  const app = createApp({ config, logger, connection, outbox, lock });

  // Nyalakan HTTP server TERLEBIH DAHULU agar 127.0.0.1:3001 selalu langsung
  // merespons permintaan dari aplikasi Laravel tanpa pernah gagal terhubung.
  const server = app.listen(config.port, config.host, () => {
    logger.info({ host: config.host, port: config.port }, 'Gateway HTTP siap');
  });
  server.requestTimeout = 45000;
  server.headersTimeout = 20000;

  try {
    await migrate(pool, config.schema);
    await outbox.recoverInterrupted();
  } catch (err) {
    logger.warn({ err: { message: err.message } }, 'Inisialisasi tabel selesai dengan fallback');
  }

  outbox.start();
  lock
    .acquire()
    .then((acquired) => {
      if (acquired) {
        return connection.start();
      }
    })
    .catch((err) => {
      logger.error({ err: { message: err.message } }, 'Gagal memulai koneksi awal WhatsApp');
    });

  let shuttingDown = false;
  const shutdown = async (signal) => {
    if (shuttingDown) {
      return;
    }
    shuttingDown = true;
    logger.info({ signal }, 'Menghentikan gateway');
    const force = setTimeout(() => process.exit(0), 10000);
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
  logger.error({ err: { message: err.message, code: err.code } }, 'Kesalahan pada main gateway');
});
