import { BufferJSON, initAuthCreds, proto } from '@whiskeysockets/baileys';
import { decrypt, encrypt } from './crypto.js';
import { quoteIdent } from './db.js';

/**
 * Penyimpanan sesi Baileys di Postgres, terenkripsi AES-256-GCM per baris.
 * Render Free tidak punya disk persisten, jadi useMultiFileAuthState bawaan
 * akan kehilangan sesi setiap kali layanan tidur/restart/redeploy.
 *
 * Baca dibatch (satu query per pemanggilan keys.get) dan tulis dalam satu
 * transaksi, supaya sesi tidak setengah tersimpan bila proses mati di tengah.
 */
export async function createPostgresAuthState({ pool, schema, encryptionKey }) {
  const table = `${quoteIdent(schema)}.auth_state`;

  const serialize = (id, value) => encrypt(encryptionKey, JSON.stringify(value, BufferJSON.replacer), id);
  const deserialize = (id, data) => {
    try {
      return JSON.parse(decrypt(encryptionKey, data, id), BufferJSON.reviver);
    } catch {
      throw new SessionDecryptError();
    }
  };

  async function readMany(ids) {
    if (!ids.length) {
      return new Map();
    }
    const { rows } = await pool.query(`SELECT id, data FROM ${table} WHERE id = ANY($1::text[])`, [ids]);
    return new Map(rows.map((row) => [row.id, deserialize(row.id, row.data)]));
  }

  async function writeMany(upserts, deletes) {
    if (!upserts.length && !deletes.length) {
      return;
    }
    const client = await pool.connect();
    try {
      await client.query('BEGIN');
      if (upserts.length) {
        await client.query(
          `INSERT INTO ${table} (id, data, updated_at)
           SELECT * , now() FROM unnest($1::text[], $2::text[])
           ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, updated_at = now()`,
          [upserts.map(([id]) => id), upserts.map(([id, value]) => serialize(id, value))]
        );
      }
      if (deletes.length) {
        await client.query(`DELETE FROM ${table} WHERE id = ANY($1::text[])`, [deletes]);
      }
      await client.query('COMMIT');
    } catch (err) {
      await client.query('ROLLBACK').catch(() => {});
      throw err;
    } finally {
      client.release();
    }
  }

  const stored = await readMany(['creds']);
  const creds = stored.get('creds') || initAuthCreds();

  const keys = {
    get: async (type, ids) => {
      const found = await readMany(ids.map((id) => `${type}-${id}`));
      const result = {};
      for (const id of ids) {
        let value = found.get(`${type}-${id}`) ?? null;
        if (type === 'app-state-sync-key' && value) {
          value = proto.Message.AppStateSyncKeyData.fromObject(value);
        }
        result[id] = value;
      }
      return result;
    },
    set: async (data) => {
      // Map: kunci yang muncul dua kali dalam satu batch cukup ditulis sekali
      // (ON CONFLICT tidak boleh menyentuh baris yang sama dua kali).
      const latest = new Map();
      for (const category of Object.keys(data)) {
        for (const id of Object.keys(data[category])) {
          latest.set(`${category}-${id}`, data[category][id] ?? null);
        }
      }
      const upserts = [...latest].filter(([, value]) => value);
      const deletes = [...latest].filter(([, value]) => !value).map(([key]) => key);
      await writeMany(upserts, deletes);
    },
    clear: async () => {
      await pool.query(`DELETE FROM ${table} WHERE id <> 'creds'`);
    },
  };

  return {
    state: { creds, keys },
    saveCreds: () => writeMany([['creds', creds]], []),
  };
}

export class SessionDecryptError extends Error {
  constructor() {
    super('Sesi tersimpan tidak bisa didekripsi (SESSION_ENCRYPTION_KEY berubah atau data rusak).');
    this.code = 'session_decrypt_failed';
  }
}

export async function clearAuthState(pool, schema) {
  await pool.query(`DELETE FROM ${quoteIdent(schema)}.auth_state`);
}

// Sesi dianggap ada hanya bila pairing benar-benar selesai: requestPairingCode
// mengisi creds.me lebih dulu, sedangkan creds.account baru diisi setelah
// WhatsApp mengonfirmasi pairing.
export function hasCompletedPairing(creds) {
  return Boolean(creds?.me?.id && creds?.account);
}
