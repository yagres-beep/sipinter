import { BufferJSON, initAuthCreds, proto } from '@whiskeysockets/baileys';
import { decrypt, encrypt } from './crypto.js';
import { authStateTable } from './db.js';

/**
 * Penyimpanan sesi Baileys di Postgres (dengan fallback berkas lokal otomatis),
 * terenkripsi AES-256-GCM per baris.
 */
export async function createPostgresAuthState({ pool, schema, encryptionKey }) {
  const table = authStateTable(schema);

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
    if (upserts.length) {
      await pool.query(
        `INSERT INTO ${table} (id, data, updated_at)
         SELECT * , now() FROM unnest($1::text[], $2::text[])
         ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, updated_at = now()`,
        [upserts.map(([id]) => id), upserts.map(([id, value]) => serialize(id, value))]
      );
    }
    if (deletes.length) {
      await pool.query(`DELETE FROM ${table} WHERE id = ANY($1::text[])`, [deletes]);
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
  await pool.query(`DELETE FROM ${authStateTable(schema)}`);
}

export function hasCompletedPairing(creds) {
  return Boolean(creds?.me?.id && creds?.account);
}
