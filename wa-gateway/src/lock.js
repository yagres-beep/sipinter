import { EventEmitter } from 'node:events';

const LOCK_KEY_SQL = "hashtext('sipinter-wa-gateway-session')";

/**
 * Advisory lock Postgres agar hanya SATU proses yang memakai sesi WhatsApp.
 * Saat Render deploy ulang, instance baru menyala sebelum instance lama
 * dimatikan; tanpa lock keduanya tersambung dengan kredensial yang sama dan
 * WhatsApp memutus salah satunya (kode 440 connectionReplaced).
 *
 * Event 'lost' dipancarkan bila koneksi pemegang lock putus (lock ikut lepas).
 */
export class SessionLock extends EventEmitter {
  constructor(pool, logger, { retryMs = 5000 } = {}) {
    super();
    this.pool = pool;
    this.logger = logger;
    this.retryMs = retryMs;
    this.client = null;
    this.held = false;
    this.stopped = false;
  }

  async acquire() {
    while (!this.stopped) {
      try {
        const client = await this.pool.connect();
        const { rows } = await client.query(`SELECT pg_try_advisory_lock(${LOCK_KEY_SQL}) AS ok`);
        if (rows[0]?.ok) {
          this.client = client;
          this.held = true;
          client.on('error', (err) => this.#onClientError(err));
          this.logger.info('Lock sesi WhatsApp diperoleh');
          return true;
        }
        client.release();
        this.logger.info('Lock sesi dipegang proses lain (mis. instance lama saat deploy), menunggu');
      } catch (err) {
        this.logger.warn({ err: { message: err.message, code: err.code } }, 'Gagal meminta lock sesi, mencoba lagi');
      }
      await sleep(this.retryMs);
    }
    return false;
  }

  #onClientError(err) {
    if (!this.held) {
      return;
    }
    this.logger.error({ err: { message: err.message, code: err.code } }, 'Koneksi pemegang lock putus, lock dianggap hilang');
    this.held = false;
    const client = this.client;
    this.client = null;
    try {
      client?.release(err);
    } catch {
      // koneksi sudah rusak; tidak ada yang perlu dilepas lagi
    }
    this.emit('lost');
  }

  async release() {
    this.stopped = true;
    if (!this.client) {
      return;
    }
    const client = this.client;
    this.client = null;
    this.held = false;
    try {
      await client.query(`SELECT pg_advisory_unlock(${LOCK_KEY_SQL})`);
      client.release();
    } catch (err) {
      client.release(err);
    }
  }
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
