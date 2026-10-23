# Finalisasi Rilis SIPINTER — Bagian Kustom, Log Aktivitas, Google Drive (22 Oktober 2026)

- **Cabang kerja:** `susun-ulang-pertahankan-suntingan`, HEAD `19ff82d` + perubahan belum di-commit.
- **Tidak dilakukan:** staging, commit, push, deploy, migrasi produksi, perubahan data produksi.
- **Akses produksi yang dilakukan:** hanya baca, dalam transaksi `READ ONLY` + `ROLLBACK`, dan `pg_dump --schema-only` (tanpa data). Kredensial tidak ditulis ke berkas mana pun.
- Laporan sebelumnya: `AUDIT_PRA_RILIS_BAGIAN_KUSTOM_2026-10-22.md` (temuan U-1 s.d. U-10 dirujuk dari sana).

## 0. Status singkat

**Semua perbaikan dan fitur di cabang ini selesai dan lulus test di SQLite dan PostgreSQL. Namun cabang ini BELUM BISA dirilis apa adanya** — lihat §1. Rilis butuh satu paket kerja integrasi dengan `origin/main` dan persetujuan Anda.

## 1. Penghalang rilis: cabang ini tertinggal dari kode produksi

Fakta (diperiksa hari ini):

| Hal | Nilai |
|---|---|
| Migrasi yang sudah jalan di produksi | 102, batch terakhir 68 (`2026_10_20_000002`) |
| Migrasi produksi yang **tidak ada** di cabang ini | `2026_10_19_000001` (salinan bukti RTL & riwayat verifikasi berkas), `2026_10_20_000001` (realisasi 5 desimal), `2026_10_20_000002` (folder tahapan) |
| Ketiganya ada di | `origin/main` |
| Cabang ini vs `origin/main` | tertinggal **50** commit, unggul **15** commit (titik cabang `f31fd07`, 15 Okt) |
| Uji gabung tanpa menyentuh index (`git merge-tree`) | **18 berkas konflik**, termasuk `PengisianKegiatan`, `VerifikasiCapaian`, `Berkas`, `FolderStructureService`, `GoogleDriveService`, `NotulaBagian1DocxService`, Blade, CSS/JS, dan 6 test |

Akibatnya: men-deploy cabang ini akan **menghapus ±50 commit fitur yang sudah berjalan di produksi** (mis. unggah langsung ke Drive, hapus bukti ikut ke Sampah Drive, realisasi 5 desimal, salinan bukti RTL, backup otomatis mingguan, pg_dump 17). Database tidak rusak (Laravel mengabaikan migrasi yang berkasnya tidak ada), tetapi fiturnya hilang dari aplikasi.

Perbedaan rancangan yang perlu diputuskan saat integrasi:

| Topik | `origin/main` (produksi) | Cabang ini |
|---|---|---|
| Waktu unggah ke Drive | langsung saat berkas dipilih | setelah transaksi simpan berhasil (perbaikan U-3) |
| Hapus bukti | ikut dihapus/dipindah ke Sampah Drive | salinan Drive sengaja tidak dihapus |
| Penanda berkas di Drive | `cariBerkasBerpenanda()` (sudah ada) | `cariBerkasBertanda()` (baru, mirip) |
| Bagian Kustom | versi dasar (tanpa F-01–F-04, tanpa log) | konfigurasi lengkap + otorisasi + log + riwayat |

**Usulan (perlu persetujuan):** commit cabang ini per kelompok (§8), buat cabang `integrasi/rilis-bagian-kustom` dari `origin/main`, gabungkan cabang ini ke sana, selesaikan 18 konflik dengan **mengikuti perilaku produksi** untuk Drive (unggah langsung, hapus ke Sampah) dan memindahkan perbaikan cabang ini (otorisasi, bentrok, log, riwayat, pemeriksa konsistensi) ke atasnya, lalu ulangi seluruh test di kedua database dan gladi migrasi di salinan skema produksi (§6.3).

## 2. Temuan yang diperbaiki (di cabang ini)

| # | Temuan | Perbaikan | Bukti test |
|---|---|---|---|
| U-1 | Dua Ketua Tim menyunting poin yang sama → tertimpa diam-diam | Penguncian optimistis untuk **seluruh formulir** Isian Kegiatan (Kegiatan, Kendala & Solusi, RTL berikutnya, poin Bagian Kustom): versi isi baris dicatat saat dimuat; saat simpan baris dikunci `FOR UPDATE` dan dibandingkan; bila beda **seluruh** penyimpanan batal, isian tetap di layar, tombol "Muat data terbaru" memuat ulang hanya bagian yang bentrok sambil mempertahankan baris baru. Juga untuk pengaturan Bagian Kustom (dua Tim SAKIP) | `KonflikPenyuntinganTest` (9) |
| U-2 | Riwayat konfigurasi di luar transaksi | `tambah`, `simpan`, `toggleAktif`, `naikkan/turunkan`, `hapus` dalam satu transaksi bersama riwayatnya (dan kategori folder); baris dikunci. Riwayat menyimpan salinan nama pelaku | `RiwayatKonfigurasiTransaksiTest` (5) |
| U-3 | Unggah Drive di dalam transaksi → berkas Drive & lokal yatim | Unggahan Drive dijalankan **setelah commit**; bila transaksi batal tidak ada unggahan dan berkas lokal yang sempat disimpan dihapus; keadaan formulir dikembalikan (tidak ada id baris "hantu" → tidak ada data ganda saat simpan ulang) | `DriveKonsistensiBuktiTest` |
| U-4 | Log bisa diubah lewat query langsung | Trigger database: `UPDATE`/`DELETE` (dan `TRUNCATE` di PostgreSQL) pada `log_aktivitas` ditolak | `MigrasiFinalisasiTest`, `LogAktivitasBagianKustomTest` |
| U-5 | Status Drive di log basi setelah sinkron susulan | Catatan baru `bukti.tersinkron` ditulis di transaksi yang sama dengan pencatatan id Drive (saat simpan maupun `berkas:sinkron-drive`) | `DriveKonsistensiBuktiTest` |
| U-6 | Nonaktifkan bagian dengan poin menunggu | **Bukan masalah** — `bagianKustomList()` di Verifikasi tidak menyaring status aktif; poin tetap bisa diverifikasi. Dikunci dengan test, aturan bisnis tidak diubah | `OtorisasiFinalisasiTest` |
| U-7 | `Periode::firstOrCreate()` sebelum validasi | Semua id diperiksa lebih dulu; periode baru dibuat hanya bila lolos | `OtorisasiFinalisasiTest` |
| U-8 | Halaman Verifikasi tidak cek peran per aksi | `boot()` Tim SAKIP di Verifikasi, **dan** middleware peran rute dijalankan ulang di **setiap** request Livewire untuk semua halaman | `OtorisasiFinalisasiTest` (termasuk uji HTTP sungguhan; terbukti gagal tanpa perbaikan) |
| U-9 | Banjir log penolakan | Penolakan identik oleh pelaku yang sama dicatat sekali per 60 detik; catatan "berhasil" tidak pernah diredam | `LogAktivitasViewerTest` |
| Baru | **Id baris dari browser tidak diperiksa kepemilikannya** di Isian Kegiatan: Kegiatan, Kendala & Solusi, RTL berikutnya, bukti Evaluasi RTL, hapus blok, hapus bukti Kegiatan — request yang dimanipulasi bisa menimpa/menghapus data IKU lain | Semua id harus id yang dimuat server untuk IKU+periode formulir (disimpan `#[Locked]`); status kunci dibaca dari database, bukan dari browser; pelanggaran 403 + tercatat | `OtorisasiFinalisasiTest` |
| Baru | Penggantian bukti tidak atomik | Buat berkas baru + catatan + hapus baris lama + log dalam satu transaksi dengan baris lama dikunci; unggah setelah commit | `DriveKonsistensiBuktiTest` |
| Baru | Format angka besar salah digit (float) | Pembulatan desimal berbasis teks, eksak | `FormatAngkaKolomAuditTest` |

Belum dikerjakan: **U-10** (log untuk Kegiatan, Kendala & Solusi, RTL, bukti Kegiatan) — tahap 2, di luar cakupan.

## 3. Fitur yang dibuat

1. **Halaman Log Aktivitas** — tab 📜 di Data Master & Konfigurasi (`LogAktivitasViewer`, dimuat *lazy*). Hanya Tim SAKIP (dicek di rute dan di setiap request). Filter tanggal (WITA), pelaku, jenis tindakan, IKU, periode (tahun/bulan), bagian, hasil; tombol "lihat peristiwa lain dalam proses simpan yang sama". Terbaru di atas, 25 baris per halaman (`simplePaginate`, tanpa `COUNT(*)`). Rincian sebelum/sesudah per kolom; `path`, `drive_file_id`, `storage_account_id`, dan kunci bernama rahasia tidak pernah ditampilkan. Tidak ada aksi ubah/hapus. Kepala belum diberi akses.
2. **`bukti:periksa-konsistensi [--drive] [--batas-drive=200]`** — BACA-SAJA. Melaporkan: berkas tanpa salinan (Drive kosong & lokal hilang), berkas menunggu sinkron, berkas yang pemiliknya hilang, berkas lokal tanpa baris DB, dan (opsi `--drive`) salinan Drive yang hilang/di Sampah serta berkas Drive bertanda tanpa padanan DB. Terbukti tidak menulis ke DB maupun disk.
3. **`bukti:bersihkan-lokal-yatim [--jalankan] [--umur-jam=24]`** — terpisah dari pemeriksa. Bawaan hanya laporan. Hanya folder bukti, hanya berkas lebih tua dari batas umur, rujukan dicek ulang tepat sebelum menghapus, tidak pernah menyentuh DB maupun Drive, ringkasan tindakan dicatat di log.
4. **Penanda berkas Drive** (`appProperties`: kode berkas) + pemakaian ulang salinan saat percobaan ulang — sinkron susulan tidak menggandakan berkas yang sebenarnya sudah terunggah.
5. **Peringatan bentrok + "Muat data terbaru"** di Isian Kegiatan; pesan bentrok di pengaturan Bagian Kustom; tombol Aktif/Nonaktif mengirim keadaan tujuan (tidak membalik keputusan Tim SAKIP lain).

## 4. Daftar berkas dan migrasi

**Diubah:** `.gitignore`, `app/Http/Middleware/EnsureUserHasRole.php`, `app/Livewire/{BagianKustomManager,IsianBagianKustom,PengisianKegiatan,TargetTahunan,VerifikasiCapaian}.php`, `app/Models/{BagianKustom,BagianKustomPoin,Berkas,RincianN}.php`, `app/Providers/AppServiceProvider.php`, `app/Services/{FolderStructureService,GoogleDriveService,NotulaBagian1DocxService}.php`, `app/Support/TemplateBagianKustom.php`, Blade `bagian-kustom-manager`, `isian-bagian-kustom`, `pengisian-kegiatan`, `target-tahunan`, `verifikasi-capaian`, `master-iku/index`, `tests/Feature/TargetTahunanTest.php`.

**Baru (aplikasi):** `app/Console/Commands/{PeriksaKonsistensiBukti,BersihkanBuktiLokalYatim}Command.php`, `app/Exceptions/KonflikPenyuntinganException.php`, `app/Livewire/LogAktivitasViewer.php`, `app/Models/{LogAktivitas,RiwayatKonfigurasiBagianKustom}.php`, `app/Support/{FormatAngkaKolom,JejakAktivitasBagianKustom,KonsistensiBukti}.php`, Blade `log-aktivitas-viewer`, `partials/_petunjuk-kolom-angka`.

**Baru (test):** `OtorisasiFinalisasiTest`, `KonflikPenyuntinganTest`, `RiwayatKonfigurasiTransaksiTest`, `DriveKonsistensiBuktiTest`, `LogAktivitasViewerTest`, `MigrasiFinalisasiTest`, `Unit/FormatAngkaKolomAuditTest` (+ berkas test sesi sebelumnya: `BagianKustomIsolasiTest`, `BagianKustomPusatKonfigurasiTest`, `LogAktivitasBagianKustomTest`, `MigrasiKompatibilitasBagianKustomTest`, `RincianTambahanNTest`, `Unit/FormatAngkaKolomTest`, `tests/PenjagaDatabaseUji.php`).

**Migrasi pending di produksi (terverifikasi di salinan skema produksi, §6.3):**

| Migrasi | Isi | Data produksi terdampak |
|---|---|---|
| `2026_10_14_000001_konfigurasi_lengkap_bagian_kustom` | kolom baru di `bagian_kustom`, `nilai_kolom` di poin | `bagian_kustom` 0 baris, poin 0 baris |
| `2026_10_15_000001_bagian_kustom_peran_dan_penempatan_menu` | `peran`/`penempatan` JSON, isi dari `peran_pengisi`, lalu buang `peran_pengisi` | 0 baris |
| `2026_10_21_000001_tambah_sumber_dan_triwulan_mulai_ke_rincian_n` | 4 kolom baru (default `target` / 1) | `rincian_n` 34 baris — nilai lama tidak diubah, hanya mendapat default |
| `2026_10_21_000002_tambah_konteks_pengisi_ke_bagian_kustom_poin` | 4 kolom nullable + FK `users` | 0 baris |
| `2026_10_22_000001_create_riwayat_konfigurasi_bagian_kustom_table` | tabel baru (+ `nama_pengguna`) | — |
| `2026_10_22_100001_buat_tabel_log_aktivitas` | tabel baru + 8 indeks | — |
| `2026_10_22_100002_lindungi_log_aktivitas_dari_ubah_hapus` | fungsi + 2 trigger (PostgreSQL), 2 trigger (SQLite) | — |

Migrasi `000001` dan `100001` tanggal 22 Okt **diubah** (tambah `nama_pengguna`, tambah 2 indeks) — aman karena belum pernah dijalankan di produksi (terverifikasi).

## 5. Hasil test aktual

Semua dijalankan **berurutan** (tidak paralel) hari ini.

| Putaran | Hasil |
|---|---|
| Baseline sebelum perubahan, SQLite | 724 lulus, 0 gagal, 2.867 asersi |
| Akhir, SQLite in-memory | **782 lulus, 0 gagal, 0 dilewati, 3.252 asersi** |
| Akhir, PostgreSQL 18.3 uji lokal | **782 lulus, 0 gagal, 0 dilewati, 3.554 asersi** |

Test baru (58) per kelompok, lulus di kedua database:

| Kelompok | Berkas | Jumlah |
|---|---|---|
| Keamanan & otorisasi (termasuk uji HTTP Livewire sungguhan, id manipulasi, U-6, U-7, kebebasan IKU) | `OtorisasiFinalisasiTest` | 12 |
| Konflik penyuntingan (Isian Kegiatan & pengaturan) | `KonflikPenyuntinganTest` | 9 |
| Transaksi riwayat konfigurasi (berhasil, rollback, gagal catat, retry) | `RiwayatKonfigurasiTransaksiTest` | 5 |
| Google Drive (transaksi gagal, Drive gagal, catat gagal, retry tanpa dobel, ganti, hapus, pemeriksa, pembersih) | `DriveKonsistensiBuktiTest` | 12 |
| Halaman baca log (akses, filter, paginasi, WITA, penyembunyian, hanya baca, peredam, indeks) | `LogAktivitasViewerTest` | 8 |
| Format angka notula (kosong, nol, negatif, besar, pembulatan, satuan, nilai tersimpan) | `Unit/FormatAngkaKolomAuditTest` | 9 |
| Migrasi & rollback (trigger, turun-naik semua migrasi rilis, data lama utuh) | `MigrasiFinalisasiTest` | 3 |

Test lama yang **diubah** karena perilaku sengaja berubah (3): `LogAktivitasBagianKustomTest` — query langsung ke log kini ditolak trigger; penolakan peran di Verifikasi kini dicatat di `boot()` sebagai `akses.ditolak`; log unggah kini dua tahap (`bukti.diunggah` → `bukti.tersinkron`).

Regresi area lama (bagian dari 782): notula Bagian I/II/III & template Word/PDF, Rincian N & Target Tahunan, pengisian & verifikasi, unggah bukti, autentikasi — semuanya lulus tanpa perubahan test.

Tidak dapat diuji otomatis: Google Drive API sungguhan (di-*mock*), Supabase pooler, dua proses aplikasi yang menyimpan bersamaan (yang diuji: primitif kunci baris PostgreSQL dengan dua koneksi nyata, §6.2, dan logika bentrok aplikasi dalam satu proses).

## 6. PostgreSQL & Supabase

### 6.1 Fakta produksi (baca-saja, hari ini)
- Server **PostgreSQL 17.6** (Supabase). Pengguna aplikasi `postgres`: **bukan superuser**, tetapi **pemilik** tabel → boleh membuat fungsi & trigger di skema `public`.
- `search_path` = `"$user", public, extensions`. Fungsi trigger diberi `SET search_path = ''` (sesuai saran linter keamanan Supabase).
- Belum ada trigger buatan pengguna di `public`. Ukuran DB 15 MB; `berkas` 405, `kegiatan` 146, `rincian_n` 34, `capaian` 37, `users` 15 baris.
- `log_aktivitas` dan `riwayat_konfigurasi_bagian_kustom` belum ada.

### 6.2 Uji di PostgreSQL 18.3 lokal (klaster pribadi 127.0.0.1:55432, trust, tanpa data produksi)
- Seluruh suite (§5) + bukti: database uji berisi 106 migrasi dan kedua trigger.
- **Penguncian baris antar dua koneksi sungguhan**: koneksi kedua tertahan `FOR UPDATE` (SQLSTATE 55P03 pada `lock_timeout` 1 dtk) selama koneksi pertama belum commit, lalu mendeteksi bentrok setelahnya.

### 6.3 Gladi migrasi di salinan **skema produksi**
`pg_dump --schema-only` produksi (baca-saja) → dipulihkan ke klaster lokal + 102 baris `migrations` produksi → `migrate:status`: **7 pending** (daftar §4) → `migrate` sukses → `migrate:rollback --step=7` sukses → `migrate` lagi sukses (109 migrasi, 2 trigger). Catatan: ini membuktikan DDL pada struktur produksi, **bukan** perilaku Supabase pooler, RLS, atau hak akses peran Supabase lain.

### 6.4 Yang belum terbukti untuk Supabase
- Perilaku lewat pooler Supabase saat beban nyata (transaction vs session mode).
- Gladi dengan **data** produksi (sengaja tidak disalin: berisi data pribadi). Volume kecil (15 MB) sehingga risiko durasi rendah.
- Gladi ulang wajib dilakukan setelah integrasi `origin/main` (§1), karena daftar pending akan berubah.

## 7. Backup dan pemulihan

### 7.1 Gladi (lokal, terverifikasi)
Database gladi berisi data contoh → `pg_dump --no-owner --no-privileges --format=plain --schema=public` (opsi sama dengan fitur Backup aplikasi) → dipulihkan ke database baru → **jumlah baris semua tabel identik**, hash isi `bagian_kustom_poin`, `log_aktivitas`, `berkas`, `riwayat_konfigurasi_bagian_kustom`, `kegiatan` identik, trigger log ikut pulih dan aktif.

**Temuan prosedur:** dump `--schema=public` memuat `CREATE SCHEMA public`, sehingga pemulihan ke database baru **gagal** (`schema "public" already exists`) bila memakai `ON_ERROR_STOP`. Prosedur yang benar untuk database pemulihan **baru/kosong**: `DROP SCHEMA public CASCADE;` lalu `psql -v ON_ERROR_STOP=1 -f backup.sql`. **Jangan** menjalankan `DROP SCHEMA` di database Supabase produksi yang sedang dipakai.

### 7.2 Status untuk produksi
- **Backup produksi belum dibuat** (di luar izin). Fitur Backup aplikasi di `origin/main` sudah memakai pg_dump 17 (cocok dengan server 17.6); di cabang ini belum.
- Fitur backup Supabase (harian/PITR) bergantung paket — **perlu dicek di dasbor** sebelum rilis.

## 8. Strategi commit

Fakta dependensi: di `PengisianKegiatan`, `IsianBagianKustom`, dan `VerifikasiCapaian`, kode F-01–F-04, format angka, log, bentrok, dan Drive berada di hunk yang sama dan saling memanggil. Memecah dengan `git add -p` menghasilkan commit perantara yang tidak bisa dijalankan.

Urutan yang bisa dijalankan per commit (dibangun di worktree terpisah, test per commit):
1. **Rincian N** — `RincianN`, `TargetTahunan` + Blade + test, migrasi `2026_10_21_000001`, hunk Rincian N di `VerifikasiCapaian` + Blade + `NotulaBagian1DocxService` [5][6], `RincianTambahanNTest`.
2. **Format angka & pusat konfigurasi** — `FormatAngkaKolom`, `BagianKustom`, `TemplateBagianKustom`, `RiwayatKonfigurasiBagianKustom` + migrasi `000001`, partial `_petunjuk-kolom-angka`, `nilaiKolomTampil` di notula, test format (dipisah sesuai permintaan §E; commit ini mencakup perbaikan pembulatan eksak).
3. **Bagian Kustom: otorisasi, isolasi konteks, bentrok, transaksi riwayat** — `BagianKustomPoin`, migrasi `2026_10_21_000002`, `IsianBagianKustom`, `BagianKustomManager`, `PengisianKegiatan`, `VerifikasiCapaian` (boot), `EnsureUserHasRole`, `AppServiceProvider`, `KonflikPenyuntinganException`, test isolasi/otorisasi/bentrok/riwayat.
4. **Log aktivitas + halaman baca + perlindungan DB** — `LogAktivitas`, `JejakAktivitasBagianKustom`, `LogAktivitasViewer` + Blade + tab, migrasi `100001`/`100002`, test log/viewer/migrasi, `PenjagaDatabaseUji`.
5. **Google Drive** — `Berkas`, `FolderStructureService`, `GoogleDriveService`, `KonsistensiBukti`, 2 perintah, `DriveKonsistensiBuktiTest`.
6. **Dokumen & `.gitignore`** — `_docs/audit/*.md`, `.gitignore`.

Karena commit 3–5 menyentuh `PengisianKegiatan` yang sama, praktisnya 3+4+5 lebih aman digabung menjadi **satu commit** "Bagian Kustom: otorisasi, bentrok, log, Drive" kecuali Anda menghendaki pemisahan manual per hunk (perlu uji per commit). **Tidak di-commit:** `.claude/` (sekarang diabaikan `.gitignore`), `_docs/template notula.docx` (metadata penulis = nama pengguna; putuskan terpisah).

Pengamanan Git yang sudah dilakukan: `refs/cadangan/pagi-20261022` → `cc4d049` (keadaan pagi), `refs/cadangan/sebelum-finalisasi-20261022` → `6420c4a` (keadaan sebelum pekerjaan ini); salinan berkas untracked ada di scratchpad sesi. Pemindaian kredensial pada 55 berkas: 0 temuan.

## 9. Risiko residual

1. **Integrasi `origin/main` belum dilakukan** (§1) — penghalang rilis.
2. Pemilik tabel / admin DB (termasuk siapa pun yang memegang kredensial `postgres` aplikasi) tetap bisa menonaktifkan/menghapus trigger lalu mengubah log. Perlindungan ini menahan kesalahan kode dan penyalahgunaan lewat aplikasi, bukan penyalahgunaan kredensial. Mitigasi lanjutan (opsional): peran DB aplikasi terpisah tanpa kepemilikan tabel, ekspor log berkala.
3. Penanda `appProperties` Drive hanya terlihat oleh proyek Google Cloud yang sama; berkas yang diunggah sebelum fitur ini tidak bertanda sehingga tidak terdeteksi sebagai "Drive yatim".
4. Bila unggah Drive berhasil tetapi pencatatan hasil gagal dan penanda tidak terbaca, sinkron susulan bisa mengunggah salinan kedua (terdeteksi pemeriksa sebagai "salinan ganda").
5. Penolakan peran saat **membuka** halaman (GET) tidak dicatat di log (hanya aksi pada halaman terbuka) — disengaja agar log tidak penuh salah klik menu.
6. U-10 (log untuk Kegiatan/Kendala/RTL) belum ada.
7. Bentrok pada bagian mana pun membatalkan simpan seluruh formulir (sesuai permintaan); pengguna perlu memuat ulang bagian yang bentrok.
8. `RtlEvaluasi` (komponen lama) masih menerima id poin tanpa cek kepemilikan — tidak diubah karena di luar alur Isian Kegiatan; perlu dicek apakah masih dipakai.

## 10. Langkah rilis produksi (setelah integrasi disetujui & lulus)

1. Integrasikan ke `origin/main` (§1), jalankan suite SQLite lalu PostgreSQL berurutan, gladi ulang §6.3 dengan kode hasil integrasi.
2. Jadwalkan jam sepi; umumkan pembekuan isian ±15 menit.
3. **Backup** sebelum deploy: (a) cek backup Supabase di dasbor; (b) Backup Database dari aplikasi (pg_dump 17) dan unduh; (c) catat `migrate:status` (baca-saja).
4. Deploy. Container menjalankan `php artisan migrate --force` otomatis sebelum melayani; bila migrasi gagal, deploy gagal dan versi lama tetap melayani (`set -e`).
5. Verifikasi pascadeploy:
   - Bagian Kustom: Tim SAKIP buat bagian uji, atur kolom angka berformat, cek tab Riwayat.
   - Isian Kegiatan (Ketua Tim): simpan draf dengan poin + bukti → bukti tersalin ke Drive.
   - Verifikasi (Tim SAKIP): tandai poin Sesuai/Tidak Sesuai.
   - Log: tab 📜 menampilkan peristiwa di atas; Kepala/Ketua Tim mendapat 403.
   - Drive: `php artisan bukti:periksa-konsistensi` (tanpa `--drive` dulu), lalu dengan `--drive --batas-drive=50`.
   - Notula Bagian I (Word & PDF) dan Rincian N untuk satu IKU dengan data nyata.
   - Trigger: `SELECT tgname FROM pg_trigger WHERE tgrelid = 'log_aktivitas'::regclass AND NOT tgisinternal;` → 2 baris.
6. **Pemulihan bila ada masalah:**
   - Kode: deploy ulang commit sebelumnya. Semua migrasi rilis aditif, jadi kode lama tetap berjalan di atas skema baru — **tidak perlu** rollback skema untuk mengembalikan layanan.
   - Skema (hanya bila perlu): `migrate:rollback --step=7` — terbukti di salinan skema produksi. Data `log_aktivitas` dan riwayat ikut terhapus (ekspor dulu); kolom baru `rincian_n` ikut terhapus.
   - Data: pulihkan backup langkah 3 ke database baru dengan prosedur §7.1, atau gunakan pemulihan Supabase.

## 11. Asumsi yang diambil
- Bentrok membatalkan **seluruh** simpan formulir (pilihan paling aman, sesuai permintaan §B.2).
- Penolakan identik diredam 60 detik per pelaku (cegah banjir log; tidak mengurangi bukti karena keterangan/objek berbeda tetap dicatat).
- Kepala belum diberi akses halaman log.
- Tidak menyalin data produksi ke lokal (data pribadi); gladi memakai skema produksi + data contoh.
