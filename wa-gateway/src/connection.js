import { EventEmitter } from 'node:events';
import makeWASocket, {
  Browsers,
  DEFAULT_CONNECTION_CONFIG,
  fetchLatestWaWebVersion,
  makeCacheableSignalKeyStore,
} from '@whiskeysockets/baileys';
import { SessionDecryptError, clearAuthState, createPostgresAuthState, hasCompletedPairing } from './authStore.js';
import { createBaileysLogger, maskPhone } from './logger.js';
import { ACTION, SLOW_RETRY_MS, STATUS, classifyDisconnect, reconnectDelayMs } from './policy.js';

const SENT_CACHE_LIMIT = 500;

const DISCONNECT_REASON = {
  401: 'Perangkat dikeluarkan dari WhatsApp (logout). Perlu pindai QR ulang.',
  403: 'WhatsApp menolak akses akun ini (kemungkinan dibatasi/diblokir).',
  408: 'Koneksi ke WhatsApp hilang atau QR habis waktu.',
  411: 'Sesi tidak cocok dengan perangkat. Perlu pindai QR ulang.',
  428: 'Koneksi ke WhatsApp tertutup.',
  440: 'Sesi yang sama dipakai di tempat lain. Pastikan hanya satu gateway yang berjalan.',
  500: 'Sesi bermasalah di sisi WhatsApp.',
  503: 'Layanan WhatsApp sedang tidak tersedia.',
  515: 'WhatsApp meminta koneksi dimulai ulang (normal setelah pemindaian QR).',
};

export class GatewayError extends Error {
  constructor(code, message, { retryable = false, uncertain = false } = {}) {
    super(message);
    this.code = code;
    this.retryable = retryable;
    this.uncertain = uncertain;
  }
}

async function defaultVersionFetcher(logger) {
  try {
    const { version, error } = await fetchLatestWaWebVersion({ signal: AbortSignal.timeout(8000) });
    if (error) {
      logger.warn({ error: error.message }, 'Versi WA Web terbaru tidak terbaca, memakai versi bawaan Baileys');
    }
    return version;
  } catch (err) {
    logger.warn({ err: { message: err.message } }, 'Gagal mengambil versi WA Web, memakai versi bawaan Baileys');
    return DEFAULT_CONNECTION_CONFIG.version;
  }
}

function withTimeout(promise, ms, onTimeout) {
  let timer;
  return Promise.race([
    promise,
    new Promise((_, reject) => {
      timer = setTimeout(() => reject(onTimeout()), ms);
    }),
  ]).finally(() => clearTimeout(timer));
}

/**
 * Mengelola tepat satu socket Baileys untuk satu nomor kantor.
 *
 * Aturan yang mencegah masalah gateway lama:
 * - Setiap socket punya nomor generasi; event dari socket lama diabaikan, dan
 *   socket lama selalu ditutup (end) sebelum socket baru dibuat.
 * - connect() yang dipanggil bersamaan digabung menjadi satu proses.
 * - Sesi hanya dihapus bila WhatsApp menyatakan logout (401/411), tidak saat
 *   gangguan jaringan.
 * - QR hanya dibuat bila admin meminta (connectNow) dan sesi belum ada; bila QR
 *   habis tanpa dipindai, gateway kembali ke no_session, tidak berulang sendiri.
 */
export class WhatsAppConnection extends EventEmitter {
  constructor({ pool, config, logger, socketFactory = makeWASocket, versionFetcher }) {
    super();
    this.pool = pool;
    this.config = config;
    this.logger = logger.child({ module: 'connection' });
    this.baileysLogger = createBaileysLogger(logger);
    this.socketFactory = socketFactory;
    this.versionFetcher = versionFetcher ?? (() => defaultVersionFetcher(this.logger));

    this.status = STATUS.STARTING;
    this.sock = null;
    this.creds = null;
    this.generation = 0;
    this.connecting = null;
    this.retryTimer = null;
    this.nextRetryAt = null;
    this.attempts = 0;
    this.qr = null;
    this.qrAt = null;
    this.me = null;
    this.lastDisconnect = null;
    this.stopping = false;
    this.credsChain = Promise.resolve();
    this.sentCache = new Map();
  }

  snapshot() {
    return {
      status: this.status,
      connected: this.status === STATUS.CONNECTED,
      session_present: hasCompletedPairing(this.creds),
      me: this.me,
      qr_available: this.status === STATUS.WAITING_QR && Boolean(this.qr),
      reconnect_attempts: this.attempts,
      next_retry_at: this.nextRetryAt ? new Date(this.nextRetryAt).toISOString() : null,
      last_disconnect: this.lastDisconnect,
    };
  }

  currentQr() {
    if (this.status !== STATUS.WAITING_QR || !this.qr) {
      return null;
    }
    return { qr: this.qr, ageMs: Date.now() - this.qrAt };
  }

  setStatus(status) {
    if (this.status !== status) {
      this.logger.info({ from: this.status, to: status }, 'Status koneksi berubah');
      this.status = status;
      this.emit('status', status);
    }
  }

  async start() {
    this.stopping = false;
    this.attempts = 0;
    let auth;
    try {
      auth = await createPostgresAuthState(this.#authOptions());
    } catch (err) {
      if (err instanceof SessionDecryptError) {
        this.#markUndecryptable();
        return;
      }
      throw err;
    }
    this.creds = auth.state.creds;
    if (hasCompletedPairing(this.creds)) {
      this.#setMe(this.creds);
      await this.connect('restore');
    } else {
      await this.#discardIncompletePairing();
    }
  }

  async connectNow() {
    if (this.status === STATUS.CONNECTED) {
      return this.snapshot();
    }
    if (this.status !== STATUS.CONNECTING) {
      if (!hasCompletedPairing(this.creds) && this.creds?.me) {
        await this.#closeSocket();
        await this.#discardIncompletePairing();
      } else if (this.status === STATUS.WAITING_QR && this.qr) {
        return this.snapshot();
      }
      if (this.stopping) {
        throw new GatewayError('not_ready', 'Gateway belum memegang lock sesi atau sedang berhenti.', { retryable: true });
      }
      this.attempts = 0;
      await this.connect('manual');
    }
    if (this.status === STATUS.CONNECTING) {
      await new Promise((resolve) => {
        const timer = setTimeout(() => {
          this.off('status', onStatus);
          resolve();
        }, 8000);
        const onStatus = (st) => {
          if (st !== STATUS.CONNECTING) {
            clearTimeout(timer);
            this.off('status', onStatus);
            resolve();
          }
        };
        this.on('status', onStatus);
      });
    }
    return this.snapshot();
  }

  connect(reason) {
    if (this.stopping) {
      return Promise.resolve();
    }
    if (this.connecting) {
      return this.connecting;
    }
    this.connecting = this.#openSocket(reason)
      .catch((err) => {
        this.logger.error({ err: { message: err.message, code: err.code } }, 'Gagal membuka koneksi WhatsApp');
        if (err instanceof SessionDecryptError) {
          this.#markUndecryptable();
          return;
        }
        if (!this.stopping) {
          this.lastDisconnect = { code: null, reason: `Gagal menyiapkan koneksi: ${err.message}`, at: new Date().toISOString() };
          this.#scheduleReconnect();
        }
      })
      .finally(() => {
        this.connecting = null;
      });
    return this.connecting;
  }

  async #openSocket(reason) {
    clearTimeout(this.retryTimer);
    this.retryTimer = null;
    this.nextRetryAt = null;
    await this.#closeSocket();

    const generation = ++this.generation;
    this.setStatus(this.attempts > 0 ? STATUS.RECONNECTING : STATUS.CONNECTING);
    this.logger.info({ reason, attempt: this.attempts }, 'Membuka koneksi WhatsApp');

    // Setelah pairing, WhatsApp langsung meminta restart (515); kredensial hasil
    // pairing harus sudah tersimpan sebelum dibaca ulang dari database.
    await this.credsChain;
    const auth = await createPostgresAuthState(this.#authOptions());
    const version = await this.versionFetcher();
    if (generation !== this.generation || this.stopping) {
      return;
    }

    this.creds = auth.state.creds;
    const sock = this.socketFactory({
      version,
      auth: {
        creds: auth.state.creds,
        keys: makeCacheableSignalKeyStore(auth.state.keys, this.baileysLogger),
      },
      logger: this.baileysLogger,
      browser: Browsers.ubuntu('Chrome'),
      markOnlineOnConnect: false,
      // Riwayat penuh tidak diperlukan, tetapi shouldSyncHistoryMessage bawaan
      // tetap dipakai: Baileys v7 butuh sinkron awal untuk pemetaan LID.
      syncFullHistory: false,
      generateHighQualityLinkPreview: false,
      getMessage: async (key) => this.sentCache.get(key.id),
    });
    this.sock = sock;

    sock.ev.on('creds.update', () => {
      // Antrean berurutan: penulisan yang lebih lama tidak boleh menimpa yang baru.
      this.credsChain = this.credsChain
        .then(() => auth.saveCreds())
        .catch((err) => this.logger.error({ err: { message: err.message, code: err.code } }, 'Gagal menyimpan kredensial'));
    });
    sock.ev.on('connection.update', (update) => {
      this.#onConnectionUpdate(generation, update).catch((err) =>
        this.logger.error({ err: { message: err.message } }, 'Kesalahan saat memproses status koneksi')
      );
    });
    sock.ev.on('messages.update', (updates) => this.#onMessagesUpdate(generation, updates));
  }

  async #onConnectionUpdate(generation, { connection, lastDisconnect, qr }) {
    if (generation !== this.generation) {
      return;
    }
    if (qr) {
      this.qr = qr;
      this.qrAt = Date.now();
      this.setStatus(STATUS.WAITING_QR);
      this.emit('qr');
    }
    if (connection === 'open') {
      this.attempts = 0;
      this.qr = null;
      this.nextRetryAt = null;
      this.#setMe(this.creds);
      this.setStatus(STATUS.CONNECTED);
      this.emit('open');
    }
    if (connection === 'close') {
      await this.#onClose(lastDisconnect);
    }
  }

  async #onClose(lastDisconnect) {
    const statusBefore = this.status;
    const code = lastDisconnect?.error?.output?.statusCode ?? null;
    this.sock = null;
    this.qr = null;
    this.lastDisconnect = {
      code,
      reason: DISCONNECT_REASON[code] ?? 'Koneksi tertutup tanpa kode alasan yang dikenal.',
      at: new Date().toISOString(),
    };
    this.logger.warn({ code, statusBefore }, 'Koneksi WhatsApp tertutup');
    if (this.stopping) {
      return;
    }

    const action = classifyDisconnect(code, statusBefore);
    switch (action) {
      case ACTION.RECONNECT_NOW:
        await this.connect('restart_required');
        return;
      case ACTION.CLEAR_SESSION:
        await this.credsChain;
        await clearAuthState(this.pool, this.config.schema);
        this.creds = null;
        this.me = null;
        this.attempts = 0;
        this.setStatus(STATUS.LOGGED_OUT);
        this.emit('logged_out');
        return;
      case ACTION.CONFLICT:
        this.setStatus(STATUS.CONFLICT);
        return;
      case ACTION.FORBIDDEN:
        this.setStatus(STATUS.FAILED);
        return;
      case ACTION.QR_EXPIRED:
        await this.#discardIncompletePairing();
        return;
      default:
        if (!hasCompletedPairing(this.creds)) {
          // Belum pernah terhubung: jangan memulai pairing (QR baru) tanpa diminta admin.
          await this.#discardIncompletePairing();
          return;
        }
        this.#scheduleReconnect();
    }
  }

  // Pairing yang batal bisa meninggalkan creds.me (dari requestPairingCode);
  // Baileys lalu mencoba login, bukan registrasi, dan gagal. Mulai dari bersih.
  async #discardIncompletePairing() {
    await this.credsChain;
    await clearAuthState(this.pool, this.config.schema);
    this.creds = null;
    this.setStatus(STATUS.NO_SESSION);
  }

  #scheduleReconnect() {
    this.attempts += 1;
    const fast = this.attempts <= this.config.maxReconnectAttempts;
    const delay = fast ? reconnectDelayMs(this.attempts) : SLOW_RETRY_MS;
    this.setStatus(fast ? STATUS.RECONNECTING : STATUS.FAILED);
    this.nextRetryAt = Date.now() + delay;
    this.logger.info({ attempt: this.attempts, delayMs: delay }, 'Menjadwalkan sambung ulang');
    clearTimeout(this.retryTimer);
    this.retryTimer = setTimeout(() => {
      this.retryTimer = null;
      this.connect('backoff');
    }, delay);
  }

  #onMessagesUpdate(generation, updates) {
    if (generation !== this.generation) {
      return;
    }
    for (const { key, update } of updates) {
      if (key?.fromMe && key.id && typeof update?.status === 'number') {
        this.emit('receipt', { id: key.id, status: update.status });
      }
    }
  }

  async requestPairingCode(phone) {
    if (hasCompletedPairing(this.creds)) {
      throw new GatewayError('already_paired', 'Sesi WhatsApp sudah ada. Putuskan koneksi dulu bila ingin mengganti nomor.');
    }
    if (this.creds?.me) {
      await this.#closeSocket();
      await this.#discardIncompletePairing();
    }
    if (this.status !== STATUS.WAITING_QR || !this.sock) {
      const ready = new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
          this.off('qr', onQr);
          reject(new GatewayError('pairing_not_ready', 'WhatsApp belum siap menerima permintaan kode pairing. Coba lagi.', { retryable: true }));
        }, 30000);
        const onQr = () => {
          clearTimeout(timer);
          resolve();
        };
        this.once('qr', onQr);
      });
      await this.connectNow();
      await ready;
    }
    const code = await this.sock.requestPairingCode(phone);
    return code.length === 8 ? `${code.slice(0, 4)}-${code.slice(4)}` : code;
  }

  async logout() {
    const sock = this.sock;
    clearTimeout(this.retryTimer);
    this.retryTimer = null;
    this.nextRetryAt = null;
    this.generation += 1;
    this.sock = null;
    if (sock) {
      try {
        if (this.status === STATUS.CONNECTED) {
          await withTimeout(sock.logout(), 10000, () => new Error('logout timeout'));
        }
      } catch (err) {
        this.logger.warn({ err: { message: err.message } }, 'Logout ke WhatsApp gagal, sesi lokal tetap dihapus');
      }
      await Promise.resolve(sock.end(undefined)).catch(() => {});
    }
    await this.credsChain;
    await clearAuthState(this.pool, this.config.schema);
    this.creds = null;
    this.me = null;
    this.qr = null;
    this.attempts = 0;
    this.lastDisconnect = { code: 401, reason: 'Koneksi diputus oleh admin SIPINTER.', at: new Date().toISOString() };
    this.setStatus(STATUS.LOGGED_OUT);
  }

  async stop(nextStatus = STATUS.STOPPED) {
    this.stopping = true;
    clearTimeout(this.retryTimer);
    this.retryTimer = null;
    this.nextRetryAt = null;
    await this.#closeSocket();
    await this.credsChain;
    this.setStatus(nextStatus);
  }

  async sendText(phone, text) {
    const sock = this.sock;
    if (this.status !== STATUS.CONNECTED || !sock) {
      throw new GatewayError('not_connected', 'WhatsApp belum terhubung.', { retryable: true });
    }

    let lookup;
    try {
      lookup = await withTimeout(sock.onWhatsApp(phone), 15000, () => new GatewayError('lookup_timeout', 'Pemeriksaan nomor ke WhatsApp habis waktu.', { retryable: true }));
    } catch (err) {
      throw err instanceof GatewayError ? err : new GatewayError('lookup_failed', 'Gagal memeriksa nomor ke WhatsApp.', { retryable: true });
    }
    const target = lookup?.[0];
    if (!target?.exists || !target.jid) {
      throw new GatewayError('not_on_whatsapp', 'Nomor tujuan tidak terdaftar di WhatsApp.');
    }

    let sent;
    try {
      sent = await withTimeout(
        sock.sendMessage(target.jid, { text }),
        30000,
        () => new GatewayError('send_timeout', 'Pengiriman habis waktu; status pesan tidak pasti.', { uncertain: true })
      );
    } catch (err) {
      if (err instanceof GatewayError) {
        throw err;
      }
      const code = err?.output?.statusCode;
      // 428/408: socket sudah tertutup sebelum node pesan dikirim, aman diulang.
      if (code === 428 || code === 408) {
        throw new GatewayError('connection_closed', 'Koneksi WhatsApp tertutup sebelum pesan terkirim.', { retryable: true });
      }
      throw new GatewayError('send_failed', `Gagal mengirim pesan: ${err.message}`);
    }

    if (!sent?.key?.id) {
      throw new GatewayError('no_message_id', 'WhatsApp tidak mengembalikan ID pesan; status tidak pasti.', { uncertain: true });
    }
    this.sentCache.set(sent.key.id, sent.message);
    if (this.sentCache.size > SENT_CACHE_LIMIT) {
      this.sentCache.delete(this.sentCache.keys().next().value);
    }
    return { id: sent.key.id };
  }

  async #closeSocket() {
    const sock = this.sock;
    if (!sock) {
      return;
    }
    this.generation += 1;
    this.sock = null;
    try {
      await sock.end(undefined);
    } catch {
      // socket sudah tertutup
    }
  }

  // Tidak dihapus otomatis: admin yang memutuskan lewat "Putuskan Koneksi".
  #markUndecryptable() {
    this.creds = null;
    this.lastDisconnect = {
      code: null,
      reason: 'Sesi tersimpan tidak bisa dibuka (kunci enkripsi berubah atau data rusak). Putuskan koneksi lalu pindai QR ulang.',
      at: new Date().toISOString(),
    };
    this.setStatus(STATUS.FAILED);
  }

  #setMe(creds) {
    const id = creds?.me?.id;
    this.me = id ? maskPhone(id.split(':')[0].split('@')[0]) : null;
  }

  #authOptions() {
    return { pool: this.pool, schema: this.config.schema, encryptionKey: this.config.encryptionKey };
  }
}
