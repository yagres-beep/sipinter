import { initAuthCreds, BufferJSON, proto } from '@whiskeysockets/baileys';

/**
 * Adapter penyimpanan sesi Baileys ke Postgres, pengganti useMultiFileAuthState
 * bawaan yang berbasis file lokal. Dipakai supaya sesi WhatsApp (login sekali,
 * scan QR) TIDAK HILANG saat container di-restart/redeploy/sleep — kasus nyata
 * di Render free tier yang disknya tidak persisten.
 *
 * Menyimpan setiap potongan kredensial/kunci sebagai satu baris JSONB di tabel
 * wa_sessions, dengan serialisasi BufferJSON (bawaan Baileys) supaya nilai
 * Buffer/Uint8Array tidak rusak saat lewat JSON.
 */
export async function usePostgresAuthState(pool) {
  await pool.query(`
    CREATE TABLE IF NOT EXISTS wa_sessions (
      id TEXT PRIMARY KEY,
      data JSONB NOT NULL,
      updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
    )
  `);

  async function bacaData(id) {
    const { rows } = await pool.query('SELECT data FROM wa_sessions WHERE id = $1', [id]);

    if (!rows.length) {
      return null;
    }

    return JSON.parse(JSON.stringify(rows[0].data), BufferJSON.reviver);
  }

  async function tulisData(id, data) {
    const serial = JSON.parse(JSON.stringify(data, BufferJSON.replacer));

    await pool.query(
      `INSERT INTO wa_sessions (id, data, updated_at) VALUES ($1, $2, now())
       ON CONFLICT (id) DO UPDATE SET data = $2, updated_at = now()`,
      [id, serial]
    );
  }

  async function hapusData(id) {
    await pool.query('DELETE FROM wa_sessions WHERE id = $1', [id]);
  }

  const creds = (await bacaData('creds')) || initAuthCreds();

  return {
    state: {
      creds,
      keys: {
        get: async (type, ids) => {
          const data = {};

          await Promise.all(
            ids.map(async (id) => {
              let value = await bacaData(`${type}-${id}`);

              if (type === 'app-state-sync-key' && value) {
                value = proto.Message.AppStateSyncKeyData.fromObject(value);
              }

              data[id] = value;
            })
          );

          return data;
        },
        set: async (data) => {
          const tugas = [];

          for (const kategori in data) {
            for (const id in data[kategori]) {
              const value = data[kategori][id];
              const key = `${kategori}-${id}`;
              tugas.push(value ? tulisData(key, value) : hapusData(key));
            }
          }

          await Promise.all(tugas);
        },
      },
    },
    saveCreds: () => tulisData('creds', creds),
  };
}
