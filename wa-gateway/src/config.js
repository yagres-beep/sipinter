import { createHmac } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const MIN_TOKEN_LENGTH = 32;
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT_DIR = path.resolve(__dirname, '../..');

function muatDotEnvLokal(env) {
  const hasil = { ...env };
  const kandidat = [path.join(ROOT_DIR, '.env'), path.resolve(__dirname, '../.env')];
  for (const file of kandidat) {
    if (!fs.existsSync(file)) {
      continue;
    }
    try {
      const isi = fs.readFileSync(file, 'utf8');
      for (const baris of isi.split(/\r?\n/)) {
        const bersih = baris.trim();
        if (!bersih || bersih.startsWith('#')) {
          continue;
        }
        const idx = bersih.indexOf('=');
        if (idx <= 0) {
          continue;
        }
        const kunci = bersih.slice(0, idx).trim();
        let nilai = bersih.slice(idx + 1).trim();
        if ((nilai.startsWith('"') && nilai.endsWith('"')) || (nilai.startsWith("'") && nilai.endsWith("'"))) {
          nilai = nilai.slice(1, -1);
        }
        if (hasil[kunci] === undefined || hasil[kunci] === '') {
          hasil[kunci] = nilai;
        }
      }
    } catch {
      // abaikan bila gagal baca .env
    }
  }
  return hasil;
}

function angka(env, nama, bawaan, { min = 0, max = Number.MAX_SAFE_INTEGER } = {}) {
  const mentah = env[nama];
  if (mentah === undefined || mentah === '') {
    return bawaan;
  }
  const nilai = Number(mentah);
  if (!Number.isInteger(nilai) || nilai < min || nilai > max) {
    throw new Error(`Environment variable ${nama} harus bilangan bulat ${min}..${max}.`);
  }
  return nilai;
}

function bangunDatabaseUrlDariLaravel(env) {
  const urlLangsung = (env.DATABASE_URL || env.DB_URL || '').trim();
  if (urlLangsung) {
    return sesuaikanPortPooler(urlLangsung);
  }
  const conn = (env.DB_CONNECTION || '').trim().toLowerCase();
  const host = (env.DB_HOST || '').trim();
  if (conn === 'pgsql' && host) {
    const port = (env.DB_PORT || '5432').trim();
    const db = (env.DB_DATABASE || 'postgres').trim();
    const user = encodeURIComponent((env.DB_USERNAME || 'postgres').trim());
    const pass = encodeURIComponent(env.DB_PASSWORD ?? '');
    return sesuaikanPortPooler(`postgresql://${user}:${pass}@${host}:${port}/${encodeURIComponent(db)}`);
  }
  return null;
}

function sesuaikanPortPooler(databaseUrl) {
  try {
    const parsed = new URL(databaseUrl);
    if (parsed.port === '6543' && parsed.hostname.endsWith('.pooler.supabase.com')) {
      parsed.port = '5432';
      return parsed.toString();
    }
  } catch {
    // biarkan divalidasi di pemanggil
  }
  return databaseUrl;
}

export function loadConfig(rawEnv = process.env) {
  const env = muatDotEnvLokal(rawEnv);
  const appKey = String(env.APP_KEY || 'sipinter-wa-gateway-default-key').trim();

  const apiToken =
    String(env.GATEWAY_API_TOKEN || env.WHATSAPP_GATEWAY_TOKEN || '').trim() ||
    createHmac('sha256', appKey).update('sipinter-wa-gateway-token').digest('hex');

  if (apiToken.length < MIN_TOKEN_LENGTH) {
    throw new Error(`GATEWAY_API_TOKEN minimal ${MIN_TOKEN_LENGTH} karakter.`);
  }

  const databaseUrl = bangunDatabaseUrlDariLaravel(env);
  if (databaseUrl) {
    try {
      new URL(databaseUrl);
    } catch {
      throw new Error('DATABASE_URL bukan URL Postgres yang valid.');
    }
  }

  let encryptionKey;
  if (env.SESSION_ENCRYPTION_KEY && String(env.SESSION_ENCRYPTION_KEY).trim()) {
    encryptionKey = Buffer.from(String(env.SESSION_ENCRYPTION_KEY).trim(), 'base64');
    if (encryptionKey.length !== 32) {
      throw new Error('SESSION_ENCRYPTION_KEY harus 32 byte yang di-encode base64 (contoh: openssl rand -base64 32).');
    }
  } else {
    encryptionKey = createHmac('sha256', appKey).update('sipinter-wa-gateway-session-key').digest();
  }

  const schema = env.DB_SCHEMA?.trim() || 'public';
  if (!/^[a-z_][a-z0-9_]{0,62}$/.test(schema)) {
    throw new Error('DB_SCHEMA hanya boleh huruf kecil, angka, dan garis bawah.');
  }

  const sendDelayMinMs = angka(env, 'SEND_DELAY_MIN_MS', 3000, { min: 500, max: 120000 });
  const sendDelayMaxMs = angka(env, 'SEND_DELAY_MAX_MS', 8000, { min: sendDelayMinMs, max: 300000 });

  const rawLevel = (env.WA_GATEWAY_LOG_LEVEL?.trim() || env.LOG_LEVEL?.trim() || 'info').toLowerCase();
  const petaLevel = { warning: 'warn', notice: 'info', critical: 'fatal', alert: 'fatal', emergency: 'fatal' };
  const kandidatLevel = petaLevel[rawLevel] || rawLevel;
  const logLevel = ['fatal', 'error', 'warn', 'info', 'debug', 'trace', 'silent'].includes(kandidatLevel) ? kandidatLevel : 'info';

  return Object.freeze({
    host: env.WA_GATEWAY_HOST?.trim() || '127.0.0.1',
    port: angka(env, 'WA_GATEWAY_PORT', 3001, { min: 1, max: 65535 }),
    apiToken,
    databaseUrl,
    localStorePath: env.WA_GATEWAY_STORE_PATH?.trim() || path.join(ROOT_DIR, 'storage/app/private/wa-gateway/store.json'),
    databaseCaCert: env.DATABASE_CA_CERT?.trim() || null,
    encryptionKey,
    schema,
    logLevel,
    sendDelayMinMs,
    sendDelayMaxMs,
    maxSendsPerHour: angka(env, 'MAX_SENDS_PER_HOUR', 60, { min: 1, max: 1000 }),
    maxSendAttempts: angka(env, 'MAX_SEND_ATTEMPTS', 3, { min: 1, max: 10 }),
    messageTtlHours: angka(env, 'MESSAGE_TTL_HOURS', 24, { min: 1, max: 168 }),
    retentionDays: angka(env, 'OUTBOX_RETENTION_DAYS', 30, { min: 1, max: 365 }),
    maxReconnectAttempts: angka(env, 'MAX_RECONNECT_ATTEMPTS', 8, { min: 1, max: 50 }),
  });
}
