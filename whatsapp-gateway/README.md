# Gateway WhatsApp SIPINTER (Baileys, gratis)

Server Node.js kecil, **terpisah** dari aplikasi Laravel SIPINTER, yang login sebagai WhatsApp Web biasa (lewat pustaka [Baileys](https://github.com/WhiskeySockets/Baileys), tidak resmi/bukan WhatsApp Business API) untuk mengirim pesan pengingat.

Sesi login (setelah scan QR sekali) disimpan di **Postgres**, bukan disk — supaya tidak hilang saat Render free tier redeploy/tidur karena idle.

## 1. Siapkan Postgres gratis di Supabase

1. Buka [supabase.com](https://supabase.com) → **Start your project** → masuk pakai GitHub/Google/email.
2. Klik **New project**:
   - **Organization**: biarkan default (dibuat otomatis untuk akun baru).
   - **Name**: bebas, mis. `sipinter-wa-gateway`.
   - **Database Password**: buat password kuat lalu **simpan di tempat aman** — dipakai di connection string, TIDAK bisa dilihat lagi setelah ini (hanya bisa di-reset).
   - **Region**: pilih yang terdekat, mis. `Southeast Asia (Singapore)`.
   - **Pricing Plan**: pastikan **Free**.
3. Klik **Create new project**, tunggu 1-2 menit sampai provisioning selesai.
4. Setelah project siap, buka **Project Settings** (ikon gerigi di sidebar kiri bawah) → **Database**.
5. Scroll ke bagian **Connection string** → tab **URI**. Ada 3 mode koneksi, pilih **Session pooler** (BUKAN "Transaction pooler" atau "Direct connection") — alasannya:
   - Gateway ini proses Node.js yang menyala terus-menerus (bukan serverless), jadi butuh koneksi persisten seperti koneksi langsung biasa — cocok dengan mode Session.
   - "Direct connection" di Supabase kini hanya lewat IPv6, yang belum tentu didukung jaringan outbound Render — Session pooler tetap jalan lewat IPv4.
6. Salin string-nya, bentuknya seperti:
   ```
   postgresql://postgres.xxxxxxxxxxxxxxxx:[YOUR-PASSWORD]@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres
   ```
7. Ganti `[YOUR-PASSWORD]` dengan password dari langkah 2 (apa adanya — kalau mengandung karakter spesial seperti `@`/`#`/`%`, harus di-*URL-encode* dulu, mis. `@` → `%40`).

String hasil langkah 7 inilah yang jadi `DATABASE_URL` di langkah berikutnya. Tabel `wa_sessions` dibuat otomatis oleh gateway saat pertama jalan (lihat `src/authStore.js`) — tidak perlu bikin tabel manual.

## 2. Deploy ke Render

1. Buat **Web Service** baru di Render, hubungkan ke repo ini, set **Root Directory** ke `whatsapp-gateway`.
2. Build Command: `npm install`
3. Start Command: `npm start`
4. Health Check Path: `/status`
5. Environment Variables:
   - `DATABASE_URL` = connection string Postgres dari langkah 1
   - `WHATSAPP_API_TOKEN` = token rahasia bebas (mis. hasil `openssl rand -hex 32`) — **harus sama persis** dengan `WHATSAPP_API_TOKEN` di `.env` Laravel
6. Deploy.

## 3. Scan QR (sekali saja)

Buka `https://<url-render-anda>/qr?token=<WHATSAPP_API_TOKEN>` di browser, scan QR itu pakai WhatsApp di HP (Perangkat Tertaut → Tautkan Perangkat). Setelah `/status` menunjukkan `"connected"`, gateway siap dipakai — sesi tersimpan di Postgres, tidak perlu scan ulang walau service di-restart/redeploy (kecuali logout manual dari HP).

## 4. Sambungkan ke Laravel

Di `.env` aplikasi Laravel SIPINTER:

```
WHATSAPP_API_URL=https://<url-render-anda>
WHATSAPP_API_TOKEN=<token yang sama persis dengan langkah 2>
```

## Ganti nomor/perangkat yang tertaut

Paling gampang lewat SIPINTER sendiri: menu **Kelola Pengguna → tab "📱 Pengingat WA"** (Tim SAKIP) — ada tombol **Putus Tautan / Ganti Nomor** yang otomatis logout + hapus sesi lama, lalu halaman itu menampilkan QR baru begitu siap discan.

Kalau mau manual lewat API langsung:
1. `POST /reset` (header `Authorization: Bearer <token>`) — logout dari nomor lama & hapus sesi tersimpan.
2. Buka `GET /qr?token=<token>`, scan dengan nomor/perangkat baru.

## Endpoint

- `GET /status` — publik (dipakai Render health check), `{ "status": "connected" | "waiting_for_qr" | "disconnected" | "connecting" }`
- `GET /qr?token=...` — halaman HTML menampilkan QR code (untuk setup awal/manual dari browser)
- `GET /qr-data` — header `Authorization: Bearer <token>`, versi JSON `{ "status": "...", "qrDataUrl": "data:image/png;base64,..." | null }`, dipakai halaman "Pengingat WA" di SIPINTER
- `POST /send` — header `Authorization: Bearer <token>`, body `{ "nomor": "628xxxxxxxxxx", "pesan": "..." }`
- `POST /reset` — header `Authorization: Bearer <token>`, logout dari nomor yang tertaut + hapus sesi, lalu mulai ulang menunggu QR baru

## Troubleshooting instalasi

`@whiskeysockets/baileys` menarik dependency `libsignal-node` lewat `git clone ssh://git@github.com/...`, bukan dari npm registry biasa. Kalau `npm install` gagal dengan error terkait `libsignal-node`/SSH (butuh SSH key GitHub, atau di Windows: path terlalu panjang), jalankan ini dulu sebelum `npm install`:

```
git config --global url."https://github.com/".insteadOf ssh://git@github.com/
```

Ini memaksa git memakai HTTPS (tanpa perlu SSH key) untuk clone dependency tsb. Biasanya TIDAK diperlukan di Render (Linux) — hanya relevan bila instalasi lokal (mis. Windows) gagal karena ini.

## Catatan

- Render free tier akan sleep setelah idle ~15 menit lalu bangun lagi saat ada request masuk — koneksi WhatsApp akan otomatis reconnect (sesi tetap ada di Postgres), tapi ada jeda beberapa detik/menit pertama setelah bangun sebelum siap mengirim. Untuk pengingat yang harus real-time, pertimbangkan plan Render berbayar (tidak sleep) atau layanan *keep-alive* ping ke `/status` tiap beberapa menit.
- Jangan bagikan `WHATSAPP_API_TOKEN` — siapa pun yang memilikinya bisa mengirim pesan WA atas nama nomor yang terhubung.
- Ini API tidak resmi (reverse-engineered dari WhatsApp Web) — ada risiko kecil nomor terkena batasan dari WhatsApp bila mengirim pesan dalam volume sangat besar/berulang ke banyak nomor asing. Untuk pengingat internal ke pegawai sendiri dalam jumlah wajar, risikonya rendah.
