import { decrypt, encrypt, sha256Hex } from './crypto.js';
import { outboxTable } from './db.js';
import { maskPhone } from './logger.js';
import { STATUS, isStatusUpgrade, receiptToStatus } from './policy.js';

const TICK_MS = 5000;
const MAINTENANCE_MS = 60000;
const PURGE_MS = 60 * 60 * 1000;

export class IdempotencyConflictError extends Error {
  constructor() {
    super('idempotency_key sudah dipakai untuk nomor/isi pesan yang berbeda.');
    this.code = 'idempotency_conflict';
  }
}

export function publicView(row) {
  return {
    idempotency_key: row.idempotency_key,
    status: row.status,
    attempts: row.attempts,
    error_code: row.error_code,
    error_message: row.error_message,
    created_at: row.created_at,
    sent_at: row.sent_at,
    delivered_at: row.delivered_at,
    expires_at: row.expires_at,
    updated_at: row.updated_at,
  };
}

/**
 * Antrean pesan persisten di Postgres. Prinsip anti pesan ganda:
 * - idempotency_key unik: permintaan ulang dari Laravel tidak membuat baris baru.
 * - Pesan diambil satu per satu dengan status 'sending' (FOR UPDATE SKIP LOCKED).
 * - Bila proses mati saat 'sending', baris menjadi 'unknown' dan TIDAK dikirim
 *   ulang (lebih baik satu pengingat hilang daripada terkirim dua kali).
 * - Retry otomatis hanya untuk kegagalan yang pasti terjadi sebelum pesan keluar.
 */
export class Outbox {
  constructor({ pool, config, logger, connection, random = Math.random }) {
    this.pool = pool;
    this.config = config;
    this.logger = logger.child({ module: 'outbox' });
    this.connection = connection;
    this.random = random;
    this.table = outboxTable(config.schema);
    this.timer = null;
    this.busy = false;
    this.stopped = true;
    this.nextSendAllowedAt = 0;
    this.lastMaintenanceAt = 0;
    this.lastPurgeAt = 0;
    this.rateLimitLoggedAt = 0;
    this.onReceipt = this.onReceipt.bind(this);
    this.kick = this.kick.bind(this);
  }

  #enc(value, key, field) {
    return encrypt(this.config.encryptionKey, value, `${key}:${field}`);
  }

  #dec(value, key, field) {
    return decrypt(this.config.encryptionKey, value, `${key}:${field}`);
  }

  async recoverInterrupted() {
    const { rowCount } = await this.pool.query(
      `UPDATE ${this.table}
       SET status = 'unknown', error_code = 'interrupted',
           error_message = 'Gateway berhenti saat mengirim; tidak dikirim ulang untuk mencegah pesan ganda.',
           updated_at = now()
       WHERE status = 'sending'`
    );
    if (rowCount) {
      this.logger.warn({ count: rowCount }, 'Pesan yang terputus saat dikirim ditandai unknown');
    }
  }

  async enqueue({ idempotencyKey, phone, message }) {
    const payloadHash = sha256Hex(`${phone}\n${message}`);
    const expiresAt = new Date(Date.now() + this.config.messageTtlHours * 3600 * 1000);

    const inserted = await this.pool.query(
      `INSERT INTO ${this.table}
         (idempotency_key, payload_hash, phone_enc, phone_hint, message_enc, expires_at)
       VALUES ($1, $2, $3, $4, $5, $6)
       ON CONFLICT (idempotency_key) DO NOTHING
       RETURNING *`,
      [
        idempotencyKey,
        payloadHash,
        this.#enc(phone, idempotencyKey, 'phone'),
        maskPhone(phone),
        this.#enc(message, idempotencyKey, 'message'),
        expiresAt,
      ]
    );

    if (inserted.rowCount) {
      this.logger.info({ key: idempotencyKey, to: maskPhone(phone) }, 'Pesan masuk antrean');
      setImmediate(this.kick);
      return { created: true, record: publicView(inserted.rows[0]) };
    }

    const existing = await this.pool.query(`SELECT * FROM ${this.table} WHERE idempotency_key = $1`, [idempotencyKey]);
    const row = existing.rows[0];
    if (!row) {
      throw new Error('Baris antrean tidak ditemukan setelah konflik idempotensi.');
    }
    if (row.payload_hash !== payloadHash) {
      throw new IdempotencyConflictError();
    }
    return { created: false, record: publicView(row) };
  }

  async find(keys) {
    const { rows } = await this.pool.query(`SELECT * FROM ${this.table} WHERE idempotency_key = ANY($1::text[])`, [keys]);
    return rows.map(publicView);
  }

  async counts() {
    const { rows } = await this.pool.query(
      `SELECT status, count(*)::int AS total FROM ${this.table}
       WHERE status IN ('queued', 'sending', 'unknown') GROUP BY status`
    );
    const result = { queued: 0, sending: 0, unknown: 0 };
    for (const row of rows) {
      result[row.status] = row.total;
    }
    return result;
  }

  start() {
    this.stopped = false;
    this.connection.on('receipt', this.onReceipt);
    this.connection.on('open', this.kick);
    this.timer = setInterval(this.kick, TICK_MS);
  }

  stop() {
    this.stopped = true;
    clearInterval(this.timer);
    this.timer = null;
    this.connection.off('receipt', this.onReceipt);
    this.connection.off('open', this.kick);
  }

  kick() {
    if (this.stopped || this.busy) {
      return;
    }
    this.busy = true;
    this.tick()
      .catch((err) => this.logger.error({ err: { message: err.message, code: err.code } }, 'Kesalahan pada pemroses antrean'))
      .finally(() => {
        this.busy = false;
      });
  }

  async tick() {
    const now = Date.now();
    if (now - this.lastMaintenanceAt >= MAINTENANCE_MS) {
      this.lastMaintenanceAt = now;
      await this.expire();
      if (now - this.lastPurgeAt >= PURGE_MS) {
        this.lastPurgeAt = now;
        await this.purge();
      }
    }

    if (this.connection.status !== STATUS.CONNECTED || now < this.nextSendAllowedAt) {
      return;
    }

    if ((await this.sentLastHour()) >= this.config.maxSendsPerHour) {
      if (now - this.rateLimitLoggedAt > 10 * 60 * 1000) {
        this.rateLimitLoggedAt = now;
        this.logger.warn({ limit: this.config.maxSendsPerHour }, 'Batas kirim per jam tercapai, antrean ditahan');
      }
      return;
    }

    const row = await this.claim();
    if (!row) {
      return;
    }
    await this.deliver(row);

    const { sendDelayMinMs: min, sendDelayMaxMs: max } = this.config;
    this.nextSendAllowedAt = Date.now() + Math.round(min + this.random() * (max - min));
  }

  async sentLastHour() {
    const { rows } = await this.pool.query(
      `SELECT count(*)::int AS total FROM ${this.table} WHERE sent_at > now() - interval '1 hour'`
    );
    return rows[0].total;
  }

  async claim() {
    const { rows } = await this.pool.query(
      `UPDATE ${this.table}
       SET status = 'sending', attempts = attempts + 1, updated_at = now()
       WHERE id = (
         SELECT id FROM ${this.table}
         WHERE status = 'queued' AND next_attempt_at <= now() AND expires_at > now()
         ORDER BY id
         FOR UPDATE SKIP LOCKED
         LIMIT 1
       )
       RETURNING *`
    );
    return rows[0] ?? null;
  }

  async deliver(row) {
    const log = this.logger.child({ key: row.idempotency_key, to: row.phone_hint, attempt: row.attempts });
    let phone;
    let message;
    try {
      phone = this.#dec(row.phone_enc, row.idempotency_key, 'phone');
      message = this.#dec(row.message_enc, row.idempotency_key, 'message');
    } catch {
      await this.#finish(row.id, 'failed', 'decrypt_failed', 'Data pesan tidak bisa didekripsi (kunci enkripsi berubah?).');
      log.error('Gagal mendekripsi pesan antrean');
      return;
    }

    try {
      const { id } = await this.connection.sendText(phone, message);
      await this.pool.query(
        `UPDATE ${this.table}
         SET status = 'sent', wa_message_id = $2, sent_at = now(), error_code = NULL, error_message = NULL, updated_at = now()
         WHERE id = $1`,
        [row.id, id]
      );
      log.info('Pesan diterima server WhatsApp');
    } catch (err) {
      const code = err.code ?? 'send_failed';
      const reason = err.message ?? 'Gagal mengirim pesan.';
      if (err.uncertain) {
        await this.#finish(row.id, 'unknown', code, reason);
        log.warn({ code }, 'Status pesan tidak pasti, tidak dikirim ulang');
      } else if (err.retryable && row.attempts < this.config.maxSendAttempts) {
        await this.pool.query(
          `UPDATE ${this.table}
           SET status = 'queued', next_attempt_at = now() + ($2::int * interval '1 minute'),
               error_code = $3, error_message = $4, updated_at = now()
           WHERE id = $1`,
          [row.id, row.attempts, code, reason]
        );
        log.warn({ code }, 'Pengiriman gagal sementara, dijadwalkan ulang');
      } else {
        await this.#finish(row.id, 'failed', code, reason);
        log.warn({ code }, 'Pengiriman gagal');
      }
    }
  }

  async #finish(id, status, code, message) {
    await this.pool.query(
      `UPDATE ${this.table} SET status = $2, error_code = $3, error_message = $4, updated_at = now() WHERE id = $1`,
      [id, status, code, message]
    );
  }

  async onReceipt({ id, status }) {
    try {
      if (status === 0) {
        await this.pool.query(
          `UPDATE ${this.table}
           SET status = 'failed', error_code = 'rejected_by_server', error_message = 'WhatsApp menandai pesan gagal.', updated_at = now()
           WHERE wa_message_id = $1 AND status = 'sent'`,
          [id]
        );
        return;
      }
      const next = receiptToStatus(status);
      if (!next) {
        return;
      }
      const lowerStatuses = ['sent', 'delivered'].filter((current) => isStatusUpgrade(current, next));
      await this.pool.query(
        `UPDATE ${this.table}
         SET status = $2, delivered_at = COALESCE(delivered_at, now()), updated_at = now()
         WHERE wa_message_id = $1 AND status = ANY($3::text[])`,
        [id, next, lowerStatuses]
      );
    } catch (err) {
      this.logger.error({ err: { message: err.message } }, 'Gagal mencatat tanda terima pesan');
    }
  }

  async expire() {
    const { rowCount } = await this.pool.query(
      `UPDATE ${this.table}
       SET status = 'expired', error_code = 'expired',
           error_message = 'Tidak terkirim dalam batas waktu antrean.', updated_at = now()
       WHERE status = 'queued' AND expires_at <= now()`
    );
    if (rowCount) {
      this.logger.warn({ count: rowCount }, 'Pesan kedaluwarsa di antrean');
    }
  }

  async purge() {
    await this.pool.query(
      `DELETE FROM ${this.table} WHERE created_at < now() - ($1::int * interval '1 day')`,
      [this.config.retentionDays]
    );
  }
}
