import express from 'express';
import QRCode from 'qrcode';
import { GatewayError } from './connection.js';
import { safeEqual } from './crypto.js';
import { IdempotencyConflictError } from './outbox.js';
import { STATUS, validateKeyList, validatePhone, validateSendPayload } from './policy.js';

const RATE_WINDOW_MS = 60000;

export function createRateLimiter({ limit, windowMs = RATE_WINDOW_MS, now = Date.now }) {
  const hits = new Map();
  const sweep = setInterval(() => {
    const t = now();
    for (const [key, entry] of hits) {
      if (entry.resetAt <= t) {
        hits.delete(key);
      }
    }
  }, windowMs);
  sweep.unref();

  return function hit(key) {
    const t = now();
    let entry = hits.get(key);
    if (!entry || entry.resetAt <= t) {
      entry = { count: 0, resetAt: t + windowMs };
      hits.set(key, entry);
    }
    entry.count += 1;
    return { allowed: entry.count <= limit, retryAfterSec: Math.ceil((entry.resetAt - t) / 1000) };
  };
}

export function createApp({ config, logger, connection, outbox, lock, startedAt = new Date() }) {
  const app = express();
  const log = logger.child({ module: 'http' });
  const requestLimiter = createRateLimiter({ limit: 120 });
  const authFailLimiter = createRateLimiter({ limit: 10 });
  const sendLimiter = createRateLimiter({ limit: 60 });

  app.disable('x-powered-by');
  app.set('trust proxy', 1);
  app.use((req, res, next) => {
    res.set('Cache-Control', 'no-store');
    res.set('X-Content-Type-Options', 'nosniff');
    res.set('Referrer-Policy', 'no-referrer');
    next();
  });

  app.get('/', (req, res) => {
    res.json({ service: 'sipinter-wa-gateway', ok: true });
  });

  app.get('/health', (req, res) => {
    res.json({ status: 'ok', uptime_seconds: Math.round(process.uptime()) });
  });

  function rateLimit(limiter) {
    return (req, res, next) => {
      const { allowed, retryAfterSec } = limiter(req.ip);
      if (!allowed) {
        res.set('Retry-After', String(retryAfterSec));
        return res.status(429).json({ error: 'rate_limited', message: 'Terlalu banyak permintaan. Coba lagi nanti.' });
      }
      next();
    };
  }

  function requireToken(req, res, next) {
    const header = req.get('authorization') || '';
    const token = header.startsWith('Bearer ') ? header.slice(7).trim() : '';
    if (token && safeEqual(token, config.apiToken)) {
      return next();
    }
    const { allowed, retryAfterSec } = authFailLimiter(req.ip);
    log.warn({ path: req.path, ip: req.ip }, 'Permintaan ditolak: token tidak valid');
    if (!allowed) {
      res.set('Retry-After', String(retryAfterSec));
      return res.status(429).json({ error: 'rate_limited', message: 'Terlalu banyak percobaan token salah.' });
    }
    return res.status(401).json({ error: 'unauthorized', message: 'Token tidak valid.' });
  }

  // Tindakan yang menyentuh sesi hanya boleh dilakukan proses pemegang lock.
  function requireLock(req, res, next) {
    if (!lock.held) {
      return res.status(503).json({ error: 'not_ready', message: 'Gateway masih menunggu instance lain berhenti. Coba lagi sebentar.' });
    }
    next();
  }

  const secured = express.Router();
  secured.use(rateLimit(requestLimiter), requireToken, express.json({ limit: '16kb' }));

  secured.get('/status', async (req, res, next) => {
    try {
      const counts = await outbox.counts().catch(() => ({ queued: 0, sending: 0, unknown: 0 }));
      res.json({
        ...connection.snapshot(),
        lock_held: lock.held,
        outbox: counts,
        started_at: startedAt.toISOString(),
      });
    } catch (err) {
      next(err);
    }
  });

  secured.post('/connect', requireLock, async (req, res, next) => {
    try {
      res.json(await connection.connectNow());
    } catch (err) {
      next(err);
    }
  });

  secured.get('/qr', async (req, res, next) => {
    try {
      const current = connection.currentQr();
      if (!current) {
        return res.json({ status: connection.status, qr_data_url: null });
      }
      const qrDataUrl = await QRCode.toDataURL(current.qr, { margin: 1, width: 300, errorCorrectionLevel: 'M' });
      res.json({ status: connection.status, qr_data_url: qrDataUrl, qr_age_seconds: Math.round(current.ageMs / 1000) });
    } catch (err) {
      next(err);
    }
  });

  secured.post('/pairing-code', requireLock, async (req, res, next) => {
    try {
      const phone = validatePhone(req.body?.phone);
      if (!phone) {
        return res.status(422).json({ error: 'validation_failed', errors: { phone: 'Nomor kantor harus format 62xxxxxxxxxx.' } });
      }
      const code = await connection.requestPairingCode(phone);
      res.json({ status: connection.status, pairing_code: code });
    } catch (err) {
      next(err);
    }
  });

  secured.post('/logout', requireLock, async (req, res, next) => {
    try {
      await connection.logout();
      res.json(connection.snapshot());
    } catch (err) {
      next(err);
    }
  });

  secured.post('/send-message', rateLimit(sendLimiter), async (req, res, next) => {
    try {
      const validation = validateSendPayload(req.body);
      if (!validation.ok) {
        return res.status(422).json({ error: 'validation_failed', errors: validation.errors });
      }
      const { created, record } = await outbox.enqueue(validation.value);
      res.status(created ? 202 : 200).json({
        ...record,
        created,
        whatsapp_connected: connection.status === STATUS.CONNECTED,
      });
    } catch (err) {
      next(err);
    }
  });

  secured.post('/messages/lookup', async (req, res, next) => {
    try {
      const keys = validateKeyList(req.body?.keys);
      if (!keys) {
        return res.status(422).json({ error: 'validation_failed', errors: { keys: 'Wajib berisi 1-100 idempotency_key yang valid.' } });
      }
      res.json({ messages: await outbox.find(keys) });
    } catch (err) {
      next(err);
    }
  });

  app.use(secured);

  app.use((req, res) => {
    res.status(404).json({ error: 'not_found' });
  });

  // eslint-disable-next-line no-unused-vars
  app.use((err, req, res, next) => {
    if (err instanceof IdempotencyConflictError) {
      return res.status(409).json({ error: err.code, message: err.message });
    }
    if (err instanceof GatewayError) {
      return res.status(err.retryable ? 503 : 409).json({ error: err.code, message: err.message });
    }
    if (err?.type === 'entity.too.large') {
      return res.status(413).json({ error: 'payload_too_large', message: 'Ukuran permintaan melebihi 16 KB.' });
    }
    if (err?.type === 'entity.parse.failed') {
      return res.status(400).json({ error: 'invalid_json', message: 'Body permintaan bukan JSON yang valid.' });
    }
    log.error({ err: { message: err?.message, code: err?.code }, path: req.path }, 'Kesalahan tak terduga');
    return res.status(500).json({ error: 'internal_error', message: 'Terjadi kesalahan di gateway.' });
  });

  return app;
}
