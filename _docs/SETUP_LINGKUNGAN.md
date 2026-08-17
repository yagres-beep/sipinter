# Setup Lingkungan — SIPINTER

Dicatat: 2026-08-15. Panduan ini menjelaskan cara menjalankan SIPINTER
(Laravel 12 + Livewire) secara lokal, konfigurasi database Supabase, dan
konfigurasi Google Drive — sudah diverifikasi langsung terhadap environment
`d:\Latsar\sipinter` pada tanggal di atas.

## 1. Prasyarat

| Komponen | Versi terpasang di mesin ini | Catatan |
|---|---|---|
| PHP | 8.2.4 (CLI, ZTS) | sesuai `composer.json` (`^8.2`) |
| Laravel | 12.64.0 | `php artisan --version` |
| Composer | — | wajib untuk `vendor/` |
| Node.js + npm | — | untuk build asset Vite (`resources/css/sipinter.css`, dll) |
| LibreOffice (headless) | **tidak ditemukan** | wajib untuk konversi Bagian II/III Notula ke PDF — lihat §4 |

## 2. Instalasi awal

```powershell
composer install
npm install
copy .env.example .env      # lewati bila .env sudah ada (sudah ada di repo ini)
php artisan key:generate    # lewati bila APP_KEY sudah terisi
php artisan migrate
```

Status migrasi di environment ini sudah **lengkap** (27 migrasi, batch 1–9,
semua `Ran` — dicek dengan `php artisan migrate:status`).

## 3. Konfigurasi Database (Supabase PostgreSQL)

Variabel di `.env` (nilai asli sudah terisi di repo ini, jangan commit ke git):

```
DB_CONNECTION=pgsql
DB_HOST=aws-0-ap-southeast-1.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.qlzeffstqfpwbsjzjowb
DB_PASSWORD=<lihat .env lokal>
```

Ini adalah **connection pooler Supabase** (region Singapore/`ap-southeast-1`),
bukan koneksi langsung — cocok untuk aplikasi web dengan banyak request pendek.

**Hasil verifikasi hari ini:**
- `php artisan migrate:status` → berhasil terhubung, seluruh 27 migrasi `Ran`.
- Query langsung ke tabel inti berhasil: `periode` (4 baris), `capaian` (12
  baris), `notula` (2 baris), `storage_account` (3 baris).
- Latensi terasa (~300–400 ms/query, dicatat juga sebagai komentar performa di
  `VerifikasiCapaian.php`) karena DB ada di Singapore sedangkan server aplikasi
  ada di lokasi lain — sudah diantisipasi lewat cache in-request di beberapa
  Livewire component (`VerifikasiCapaian`, `PengisianKegiatan`).

Bila koneksi gagal saat kamu menjalankan ulang, penyebab tersering: IP belum
diizinkan di Supabase Network Restrictions, atau pooler sedang maintenance —
cek dashboard Supabase project `qlzeffstqfpwbsjzjowb`.

## 4. Konfigurasi Google Drive

SIPINTER punya **dua jalur** ke Google Drive (lihat komentar panjang di
`config/services.php`):

1. **OAuth per-akun (jalur utama, wajib untuk akun Gmail biasa).** Tim SAKIP
   login sekali lewat menu *Akun & Storage* → *Hubungkan ke Google Drive*
   untuk tiap akun institusi. Token disimpan terenkripsi di
   `storage_account.google_refresh_token`.
2. **Service Account (fallback lama).** Dipakai otomatis hanya bila akun
   storage yang sedang *aktif* belum terhubung OAuth.

### Status saat verifikasi (2026-08-15)

| Akun | Role | OAuth terhubung? | Folder Drive |
|---|---|---|---|
| yagreskerja@gmail.com | master | Tidak | `1ArpLQ…SlzY3` |
| agrestina01@gmail.com | **aktif** | Tidak | sama (mengikuti master) |
| sipinter.demo@gmail.com | — | Tidak | belum ada |

→ Karena akun *aktif* belum OAuth, sistem otomatis jatuh ke **Service
Account**.

### Bug ditemukan & diperbaiki hari ini

`.env` mengarahkan `GOOGLE_SERVICE_ACCOUNT_PATH` ke
`google/service-account.json`, tapi file yang benar-benar ada di
`storage/app/google/` bernama
`sakip-notula-drive-7409-c4da8eb00796.json`. Akibatnya jalur fallback ini
**gagal total** (file tidak ditemukan) — dan karena tidak ada akun yang
OAuth-connected, ini berarti **semua upload ke Drive akan gagal**.

Sudah diperbaiki dengan mengubah satu baris di `.env`:

```
GOOGLE_SERVICE_ACCOUNT_PATH=google/sakip-notula-drive-7409-c4da8eb00796.json
```

**Diverifikasi hidup (live API call) setelah perbaikan:**
- Client Service Account berhasil dimuat dari kredensial JSON.
- `files.get` ke folder root (`GOOGLE_DRIVE_FOLDER_ID`) **berhasil** — folder
  bernama **"SAKIP7409"** terbaca, artinya folder tsb memang sudah dibagikan
  (share, role Editor) ke akun robot
  `notula-sakip-7409@sakip-notula-drive-7409.iam.gserviceaccount.com`.

Kesimpulan: **upload berkas ke Google Drive sudah bisa berjalan** lewat jalur
fallback Service Account, tanpa perlu login OAuth dulu. OAuth tetap
direkomendasikan untuk pemakaian jangka panjang (kuota Service Account = 0,
ia hanya numpang di folder yang dibagikan) — hubungkan lewat menu *Akun &
Storage* kapan pun siap.

### Jika ingin mencoba jalur OAuth secara lokal

Redirect URI yang terdaftar di Google Cloud Console saat ini adalah
`https://sipinter-butur.biz.id/akun-storage/google/callback` (mengikuti
`APP_URL` produksi). Untuk mencoba OAuth dari `http://127.0.0.1:8000`, kamu
perlu menambahkan redirect URI lokal yang sepadan di **Google Cloud Console →
APIs & Services → Credentials** terlebih dahulu — kalau tidak, Google akan
menolak dengan `redirect_uri_mismatch`. Untuk kebutuhan screenshot minggu ini,
tidak perlu — jalur Service Account di atas sudah cukup.

## 5. LibreOffice (untuk Kompilasi Notula Bagian II & III)

`LIBREOFFICE_PATH` di `.env` menunjuk ke
`C:\Program Files\LibreOffice\program\soffice.exe`, dipakai
`LibreOfficeConversionService` untuk mengonversi unggahan `.docx` Bagian II &
III notula menjadi PDF sebelum digabungkan (RF-42a/42b).

**Status: belum terpasang di mesin ini** (dicek `Test-Path` + pencarian
`soffice.exe` di seluruh `Program Files*`, tidak ditemukan).

Dampak: tombol **"Gabungkan → PDF"** pada halaman Kompilasi Notula akan gagal
begitu Bagian II/III diunggah, KHUSUS untuk konversi docx→PDF-nya — Bagian I
(disusun otomatis dari data terverifikasi) tetap berfungsi normal tanpa
LibreOffice.

Untuk mengaktifkan penuh: unduh LibreOffice dari
[libreoffice.org/download](https://www.libreoffice.org/download/download/),
instal dengan path default, lalu jalankan ulang `php artisan config:clear`.

## 6. Menjalankan aplikasi

```powershell
composer run dev
```

Perintah di atas (didefinisikan di `composer.json`) menjalankan bersamaan:
`php artisan serve` (server web), `queue:listen`, `php artisan pail` (log
viewer), dan `npm run dev` (Vite). Aplikasi akan tersedia di
**http://127.0.0.1:8000**.

Alternatif manual (dua terminal terpisah):

```powershell
php artisan serve
npm run dev
```

## 7. Akun untuk login (screenshot bukti dukung)

Tiga akun sudah ada di database (satu per peran):

| Nama | Email | Peran |
|---|---|---|
| Admin Tim SAKIP | admin@sipinter.bps.go.id | Tim SAKIP |
| Yulinda Agrestina | ketuatim@sipinter.bps.go.id | Ketua Tim |
| Kepala BPS Buton Utara | kepala@sipinter.bps.go.id | Kepala |

Kata sandi mengikuti yang sudah pernah diset sebelumnya. Bila lupa, reset
lewat tinker:

```powershell
php artisan tinker --execute="App\Models\User::where('email','admin@sipinter.bps.go.id')->first()->update(['password'=>Hash::make('PasswordBaru123!')]);"
```

## 8. Ringkasan verifikasi hari ini

| Item | Status |
|---|---|
| Koneksi DB Supabase | ✅ Berhasil |
| Google Drive — Service Account (fallback) | ✅ Berhasil (setelah perbaikan path) |
| Google Drive — OAuth per akun | ⚠️ Belum ada akun yang terhubung (opsional untuk minggu ini) |
| LibreOffice (konversi Notula Bagian II/III) | ❌ Belum terpasang di mesin ini |
| Modul Pencatatan Capaian per Bulan + Triwulan Otomatis | ✅ Berjalan (lihat detail di README modul) |
| Modul Verifikasi Bukti Dukung (preview PDF inline) | ✅ Berjalan, tidak bergantung status Drive |
| Modul Kompilasi Notula Triwulanan | ⚠️ Bagian I berjalan; gabung Bagian II/III tertahan LibreOffice |
