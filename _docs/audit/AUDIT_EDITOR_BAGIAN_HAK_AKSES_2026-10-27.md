# Audit: Editor Bagian Universal & Hak Akses per Peran — 27 Oktober 2026

Cabang: `fitur/editor-bagian-hak-akses` (dari `origin/main` 745297f, worktree `D:\Latsar\sipinter-kustom-dinamis`).
Status (28 Okt): Tahap A (audit), B (perbaikan menu), C (pusat pengelolaan) dan D (matriks hak Bagian Kustom) selesai, belum commit. Tahap E (alur) sengaja tanpa mesin baru sesuai keputusan. Lihat bagian 10.

---

## 1. Ringkasan audit dan akar masalah menu

**Gejala:** Bagian Kustom yang ditempatkan di Isian Kegiatan baru muncul di bilah navigasi bagian (dan di halaman) setelah IKU dipilih.

**Akar masalah ada di tampilan, bukan di server atau cache.**

- Server sudah benar. `PengisianKegiatan::bagianKustomAktif()` memanggil `BagianKustom::daftarUntuk('ketua_tim', 'pengisian')`, yang membaca cache `bagian-kustom.aktif`. Pemanggilan ini tidak memakai IKU, periode, atau data isian.
- Masalah 1: tombol navigasi bagian kustom diberi `x-show="$wire.iku_id"` (dan `x-cloak` saat IKU kosong). Tombol Bagian 2–5 tetap tampil dengan tanda 🔒, tetapi tombol bagian kustom disembunyikan.
- Masalah 2: blok `@foreach ($bagianKustomAktif …)` tertanam **di dalam** pembungkus Bagian 5 (RTL), yaitu `<div x-show="ikuDipilih" x-cloak>` → `<div wire:loading.remove>`. Akibatnya judul, deskripsi, dan formulir bagian kustom ikut tersembunyi. Ini juga yang membuat bagian kustom tampak seperti bagian dari RTL.
- Cache tidak bermasalah. `BagianKustomManager` memanggil `BagianKustom::lupakanCache()` setiap kali menyimpan, dan kunci cache tidak memakai IKU.

**Lapisan yang benar diperbaiki:** struktur Blade dipisah antara metadata dan navigasi (selalu tampil) dan formulir data (menunggu IKU, karena poin Isian Kegiatan disimpan per `iku_id`).

## 2. Perbaikan menu (Tahap B) — sudah dikerjakan

File: `resources/views/livewire/pengisian-kegiatan.blade.php`

1. Tombol navigasi bagian kustom selalu dirender. Saat IKU kosong, tombol diberi tanda 🔒, sama seperti Bagian 2–5.
2. Blok bagian kustom dikeluarkan dari pembungkus Bagian 5 (RTL). Judul, deskripsi, dan keterangan aturan wajib atau bukti selalu tampil.
3. Selama IKU belum dipilih, formulir setiap bagian diganti pesan "🔒 Pilih IKU terlebih dahulu (Bagian 1) untuk mengisi …", dengan tautan ke Bagian 1. Tidak ada formulir yang tidak bisa dipakai, dan tidak ada data yang dibuat.
4. Catatan "Analisis capaian … diisi Tim SAKIP" dipindah ke akhir halaman dan tetap hanya tampil setelah IKU dipilih.

Yang **tidak** diubah:
- Logika server.
- Validasi (simpan atau ajukan tanpa IKU tetap ditolak di backend: `iku_id` required).
- Cache dan data.
- Perilaku Bagian 1–5.

Tes baru: `tests/Feature/BagianKustomMenuTanpaIkuTest.php` (8 tes, 37 asersi).

| # | Yang dibuktikan | Butir prompt |
|---|---|---|
| 1 | Bagian aktif tampil di menu, judul berdiri sendiri, ada pesan kunci, tanpa membuat Periode atau poin | 41.1, 41.6 |
| 2 | Judul bagian kustom tidak lagi tersarang di dalam pembungkus RTL (keseimbangan `<div>`) | akar masalah |
| 3 | Menu tetap ada saat bulan, tahun, atau IKU berubah dan setelah komponen dimuat ulang | 41.2–41.4 |
| 4 | Bagian nonaktif dan bagian peran lain tidak muncul | 41.5 |
| 5 | Bagian baru langsung berlaku setelah cache dilupakan, tanpa deploy | 41.9 |
| 6 | Simpan tanpa IKU ditolak backend dan tidak membuat poin | 41.6, 46 |
| 7 | Formulir tampil setelah IKU dipilih | 41.7 |
| 8 | Kepala dan Tim SAKIP mendapat 403 saat membuka `/pengisian` langsung | 41.10 |

Bukti regresi: dengan Blade lama, tes 1 dan 2 **gagal**. Dengan Blade baru, keduanya lulus.

## 3. Arsitektur otorisasi saat ini

| Komponen | Kondisi aktual |
|---|---|
| Peran | Tabel `roles`, berisi 3 nama: **Ketua Tim**, **Tim SAKIP**, **Kepala**. `users.role_id`: satu pengguna punya satu peran. Tidak ada peran lain. |
| Gerbang rute | Middleware `role:<nama>` (`EnsureUserHasRole`). Aksi Livewire yang ditolak dicatat lewat `LogAktivitas::aksesDitolak`. |
| Gerbang dalam komponen | Sekitar 30 pemeriksaan `namaRole() === 'Tim SAKIP'` / `abort_unless` di 13 komponen Livewire (VerifikasiCapaian, MasterIku, LakinList/Detail, BagianKustomManager, VariabelKatalogManager, LogAktivitasViewer, PengaturanWhatsApp, dan lainnya). |
| Policy / Gate / paket permission | **Tidak ada.** Tidak ada `app/Policies`, tidak ada Spatie Permission, tidak ada tabel permission. |
| Hak akses Bagian Kustom | Sudah ada versi mini yang bisa dikonfigurasi per bagian: `peran` (pengisi), `peran_pembaca` (baca saja), `penempatan` (menu). Batasnya adalah `MenuBagianKustom::katalog()` (peran yang boleh membuka tiap menu), sehingga konfigurasi tidak bisa memberi akses melebihi gerbang rute. Data dipisah per konteks menu + peran (`BagianKustomPoin::scopeKonteks`). |
| Riwayat konfigurasi | `riwayat_konfigurasi_bagian_kustom` (definisi bagian, sebelum dan sesudah) + `log_aktivitas` (isian, bukti, akses ditolak; dilindungi trigger dari ubah/hapus). |
| Cache | `bagian-kustom.aktif` (1 jam, dilupakan terarah saat simpan). Per periode + IKU di PengisianKegiatan. |

## 4. Inventaris bagian SIPINTER

Jenis: **A** = dinamis (struktur dari database), **B** = konfigurasi terbatas, **C** = logika bisnis khusus (struktur di kode).

| Bagian | Jenis | Komponen / rute | Tabel | Butuh IKU | Periode | Bukti dukung | Notula | Proses khusus | Bisa dikonfigurasi tanpa kode? | Risiko ubah struktur |
|---|---|---|---|---|---|---|---|---|---|---|
| 1. IKU (pemilihan) | C | PengisianKegiatan, Bagian 1; Data Master: MasterIku, PenugasanIku, TargetTahunan | `master_iku`, `iku_penugasan`, `iku_tim`, `capaian_tahunan` | — | tahun | — | Bagian I (kode, indikator) | penugasan tim, target N/n | Tidak | Tinggi: kunci semua relasi |
| 2. Kegiatan | C | PengisianKegiatan, Bagian 2 | `kegiatan`, `berkas`, `rincian_output` | Ya | bulan | Ya, PDF; folder Drive per kegiatan/tahapan | Bagian I | tahapan survei/sensus, folder Drive, n | Tidak | Tinggi: folder Drive, notula, RTL |
| 3. Kendala & Solusi | C | PengisianKegiatan, Bagian 3 | `kendala_solusi` | Ya | bulan | Tidak | Bagian I | diverifikasi Sesuai/Tidak Sesuai per pasang | Tidak (label tertanam di Blade, notula, DOCX) | Sedang: pasangan kendala ↔ solusi |
| 4. Evaluasi RTL | C | PengisianKegiatan, Bagian 4; RtlEvaluasi (`/rtl-evaluasi`) | `rtl_evaluasi`, `salinan_bukti_rtl` | Ya | triwulan sebelumnya | Ya, bukti capaian ditaut per ID | Bagian I/II | dikaitkan dengan ID (bukan teks), salinan bukti | Tidak | Tinggi: relasi ID ke kegiatan dan bukti |
| 5. RTL Triwulan berikutnya | C | PengisianKegiatan, Bagian 5 | `rtl_evaluasi` (poin periode berikutnya) | Ya | hanya bulan ke-3 triwulan | Tidak | Bagian I | PIC otomatis dari tim IKU, ajukan ulang, verifikasi per poin | Tidak | Tinggi: aturan triwulan |
| Verifikasi capaian | C | VerifikasiCapaian (`/verifikasi`) | `capaian`, `riwayat_status_capaian`, `riwayat_verifikasi_berkas` | Ya | bulan | menandai bukti | — | status alur (bagian 5) | Tidak | Tinggi |
| Notula & Persetujuan | C | KompilasiNotula, PersetujuanNotula, TemplateNotula | `notula`, `riwayat_status_notula` | — | triwulan | — | inti | draft → menunggu_persetujuan → disetujui/dikembalikan | Template saja | Tinggi |
| Rekap Kinerja Tahunan (LAKIN) | C | LakinList/Detail | `lakin`, `lakin_baris` | — | tahun | — | — | hanya Tim SAKIP yang membentuk | Tidak | Sedang |
| Dasbor / Dasbor Kinerja | C (baca) | DasborUtama, DasborCapaian | — | — | — | — | — | baca saja | Tidak | Rendah |
| **Bagian Kustom** (mis. "Manajemen Risiko") | **A** | BagianKustomManager (Data Master); diisi di PengisianKegiatan atau IsianBagianKustom | `bagian_kustom`, `bagian_kustom_poin`, `berkas` | Isian Kegiatan: ya (per IKU). Menu lain: IKU per baris | bulan; kolom bisa mulai berlaku per triwulan | Bisa diatur (mode, jumlah, format, ukuran) | Bisa diatur (judul, format baris, variabel) | ikut pengajuan Isian Kegiatan; lanjutan antarperiode | **Ya, sepenuhnya** | Rendah: kunci kolom dan kode tetap, nonaktif tanpa hapus |

**Catatan Manajemen Risiko:** tidak ada modul bawaan "Manajemen Risiko" di kode. Nama itu hanya contoh Bagian Kustom (komentar kode dan contoh variabel notula). Jika bagian ini ada di produksi, ia dikelola sebagai Bagian Kustom (jenis A).

## 5. Status alur kerja aktual (tidak ada status baru yang diperlukan)

- **Capaian / Kegiatan:** `draft` → `diajukan` → `sedang_ditangani` (Tim SAKIP simpan sementara) → `diverifikasi` atau `dikembalikan` → `disetujui` (masuk notula final). Satu-satunya jalur ubah status: `Capaian::catatStatus()`.
- **Kendala & Solusi / RTL Evaluasi / berkas:** status per butir `terverifikasi` / `ditolak` (Sesuai / Tidak Sesuai) + `draft`/`diajukan`.
- **Notula:** `draft` → `menunggu_persetujuan` → `disetujui` / `dikembalikan` (Kepala).
- Penguncian formulir Ketua Tim: `formTerkunciDisetujui` (disetujui) dan `formTerkunciSedangDitangani` (sedang diperiksa), diperiksa ulang di server pada simpan dan ajukan.

## 6. Matriks hak akses aktual (dari kode, bukan konfigurasi)

Ya = diizinkan, — = ditolak di backend (rute 403 atau `abort`).

| Bagian | Peran | Lihat menu | Baca | Buat | Ubah | Hapus | Ajukan | Verifikasi | Setujui | Kembalikan |
|---|---|---|---|---|---|---|---|---|---|---|
| Isian Kegiatan (Bagian 1–5) | Ketua Tim | Ya | Ya (IKU semua tim*) | Ya | Ya, selama tidak terkunci | Ya, butir belum terverifikasi | Ya | — | — | — |
| | Tim SAKIP | — | Ya, lewat Verifikasi | — | Ya, saat verifikasi (analisis, angka) | — | — | Ya | — | Ya |
| | Kepala | — | Ringkasan di Dasbor/Notula | — | — | — | — | — | — | — |
| Notula | Ketua Tim | Riwayat | Final saja | — | — | — | — | — | — | — |
| | Tim SAKIP | Ya | Ya | Ya, kompilasi | Ya, draft | — | Ya, ke Kepala | — | — | — |
| | Kepala | Ya | Ya | — | — | — | — | — | Ya | Ya |
| Data Master & Konfigurasi (IKU, target, folder, Bagian Kustom, katalog, log) | Tim SAKIP | Ya | Ya | Ya | Ya | Ya | — | — | — | — |
| | Lainnya | — | — | — | — | — | — | — | — | — |
| Bagian Kustom (isian) | sesuai `peran` / `peran_pembaca` / `penempatan` per bagian, dibatasi katalog menu | Ya | Ya | Ya, pengisi | Ya, pengisi | Ya, pengisi | ikut Isian Kegiatan | Tim SAKIP (Verifikasi) | — | Tim SAKIP |
| Rekap Kinerja Tahunan | Semua | Ya | Ya | Tim SAKIP | Tim SAKIP | Tim SAKIP | — | — | — | — |

\* Terkonfirmasi di kode: `aturanIkuValid()` menerima semua IKU di `MasterIku::daftarUrutKode()`. Ketua Tim mana pun bisa memilih dan mengisi IKU tim lain. Ini perilaku produksi saat ini dan tidak diubah. Bila perlu dibatasi per tim, itu keputusan terpisah (butir 28 prompt: "Read tidak otomatis memberi akses ke seluruh data").

Hak konfigurasi (mengubah definisi bagian, katalog variabel, folder, IKU) saat ini **hanya Tim SAKIP**. Tidak ada jalur bagi peran lain untuk menaikkan kewenangannya sendiri.

## 7. Rancangan Tahap C dan D, serta keputusan yang dibutuhkan

### Mengapa belum langsung dikerjakan

Prompt melarang mengubah hak akses produksi tanpa audit, membuat sistem otorisasi kedua, dan memaksakan bagian bawaan menjadi formulir generik. Matriks hak akses yang benar-benar **menentukan** perilaku backend untuk bagian bawaan berarti mengganti sekitar 30 gerbang tertanam dan gerbang rute di modul paling kritis (Verifikasi, Pengisian, Persetujuan). Siapa yang boleh mengubah hak akses juga harus diputuskan secara eksplisit oleh pemilik proses.

### Usulan minimum yang aman (bisa dikerjakan setelah disetujui)

**C1. Registri bagian (kode, tanpa tabel baru)**
- Kelas `App\Support\RegistriBagian` mendaftarkan bagian bawaan (jenis C) dengan identitas stabil `iku`, `kegiatan`, `kendala_solusi`, `evaluasi_rtl`, `rtl_triwulan`, plus semua Bagian Kustom (jenis A) dari database.
- Data per bagian: kebutuhan IKU/periode, dukungan bukti dan notula, aksi yang didukung.
- Memperluas `MenuBagianKustom` yang sudah ada, bukan sistem baru.

**C2. Tab "Daftar Bagian" di Data Master → Bagian Kustom**
- Satu daftar berisi bagian bawaan (berlabel "Sistem — struktur di kode", dengan matriks hak akses aktual baca-saja dan alasan tidak bisa diubah) dan Bagian Kustom (tombol Edit/Aktifkan seperti sekarang).

**C3. Opsional: label tampilan bagian bawaan**
- Ganti label menu, judul, dan petunjuk Kendala & Solusi, Kegiatan, dan lainnya lewat kolom JSON baru yang aditif.
- **Tidak** mengubah notula, DOCX, atau nama folder Drive.
- Perlu satu migrasi aditif (pola `MigrasiKunciSingkat`).

**D1. Perluasan hak Bagian Kustom (jenis A saja)**
- Ganti pasangan `peran`/`peran_pembaca` menjadi matriks per peran: Lihat, Buat, Ubah, Hapus, Hapus bukti.
- Tetap dibatasi katalog menu.
- Data lama dipetakan otomatis: pengisi = semua aksi, pembaca = Lihat. Kolom lama dipertahankan.
- Diperiksa di `IsianBagianKustom` dan `PengisianKegiatan` pada setiap aksi, dan dicatat di riwayat konfigurasi.

**D2. Bagian bawaan**
- Hak tetap dari kode dan **ditampilkan** di matriks (baca-saja).
- Mengubahnya menjadi dapat dikonfigurasi memerlukan keputusan terpisah dan pemindahan gerbang ke Gate Laravel bawaan, bukan paket baru.

### Keputusan yang perlu dari pemilik proses

1. Siapa yang boleh mengubah konfigurasi bagian dan hak akses? Usulan: tetap **hanya Tim SAKIP**, tanpa bisa mencabut hak konfigurasi milik Tim SAKIP sendiri.
2. Untuk bagian bawaan, cukup **tampil baca-saja** di pusat pengelolaan (C2 + D2), atau perlu label yang bisa diganti (C3)?
3. Perlukah matriks CRUD per peran untuk Bagian Kustom (D1), atau `peran` + `peran_pembaca` yang ada sudah cukup?

## 7a. Hasil pengujian (27 Okt)

| Lingkungan | Hasil |
|---|---|
| SQLite (`php artisan test`) | **938 lulus**, 0 gagal, 0 dilewati (4261 asersi) |
| PostgreSQL 18 lokal (klaster uji :55432, `sipinter_uji`) | **938 lulus**, 0 gagal (4689 asersi) |
| Uji browser manual | Belum dijalankan |

## 8. Perubahan database

Tahap A dan B: tidak ada. Tahap C dan D: dua migrasi, keduanya **hanya menambah**.

| Migrasi | Isi | Dampak ke data lama |
|---|---|---|
| `2026_10_27_000001_tambah_hak_aksi_ke_bagian_kustom` | Kolom JSON `bagian_kustom.hak_aksi` (nullable) | Tidak ada. NULL = perilaku lama (pengisi boleh semua aksi). Kolom `peran` dan `peran_pembaca` tetap dipakai. |
| `2026_10_27_000002_create_pengaturan_bagian_bawaan_table` | Tabel baru `pengaturan_bagian_bawaan` (kode unik, label_menu, judul, petunjuk, diubah_oleh) | Tidak ada. Tabel kosong = label bawaan dari kode. |

Keduanya memakai `MigrasiKunciSingkat` (tunggu kunci tabel maks. 5 detik per percobaan, lalu ulang) dan aman dijalankan ulang (`hasColumn` / `hasTable`). Rollback: `down()` hanya membuang kolom/tabel baru; kode membaca keduanya dengan aman bila belum ada.

## 9. Perlindungan data

- Tidak ada perintah yang menyentuh database nyata (produksi tidak disentuh, termasuk baca-saja).
- Tes memakai database uji (trait `PenjagaDatabaseUji`; PostgreSQL di klaster lokal :55432).
- Migrasi aditif; tidak ada data, bukti Drive, riwayat, atau perhitungan N/n yang diubah.
- Label bagian bawaan hanya dibaca layar Isian Kegiatan. Notula, DOCX, nama folder Drive, dan relasi data tidak membacanya.
- Bagian III (input sasaran) tidak disentuh.

## 10. Tahap C, D, dan E — hasil (sesuai keputusan pemilik proses 27 Okt)

Keputusan yang dipakai: (1) pengelola konfigurasi, hak, dan alur hanya **Tim SAKIP**; (2) bagian bawaan: label menu, judul, dan petunjuk bisa diganti, struktur/notula/Drive tetap di kode; (3) Bagian Kustom memakai matriks hak per peran; (4) alur kerja Bagian Kustom tetap ikut Isian Kegiatan, **tanpa mesin alur baru**.

### 10.1 Pusat pengelolaan (Tahap C)

Data Master → tab **🧩 Pengelolaan Bagian** (sebelumnya "Bagian Kustom"):
- Komponen baru `DaftarBagianSipinter` di atas editor Bagian Kustom yang sudah ada. Satu tempat, tidak ada menu ganda.
- Registri `App\Support\RegistriBagian` mendaftarkan 8 bagian bawaan dengan kode tetap (`iku`, `kegiatan`, `kendala_solusi`, `evaluasi_rtl`, `rtl_triwulan`, `verifikasi`, `notula`, `lakin`) dan semua Bagian Kustom dari database.
- Setiap bagian menampilkan jenis, menu, kebutuhan IKU/periode, bukti, notula, tabel, hak per peran, alur, dan batasan konfigurasinya.

| Jenis | Bagian | Yang bisa diatur dari layar | Alasan batasan |
|---|---|---|---|
| A — dinamis penuh | Semua Bagian Kustom | Struktur subbagian & kolom, peran, menu, hak aksi, bukti, notula, tampilan | Dirancang dinamis sejak awal |
| B — terbatas | IKU, Kegiatan, Kendala & Solusi, Evaluasi RTL, RTL Triwulan | Label menu, judul, petunjuk | Struktur terkait folder Drive, notula/DOCX, relasi ID, aturan triwulan |
| C — logika khusus | Verifikasi, Notula & Persetujuan, Rekap Kinerja Tahunan | Tidak ada (hanya dokumentasi) | Aturan alur dan perhitungan inti |

Perubahan label: dicatat di riwayat konfigurasi (sebelum/sesudah), cache dilupakan terarah, penyimpanan bentrok antar-Tim SAKIP ditolak (pemeriksaan versi + `lockForUpdate`). Mengosongkan isian = kembali ke label bawaan.

### 10.2 Hak akses (Tahap D)

Matriks per bagian dan peran pengisi: **Buat poin, Ubah poin, Hapus poin, Unggah bukti, Hapus bukti**. "Lihat" tetap diatur lewat `peran` (pengisi) dan `peran_pembaca` (baca saja), dan tetap dibatasi gerbang rute tiap menu (`MenuBagianKustom::katalog()`), sehingga konfigurasi tidak pernah memberi akses melebihi rute.

Pemeriksaan server (bukan hanya tombol):
- `IsianBagianKustom`: tambah baris, lanjutkan, hapus baris tersimpan, simpan (setiap poin baru/berubah/dibuang dan setiap berkas baru diperiksa **sebelum** apa pun ditulis), hapus bukti.
- `PengisianKegiatan`: poin baru, poin lama yang berubah, unggah/ganti bukti, hapus bukti. Hapus poin tersimpan memang tidak tersedia di Isian Kegiatan (perilaku lama).
- Setiap penolakan → 403 + catatan di Log Aktivitas.
- Hanya Tim SAKIP yang bisa membuka pusat pengelolaan dan mengubah matriks; tidak ada jalur bagi peran lain menaikkan haknya sendiri.

Hak bagian bawaan **tidak** dipindah ke konfigurasi: tetap dari middleware `role:` dan pemeriksaan di komponennya (tabel bagian 6), ditampilkan baca-saja di pusat pengelolaan.

### 10.3 Alur kerja (Tahap E)

Sesuai keputusan (4), **tidak ada mesin alur atau status baru**. Bagian Kustom di Isian Kegiatan ikut alur capaian yang sudah ada (bagian 5): Draft → Diajukan → Sedang ditangani → Diverifikasi/Dikembalikan → Disetujui, dengan penguncian yang diperiksa ulang di server. Alur Notula dan Verifikasi tidak diubah. Butir prompt tentang status/transisi yang dapat dikonfigurasi, template alur, dan notifikasi per transisi **tidak dikerjakan** dan memerlukan keputusan baru bila nanti dibutuhkan.

### 10.4 Tes baru

- `BagianKustomMenuTanpaIkuTest` — 8 tes (Tahap B).
- `BagianKustomHakAksiTest` — 18 tes: bagian lama tetap berhak penuh; matriks membatasi tiap aksi; buat/ubah/hapus/hapus-bukti ditolak server di kedua menu; pembaca tidak bisa menyimpan lewat request langsung; perubahan matriks tercatat; simpan tanpa perubahan mempertahankan NULL; peran selain Tim SAKIP ditolak; daftar memuat bawaan + kustom; label bawaan berlaku langsung dan bisa dikembalikan; bagian jenis C tidak bisa diberi label; validasi panjang; penyimpanan bentrok ditolak.

### 10.5 Hasil pengujian (28 Okt, setelah Tahap C/D)

| Lingkungan | Hasil |
|---|---|
| SQLite (`php artisan test`) | **956 lulus**, 0 gagal, 0 dilewati (4350 asersi) |
| PostgreSQL 18 lokal (klaster uji :55432) | **956 lulus**, 0 gagal (4832 asersi) |
| Uji browser manual | Belum dijalankan |
| Gladi migrasi di salinan skema produksi | Belum dijalankan |

### 10.6 Kesiapan rilis

**Siap diuji di lingkungan pengujian.** Belum di-commit. Sebelum rilis: uji browser (Tim SAKIP mengubah label & matriks; Ketua Tim mengisi dengan hak terbatas), gladi migrasi di salinan skema produksi, backup terverifikasi, lalu persetujuan eksplisit. Push ke `main` tanpa `[skip render]` = deploy + migrasi otomatis.
