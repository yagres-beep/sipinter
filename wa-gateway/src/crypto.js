import { createCipheriv, createDecipheriv, createHash, randomBytes, timingSafeEqual } from 'node:crypto';

const VERSION = 'v1';

// AES-256-GCM. "context" (mis. id baris) ikut diautentikasi sebagai AAD supaya
// ciphertext satu baris tidak bisa dipindah ke baris lain tanpa ketahuan.
export function encrypt(key, plaintext, context) {
  const iv = randomBytes(12);
  const cipher = createCipheriv('aes-256-gcm', key, iv);
  cipher.setAAD(Buffer.from(String(context), 'utf8'));
  const ciphertext = Buffer.concat([cipher.update(String(plaintext), 'utf8'), cipher.final()]);
  const tag = cipher.getAuthTag();
  return [VERSION, iv.toString('base64'), tag.toString('base64'), ciphertext.toString('base64')].join(':');
}

export function decrypt(key, payload, context) {
  const parts = String(payload).split(':');
  if (parts.length !== 4 || parts[0] !== VERSION) {
    throw new Error('Format data terenkripsi tidak dikenali.');
  }
  const [, ivB64, tagB64, ctB64] = parts;
  const decipher = createDecipheriv('aes-256-gcm', key, Buffer.from(ivB64, 'base64'));
  decipher.setAAD(Buffer.from(String(context), 'utf8'));
  decipher.setAuthTag(Buffer.from(tagB64, 'base64'));
  return Buffer.concat([decipher.update(Buffer.from(ctB64, 'base64')), decipher.final()]).toString('utf8');
}

export function sha256Hex(value) {
  return createHash('sha256').update(String(value), 'utf8').digest('hex');
}

export function safeEqual(a, b) {
  const ha = createHash('sha256').update(String(a), 'utf8').digest();
  const hb = createHash('sha256').update(String(b), 'utf8').digest();
  return timingSafeEqual(ha, hb);
}
