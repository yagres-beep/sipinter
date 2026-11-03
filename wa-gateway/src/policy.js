import { DisconnectReason } from '@whiskeysockets/baileys';

export const STATUS = Object.freeze({
  STARTING: 'starting',
  WAITING_LOCK: 'waiting_lock',
  NO_SESSION: 'no_session',
  CONNECTING: 'connecting',
  WAITING_QR: 'waiting_qr',
  CONNECTED: 'connected',
  RECONNECTING: 'reconnecting',
  LOGGED_OUT: 'logged_out',
  CONFLICT: 'conflict',
  FAILED: 'failed',
  STOPPED: 'stopped',
});

export const ACTION = Object.freeze({
  RECONNECT_NOW: 'reconnect_now',
  RECONNECT_BACKOFF: 'reconnect_backoff',
  CLEAR_SESSION: 'clear_session',
  QR_EXPIRED: 'qr_expired',
  CONFLICT: 'conflict',
  FORBIDDEN: 'forbidden',
});

/**
 * Menentukan tindakan setelah koneksi tertutup, berdasarkan kode alasan dari
 * Baileys. Kode 408 dipakai Baileys untuk "koneksi hilang" sekaligus "QR
 * habis tanpa dipindai", jadi status saat koneksi tertutup ikut menentukan.
 */
export function classifyDisconnect(statusCode, statusBeforeClose) {
  switch (statusCode) {
    case DisconnectReason.restartRequired:
      return ACTION.RECONNECT_NOW;
    case DisconnectReason.loggedOut:
    case DisconnectReason.multideviceMismatch:
      return ACTION.CLEAR_SESSION;
    case DisconnectReason.connectionReplaced:
      return ACTION.CONFLICT;
    case DisconnectReason.forbidden:
      return ACTION.FORBIDDEN;
    case DisconnectReason.timedOut:
      return statusBeforeClose === STATUS.WAITING_QR ? ACTION.QR_EXPIRED : ACTION.RECONNECT_BACKOFF;
    default:
      return ACTION.RECONNECT_BACKOFF;
  }
}

export function reconnectDelayMs(attempt, random = Math.random) {
  const base = Math.min(300000, 2000 * 2 ** Math.max(0, attempt - 1));
  const jitter = 0.8 + random() * 0.4;
  return Math.round(base * jitter);
}

export const SLOW_RETRY_MS = 10 * 60 * 1000;

const PHONE_RE = /^[1-9]\d{9,14}$/;
const KEY_RE = /^[A-Za-z0-9:._-]{8,191}$/;
export const MAX_MESSAGE_LENGTH = 4096;

export function validateSendPayload(body) {
  const errors = {};
  const idempotencyKey = typeof body?.idempotency_key === 'string' ? body.idempotency_key.trim() : '';
  const phone = typeof body?.phone === 'string' ? body.phone.trim() : '';
  const message = typeof body?.message === 'string' ? body.message.trim() : '';

  if (!KEY_RE.test(idempotencyKey)) {
    errors.idempotency_key = 'Wajib 8-191 karakter: huruf, angka, titik dua, titik, garis bawah, atau tanda hubung.';
  }
  if (!PHONE_RE.test(phone)) {
    errors.phone = 'Nomor harus format internasional tanpa tanda plus, 10-15 digit, contoh 6281234567890.';
  }
  if (!message) {
    errors.message = 'Isi pesan wajib diisi.';
  } else if (message.length > MAX_MESSAGE_LENGTH) {
    errors.message = `Isi pesan maksimal ${MAX_MESSAGE_LENGTH} karakter.`;
  }

  return Object.keys(errors).length
    ? { ok: false, errors }
    : { ok: true, value: { idempotencyKey, phone, message } };
}

export function validatePhone(value) {
  const phone = typeof value === 'string' ? value.trim() : '';
  return PHONE_RE.test(phone) ? phone : null;
}

export function validateKeyList(value) {
  if (!Array.isArray(value) || value.length === 0 || value.length > 100) {
    return null;
  }
  const keys = value.map((k) => (typeof k === 'string' ? k.trim() : ''));
  return keys.every((k) => KEY_RE.test(k)) ? [...new Set(keys)] : null;
}

const STATUS_RANK = { queued: 0, sending: 1, unknown: 2, sent: 3, delivered: 4, read: 5 };

// Tanda terima WhatsApp bisa datang tidak berurutan; status hanya boleh naik.
export function receiptToStatus(waStatus) {
  switch (waStatus) {
    case 3:
      return 'delivered';
    case 4:
    case 5:
      return 'read';
    default:
      return null;
  }
}

export function isStatusUpgrade(current, next) {
  return (STATUS_RANK[next] ?? -1) > (STATUS_RANK[current] ?? -1);
}
