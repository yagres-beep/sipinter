import fs from 'node:fs';
import path from 'node:path';
import pg from 'pg';

const { Pool } = pg;

/**
 * Penyimpanan lokal berbasis berkas JSON (fallback otomatis bila dijalankan di
 * lingkungan pengembangan lokal dengan SQLite / tanpa Postgres). Di produksi
 * Render (Supabase Postgres), tetap memakai pg.Pool agar sesi bertahan lintas
 * deploy dan restart container.
 */
class LocalFilePool {
  constructor(filePath, logger) {
    this.filePath = filePath;
    this.logger = logger;
    this.state = { auth_state: {}, outbox: [], seq: 0 };
    this.#load();
  }

  #load() {
    try {
      if (fs.existsSync(this.filePath)) {
        const parsed = JSON.parse(fs.readFileSync(this.filePath, 'utf8'));
        if (parsed && typeof parsed === 'object') {
          this.state.auth_state = parsed.auth_state || {};
          this.state.outbox = Array.isArray(parsed.outbox) ? parsed.outbox : [];
          this.state.seq = Number(parsed.seq) || this.state.outbox.length;
        }
      }
    } catch (err) {
      this.logger?.warn({ err: { message: err.message } }, 'Gagal memuat store lokal, memulai baru');
    }
  }

  #save() {
    try {
      fs.mkdirSync(path.dirname(this.filePath), { recursive: true });
      const tmp = `${this.filePath}.tmp`;
      fs.writeFileSync(tmp, JSON.stringify(this.state), 'utf8');
      fs.renameSync(tmp, this.filePath);
    } catch (err) {
      this.logger?.error({ err: { message: err.message } }, 'Gagal menyimpan store lokal');
    }
  }

  on() {}

  async connect() {
    return {
      query: (sql, params) => this.query(sql, params),
      release: () => {},
      on: () => {},
    };
  }

  async end() {}

  async query(sql, params = []) {
    const q = sql.replace(/\s+/g, ' ').trim();

    if (/^(BEGIN|COMMIT|ROLLBACK|CREATE SCHEMA|CREATE TABLE|CREATE INDEX)/i.test(q)) {
      return { rows: [], rowCount: 0 };
    }
    if (q.includes('pg_namespace')) {
      return { rows: [{ '?column?': 1 }], rowCount: 1 };
    }
    if (q.includes('pg_try_advisory_lock') || q.includes('pg_advisory_unlock')) {
      return { rows: [{ ok: true }], rowCount: 1 };
    }

    // auth_state queries
    if (q.includes('.auth_state')) {
      if (/^SELECT id, data FROM/i.test(q)) {
        const ids = params[0] || [];
        const rows = ids
          .filter((id) => Object.prototype.hasOwnProperty.call(this.state.auth_state, id))
          .map((id) => ({ id, data: this.state.auth_state[id] }));
        return { rows, rowCount: rows.length };
      }
      if (/^INSERT INTO/i.test(q)) {
        const ids = params[0] || [];
        const datas = params[1] || [];
        for (let i = 0; i < ids.length; i += 1) {
          this.state.auth_state[ids[i]] = datas[i];
        }
        this.#save();
        return { rows: [], rowCount: ids.length };
      }
      if (/^DELETE FROM .* WHERE id = ANY/i.test(q)) {
        const ids = params[0] || [];
        let deleted = 0;
        for (const id of ids) {
          if (Object.prototype.hasOwnProperty.call(this.state.auth_state, id)) {
            delete this.state.auth_state[id];
            deleted += 1;
          }
        }
        this.#save();
        return { rows: [], rowCount: deleted };
      }
      if (/^DELETE FROM .* WHERE id <> 'creds'/i.test(q)) {
        const creds = this.state.auth_state.creds;
        this.state.auth_state = creds ? { creds } : {};
        this.#save();
        return { rows: [], rowCount: 1 };
      }
      if (/^DELETE FROM/i.test(q)) {
        this.state.auth_state = {};
        this.#save();
        return { rows: [], rowCount: 1 };
      }
    }

    // outbox queries
    const nowIso = new Date().toISOString();
    const nowMs = Date.now();

    if (/WHERE status = 'sending'/i.test(q) && /^UPDATE/i.test(q)) {
      let count = 0;
      for (const row of this.state.outbox) {
        if (row.status === 'sending') {
          row.status = 'unknown';
          row.error_code = 'interrupted';
          row.error_message = 'Gateway berhenti saat mengirim; tidak dikirim ulang untuk mencegah pesan ganda.';
          row.updated_at = nowIso;
          count += 1;
        }
      }
      if (count) this.#save();
      return { rows: [], rowCount: count };
    }

    if (/^INSERT INTO .*\.outbox/i.test(q)) {
      const [idempotency_key, payload_hash, phone_enc, phone_hint, message_enc, expires_at] = params;
      if (this.state.outbox.some((r) => r.idempotency_key === idempotency_key)) {
        return { rows: [], rowCount: 0 };
      }
      this.state.seq += 1;
      const row = {
        id: this.state.seq,
        idempotency_key,
        payload_hash,
        phone_enc,
        phone_hint,
        message_enc,
        status: 'queued',
        attempts: 0,
        next_attempt_at: nowIso,
        wa_message_id: null,
        error_code: null,
        error_message: null,
        created_at: nowIso,
        updated_at: nowIso,
        sent_at: null,
        delivered_at: null,
        expires_at: expires_at instanceof Date ? expires_at.toISOString() : String(expires_at),
      };
      this.state.outbox.push(row);
      this.#save();
      return { rows: [row], rowCount: 1 };
    }

    if (/^SELECT \* FROM .*\.outbox WHERE idempotency_key = \$1/i.test(q)) {
      const rows = this.state.outbox.filter((r) => r.idempotency_key === params[0]);
      return { rows, rowCount: rows.length };
    }

    if (/^SELECT \* FROM .*\.outbox WHERE idempotency_key = ANY/i.test(q)) {
      const set = new Set(params[0] || []);
      const rows = this.state.outbox.filter((r) => set.has(r.idempotency_key));
      return { rows, rowCount: rows.length };
    }

    if (/SELECT status, count\(\*\)::int AS total/i.test(q)) {
      const counts = {};
      for (const r of this.state.outbox) {
        if (['queued', 'sending', 'unknown'].includes(r.status)) {
          counts[r.status] = (counts[r.status] || 0) + 1;
        }
      }
      const rows = Object.entries(counts).map(([status, total]) => ({ status, total }));
      return { rows, rowCount: rows.length };
    }

    if (/WHERE sent_at > now\(\) - interval '1 hour'/i.test(q)) {
      const batas = nowMs - 3600 * 1000;
      const total = this.state.outbox.filter((r) => r.sent_at && new Date(r.sent_at).getTime() > batas).length;
      return { rows: [{ total }], rowCount: 1 };
    }

    if (/FOR UPDATE SKIP LOCKED/i.test(q)) {
      const kandidat = this.state.outbox
        .filter(
          (r) =>
            r.status === 'queued' &&
            new Date(r.next_attempt_at).getTime() <= nowMs &&
            new Date(r.expires_at).getTime() > nowMs
        )
        .sort((a, b) => a.id - b.id)[0];
      if (!kandidat) {
        return { rows: [], rowCount: 0 };
      }
      kandidat.status = 'sending';
      kandidat.attempts += 1;
      kandidat.updated_at = nowIso;
      this.#save();
      return { rows: [kandidat], rowCount: 1 };
    }

    if (/SET status = 'sent', wa_message_id = \$2/i.test(q)) {
      const row = this.state.outbox.find((r) => r.id === params[0]);
      if (row) {
        row.status = 'sent';
        row.wa_message_id = params[1];
        row.sent_at = nowIso;
        row.error_code = null;
        row.error_message = null;
        row.updated_at = nowIso;
        this.#save();
      }
      return { rows: [], rowCount: row ? 1 : 0 };
    }

    if (/SET status = 'queued', next_attempt_at =/i.test(q)) {
      const row = this.state.outbox.find((r) => r.id === params[0]);
      if (row) {
        row.status = 'queued';
        row.next_attempt_at = new Date(nowMs + Number(params[1]) * 60000).toISOString();
        row.error_code = params[2];
        row.error_message = params[3];
        row.updated_at = nowIso;
        this.#save();
      }
      return { rows: [], rowCount: row ? 1 : 0 };
    }

    if (/SET status = \$2, error_code = \$3, error_message = \$4/i.test(q)) {
      const row = this.state.outbox.find((r) => r.id === params[0]);
      if (row) {
        row.status = params[1];
        row.error_code = params[2];
        row.error_message = params[3];
        row.updated_at = nowIso;
        this.#save();
      }
      return { rows: [], rowCount: row ? 1 : 0 };
    }

    if (/WHERE wa_message_id = \$1 AND status = 'sent'/i.test(q)) {
      const row = this.state.outbox.find((r) => r.wa_message_id === params[0] && r.status === 'sent');
      if (row) {
        row.status = 'failed';
        row.error_code = 'rejected_by_server';
        row.error_message = 'WhatsApp menandai pesan gagal.';
        row.updated_at = nowIso;
        this.#save();
      }
      return { rows: [], rowCount: row ? 1 : 0 };
    }

    if (/WHERE wa_message_id = \$1 AND status = ANY/i.test(q)) {
      const allowed = new Set(params[2] || []);
      const row = this.state.outbox.find((r) => r.wa_message_id === params[0] && allowed.has(r.status));
      if (row) {
        row.status = params[1];
        row.delivered_at = row.delivered_at || nowIso;
        row.updated_at = nowIso;
        this.#save();
      }
      return { rows: [], rowCount: row ? 1 : 0 };
    }

    if (/WHERE status = 'queued' AND expires_at <= now\(\)/i.test(q)) {
      let count = 0;
      for (const r of this.state.outbox) {
        if (r.status === 'queued' && new Date(r.expires_at).getTime() <= nowMs) {
          r.status = 'expired';
          r.error_code = 'expired';
          r.error_message = 'Tidak terkirim dalam batas waktu antrean.';
          r.updated_at = nowIso;
          count += 1;
        }
      }
      if (count) this.#save();
      return { rows: [], rowCount: count };
    }

    if (/^DELETE FROM .*\.outbox WHERE created_at </i.test(q)) {
      const cutoff = nowMs - Number(params[0]) * 86400 * 1000;
      const before = this.state.outbox.length;
      this.state.outbox = this.state.outbox.filter((r) => new Date(r.created_at).getTime() >= cutoff);
      const removed = before - this.state.outbox.length;
      if (removed) this.#save();
      return { rows: [], rowCount: removed };
    }

    return { rows: [], rowCount: 0 };
  }
}

export function createPool(config, logger) {
  if (!config.databaseUrl) {
    logger.info({ path: config.localStorePath }, 'DATABASE_URL tidak diatur: memakai penyimpanan berkas terenkripsi lokal');
    return new LocalFilePool(config.localStorePath, logger);
  }

  const ssl = config.databaseCaCert
    ? { ca: config.databaseCaCert.replace(/\\n/g, '\n'), rejectUnauthorized: true }
    : sslTanpaVerifikasi(config.databaseUrl, logger);

  const pool = new Pool({
    connectionString: config.databaseUrl,
    ssl,
    max: 4,
    idleTimeoutMillis: 30000,
    connectionTimeoutMillis: 15000,
    statement_timeout: 20000,
    application_name: 'sipinter-wa-gateway',
  });

  pool.on('error', (err) => {
    logger.error({ err: { message: err.message, code: err.code } }, 'Koneksi Postgres idle error');
  });

  return pool;
}

function sslTanpaVerifikasi(databaseUrl, logger) {
  const host = new URL(databaseUrl).hostname;
  if (host === 'localhost' || host === '127.0.0.1') {
    return false;
  }
  logger.warn('DATABASE_CA_CERT kosong: koneksi Postgres dienkripsi TLS tetapi sertifikat server tidak diverifikasi.');
  return { rejectUnauthorized: false };
}

export async function migrate(pool, schema) {
  const s = quoteIdent(schema);
  const existing = await pool.query('SELECT 1 FROM pg_namespace WHERE nspname = $1', [schema]);
  if (!existing.rowCount) {
    await pool.query(`CREATE SCHEMA IF NOT EXISTS ${s}`);
  }
  await pool.query(`
    CREATE TABLE IF NOT EXISTS ${s}.auth_state (
      id TEXT PRIMARY KEY,
      data TEXT NOT NULL,
      updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
    )
  `);
  await pool.query(`
    CREATE TABLE IF NOT EXISTS ${s}.outbox (
      id BIGSERIAL PRIMARY KEY,
      idempotency_key TEXT NOT NULL UNIQUE,
      payload_hash TEXT NOT NULL,
      phone_enc TEXT NOT NULL,
      phone_hint TEXT NOT NULL,
      message_enc TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'queued',
      attempts INTEGER NOT NULL DEFAULT 0,
      next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT now(),
      wa_message_id TEXT,
      error_code TEXT,
      error_message TEXT,
      created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
      updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
      sent_at TIMESTAMPTZ,
      delivered_at TIMESTAMPTZ,
      expires_at TIMESTAMPTZ NOT NULL
    )
  `);
  await pool.query(`CREATE INDEX IF NOT EXISTS outbox_status_next_idx ON ${s}.outbox (status, next_attempt_at)`);
  await pool.query(`CREATE INDEX IF NOT EXISTS outbox_wa_message_id_idx ON ${s}.outbox (wa_message_id)`);
  await pool.query(`CREATE INDEX IF NOT EXISTS outbox_sent_at_idx ON ${s}.outbox (sent_at)`);
}

export function quoteIdent(name) {
  return `"${String(name).replace(/"/g, '""')}"`;
}
