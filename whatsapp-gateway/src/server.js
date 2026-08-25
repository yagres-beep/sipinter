import express from 'express';
import pkg from 'pg';
import qrcode from 'qrcode';
import pino from 'pino';
import makeWASocket, { DisconnectReason, fetchLatestBaileysVersion } from '@whiskeysockets/baileys';
import { usePostgresAuthState } from './authStore.js';

const { Pool } = pkg;

const PORT = process.env.PORT || 3000;
const API_TOKEN = process.env.WHATSAPP_API_TOKEN;
const DATABASE_URL = process.env.DATABASE_URL;

if (!API_TOKEN) {
  console.error('WHATSAPP_API_TOKEN belum diset di environment, keluar.');
  process.exit(1);
}

if (!DATABASE_URL) {
  console.error('DATABASE_URL belum diset di environment, keluar.');
  process.exit(1);
}

const pool = new Pool({
  connectionString: DATABASE_URL,
  ssl: { rejectUnauthorized: false },
});

let sock = null;
let latestQr = null;
let connectionStatus = 'connecting';

async function startSock() {
  const { state, saveCreds } = await usePostgresAuthState(pool);
  const { version } = await fetchLatestBaileysVersion();

  sock = makeWASocket({
    version,
    auth: state,
    logger: pino({ level: 'warn' }),
    printQRInTerminal: false,
  });

  sock.ev.on('creds.update', saveCreds);

  sock.ev.on('connection.update', (update) => {
    const { connection, lastDisconnect, qr } = update;

    if (qr) {
      latestQr = qr;
      connectionStatus = 'waiting_for_qr';
    }

    if (connection === 'open') {
      connectionStatus = 'connected';
      latestQr = null;
      console.log('Gateway WhatsApp terhubung.');
    }

    if (connection === 'close') {
      connectionStatus = 'disconnected';
      const kodeAlasan = lastDisconnect?.error?.output?.statusCode;
      const haruskahReconnect = kodeAlasan !== DisconnectReason.loggedOut;
      console.warn('Koneksi WhatsApp terputus.', { kodeAlasan, haruskahReconnect });

      if (haruskahReconnect) {
        startSock();
      }
    }
  });
}

startSock();

const app = express();
app.use(express.json());

// /qr sengaja bisa diakses via ?token= (bukan cuma header Bearer) supaya bisa
// dibuka langsung dari browser saat setup awal (scan QR sekali).
function tokenValid(req) {
  const header = req.get('authorization') || '';
  const bearerToken = header.startsWith('Bearer ') ? header.slice(7) : null;

  return bearerToken === API_TOKEN || req.query.token === API_TOKEN;
}

function cekToken(req, res, next) {
  if (!tokenValid(req)) {
    return res.status(401).json({ status: 'error', pesan: 'Token tidak valid.' });
  }

  next();
}

app.get('/status', (req, res) => {
  res.json({ status: connectionStatus });
});

app.get('/qr', cekToken, async (req, res) => {
  if (connectionStatus === 'connected') {
    return res.send('<p>Gateway sudah terhubung, tidak perlu scan QR.</p>');
  }

  if (!latestQr) {
    return res.send('<p>QR belum tersedia, muat ulang halaman ini beberapa detik lagi.</p>');
  }

  const dataUrl = await qrcode.toDataURL(latestQr);
  res.send(`<img src="${dataUrl}" alt="Scan QR WhatsApp" />`);
});

// Versi JSON dari /qr + /status digabung, dipakai SIPINTER (Laravel) untuk
// menampilkan status & QR langsung di halaman admin tanpa membuka gateway
// terpisah. Beda dari /status (publik, tanpa token) — endpoint ini butuh
// token karena qrDataUrl bisa dipakai orang lain menautkan HP-nya sendiri.
app.get('/qr-data', cekToken, async (req, res) => {
  const respons = { status: connectionStatus, qrDataUrl: null };

  if (connectionStatus === 'waiting_for_qr' && latestQr) {
    respons.qrDataUrl = await qrcode.toDataURL(latestQr);
  }

  res.json(respons);
});

// Putuskan nomor yang sedang tertaut & hapus sesi tersimpan, lalu langsung
// mulai ulang koneksi supaya QR baru bisa discan (untuk ganti nomor/perangkat
// tanpa perlu masuk ke Supabase/restart service manual).
app.post('/reset', cekToken, async (req, res) => {
  try {
    if (sock) {
      try {
        await sock.logout();
      } catch (err) {
        console.warn('Logout gagal (kemungkinan sesi sudah terputus), lanjut hapus data sesi.', err.message);
      }
    }

    await pool.query('DELETE FROM wa_sessions');
    connectionStatus = 'connecting';
    latestQr = null;

    await startSock();

    res.json({ status: 'ok', pesan: 'Sesi lama dihapus. Menunggu QR baru.' });
  } catch (err) {
    console.error('Gagal reset sesi WhatsApp.', err);
    res.status(500).json({ status: 'error', pesan: 'Gagal reset sesi.' });
  }
});

app.post('/send', cekToken, async (req, res) => {
  const { nomor, pesan } = req.body || {};

  if (!nomor || !pesan) {
    return res.status(400).json({ status: 'error', pesan: 'Field "nomor" dan "pesan" wajib diisi.' });
  }

  if (connectionStatus !== 'connected' || !sock) {
    return res.status(503).json({ status: 'error', pesan: 'Gateway WhatsApp belum terhubung.' });
  }

  try {
    const jid = `${nomor}@s.whatsapp.net`;
    await sock.sendMessage(jid, { text: pesan });
    res.json({ status: 'ok' });
  } catch (err) {
    console.error('Gagal mengirim pesan WA.', err);
    res.status(500).json({ status: 'error', pesan: 'Gagal mengirim pesan.' });
  }
});

app.listen(PORT, () => {
  console.log(`Gateway WhatsApp berjalan di port ${PORT}.`);
});
