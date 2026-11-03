import pino from 'pino';

const REDACT_PATHS = [
  'req.headers.authorization',
  'headers.authorization',
  'authorization',
  'token',
  'apiToken',
  'qr',
  'phone',
  'message',
  'creds',
  'keys',
  'databaseUrl',
  'encryptionKey',
  '*.qr',
  '*.phone',
  '*.message',
  '*.creds',
  '*.keys',
];

export function createLogger(level = 'info') {
  return pino({
    level,
    base: { service: 'sipinter-wa-gateway' },
    redact: { paths: REDACT_PATHS, censor: '[disembunyikan]' },
    timestamp: pino.stdTimeFunctions.isoTime,
  });
}

// Logger untuk internal Baileys: hanya warn ke atas, karena level info/debug
// Baileys mencetak isi node protokol (termasuk JID lengkap dan kunci sesi).
export function createBaileysLogger(parent) {
  const child = parent.child({ module: 'baileys' });
  child.level = parent.levelVal > 40 ? parent.level : 'warn';
  return child;
}

export function maskPhone(phone) {
  const digits = String(phone ?? '').replace(/\D/g, '');
  if (digits.length < 7) {
    return '***';
  }
  return `${digits.slice(0, 4)}****${digits.slice(-3)}`;
}
