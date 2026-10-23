# Audit Pra-Rilis Bagian Kustom & Log Aktivitas — 22 Oktober 2026 (tindak lanjut)

- **Cabang:** `susun-ulang-pertahankan-suntingan`, HEAD `19ff82d`. **Tidak ada staging, commit, push, deploy, migrasi produksi, atau perubahan data produksi.**
- `.env` lokal menunjuk ke database **produksi** (Supabase pgsql). Tidak ada perintah artisan yang dijalankan dengan `.env` itu. Semua test memakai SQLite in-memory atau klaster PostgreSQL uji pribadi (lihat §4), dijaga trait `Tests\PenjagaDatabaseUji`.

**Tanda:** **[B]** terbukti lewat test/perintah yang dijalankan hari ini · **[K]** dibaca dari kode, belum diuji · **[D]** dugaan · **[R]** rekomendasi · **[T]** sudah diterapkan · **[X]** belum dilakukan.

---

## 1. Kondisi Git dan daftar perubahan

### 1.1 Status
- 16 file tracked termodifikasi (unstaged), 0 staged, 19 entri untracked. **[B]**
- Pemindaian pola kredensial (password, token, api key, private key, host Supabase) pada `git diff` dan seluruh file untracked teks: **tidak ada temuan**. **[B]**
- `.env` di-`.gitignore`. **[B]**

### 1.2 Stash `cc4d049`
- Objek commit stash yang dibuat dengan `git stash create` pagi ini. **Tidak** terdaftar di `git stash list`, jadi bisa dihapus otomatis oleh `git gc` (umumnya setelah ±2 minggu). **[B]**
- Isinya = semua file **tracked** pagi ini, yaitu F-01–F-04 + Rincian N (11 file, +750/−85). File untracked pagi ini (migrasi `2026_10_21_000001/000002`, `BagianKustomIsolasiTest.php`, `RincianTambahanNTest.php`) tidak ada di stash. Salinannya ada di `…\scratchpad\cadangan-20261022\` dan masih **identik byte-per-byte** dengan file sekarang (hash). **[B]**
- `git grep` di `cc4d049`: tidak ada rujukan ke `FormatAngkaKolom`, `kolomBerlaku`, `nilaiKolomTampil`, `LogAktivitas`. Jadi kondisi pagi itu berdiri sendiri; kondisi itu lulus 663 test. **[B]**
- [R] Jangan dihapus. Agar tidak hilang oleh `gc`, buat ref: `git update-ref refs/cadangan/pagi-20261022 cc4d049` (perlu persetujuan; ini menambah ref di repo).

### 1.3 File yang perlu diwaspadai
| File | Isi | Rekomendasi |
|---|---|---|
| `.claude/settings.json`, `.claude/settings.local.json` | izin alat lokal, termasuk `git add -A` dan `git push origin main` | **Jangan di-commit**; tambahkan `.claude/settings.local.json` ke `.gitignore` [R]. Izin `git add -A` berisiko menyertakan semua file untracked |
| `_docs/template notula.docx` | hanya placeholder `{{…}}`, tanpa angka nyata; metadata penulis = nama pengguna | Di luar ruang lingkup; putuskan terpisah |
| `_docs/audit/*.md` | laporan audit | opsional |

### 1.4 Kelompok perubahan
| # | Kelompok | File / hunk |
|---|---|---|
| 1 | Keamanan & otorisasi Bagian Kustom (F-01–F-04 + cek peran penandaan) | `BagianKustomPoin.php`; `IsianBagianKustom.php` [2][3][4][5][9] + bagian F-02 di [7][8]; `PengisianKegiatan.php` [2][3][5-awal][7][8][9][12][15] + bagian F-03 di [13]; `BagianKustomManager.php` `boot()`, `jumlahPoinMenungguVerifikasi()`, `konteksDilepasBerisi()`; `VerifikasiCapaian.php` [12] + `pastikanTimSakipUntukBagianKustom()` di [13] |
| 2 | Log aktivitas | `LogAktivitas.php`, `JejakAktivitasBagianKustom.php`; baris log di `IsianBagianKustom` [1][7][8], `PengisianKegiatan` [1][4][5-akhir][6][13][14], `VerifikasiCapaian` [1][13][14][15] |
| 3 | Migrasi & skema | `2026_10_21_000001` (Rincian N), `2026_10_21_000002` (konteks poin), `2026_10_22_000001` (riwayat konfigurasi, sesi lain), `2026_10_22_100001` (log) |
| 4 | Test | `BagianKustomIsolasiTest` (+penjaga DB), `LogAktivitasBagianKustomTest` (23), `MigrasiKompatibilitasBagianKustomTest` (4), `tests/PenjagaDatabaseUji.php`, `BagianKustomPusatKonfigurasiTest`, `FormatAngkaKolomTest`, `RincianTambahanNTest`, `TargetTahunanTest` |
| 5 | Rincian N | `RincianN.php`, `TargetTahunan.php`, `target-tahunan.blade.php`, `VerifikasiCapaian.php` [2]–[11][16]–[18], `verifikasi-capaian.blade.php` (kecuali baris `nilaiTampil`), `NotulaBagian1DocxService.php` [5][6] |
| 6 | Format angka notula & pusat konfigurasi (sesi lain) | `BagianKustom.php`, `FormatAngkaKolom.php`, `TemplateBagianKustom.php`, `RiwayatKonfigurasiBagianKustom.php`, `bagian-kustom-manager.blade.php`, partial `_petunjuk-kolom-angka`, `nilaiTampil`/`kolomBerlaku` di 3 Blade, `NotulaBagian1DocxService.php` [1][2][3], `IsianBagianKustom` [6] + argumen baru `bersihkanNilaiKolom()` di [8], `PengisianKegiatan` [10][11] + argumen baru di [13], salin pengaturan & riwayat di `BagianKustomManager` |
| 7 | Lain-lain | `NotulaBagian1DocxService` [4] (spasi), `VerifikasiCapaian` [16][17] (rapikan nama kelas penuh), `.claude/`, template docx |

(Nomor `[n]` = urutan hunk `git diff` dengan konteks bawaan, dipetakan per 22 Okt.)

---

## 2. Temuan yang sudah diperbaiki **[T][B]**
1. **F-01–F-04** (sesi sebelumnya), tetap terbukti oleh `BagianKustomIsolasiTest` di SQLite **dan** PostgreSQL.
2. **Peran dicabut di halaman Verifikasi:** penandaan poin Bagian Kustom oleh pengguna yang sudah bukan Tim SAKIP sebelumnya berhasil (penjajak). Sekarang ditolak 403, tercatat `ditolak`, status poin dan Capaian tidak berubah.
3. **Log aktivitas isian Bagian Kustom** (poin, bukti, penandaan) — §5.
4. **Penjaga database uji** pada test baru dan test isolasi (menolak koneksi selain SQLite memory atau PG uji lokal).

## 3. Temuan yang belum diperbaiki
| # | Temuan | Bukti | Usulan minimal |
|---|---|---|---|
| U-1 | Dua Ketua Tim menyunting poin Bagian Kustom yang sama di Isian Kegiatan → versi pertama **tertimpa diam-diam**, tanpa galat | **[B]** penjajak: teks akhir "Versi B", galat B kosong. Log tetap menunjukkan B mengubah dari "Versi A" | §6 |
| U-2 | Riwayat konfigurasi ditulis **di luar transaksi** → bila penulisan riwayat gagal, konfigurasi tetap berubah tanpa jejak | **[B]** penjajak: nama bagian berubah, 0 riwayat | §6 |
| U-3 | Unggah bukti di dalam transaksi DB → bila transaksi batal setelah unggah, berkas sudah ada di Drive dan file lokal tertinggal tanpa baris DB maupun log | **[B]** penjajak: 1 unggahan Drive, 0 berkas DB, 0 log, 1 file lokal | §5.3 |
| U-4 | Larangan ubah/hapus log hanya di model Eloquent; query langsung tidak tertahan | **[B]** test: `DB::table('log_aktivitas')->update()` berhasil | §5.2 |
| U-5 | Status Drive di log menjadi basi setelah `berkas:sinkron-drive` berhasil menyusulkan berkas (log tetap "BELUM tersalin", tidak ada catatan susulan) | **[K]** | tambah log `bukti.tersinkron` di `Berkas::sinkronUlangKeDrive()` |
| U-6 | Penonaktifan bagian yang poinnya masih menunggu verifikasi tidak dicegah | **[K]** | samakan dengan aturan lepas menu |
| U-7 | `Periode::firstOrCreate()` berjalan sebelum validasi id poin → permintaan yang ditolak bisa membuat baris periode | **[K]** | pindah validasi sebelum `firstOrCreate` |
| U-8 | Halaman Verifikasi secara umum (selain penandaan Bagian Kustom) belum memeriksa peran di setiap aksi (temuan audit 21 Okt #3) | **[K]** | `boot()` cek Tim SAKIP — perlu uji regresi luas |
| U-9 | Penolakan berulang yang disengaja bisa membanjiri `log_aktivitas` | **[D]** | batasi laju / gabungkan percobaan identik per menit |
| U-10 | Aktivitas Kegiatan, Kendala & Solusi, RTL, dan bukti Kegiatan belum masuk log (hanya status Capaian) | **[K]** | tahap 2, terpisah |

---

## 4. Hasil test dan bukti PostgreSQL

### 4.1 Lingkungan PostgreSQL uji **[B]**
- Layanan Postgres sistem (`postgresql-x64-18`) **tidak dipakai** (kata sandinya tidak diketahui dan tidak ditebak).
- Dibuat **klaster pribadi** dengan biner yang sama: `initdb` di `…\scratchpad\pg-uji\data`, PostgreSQL **18.3**, hanya `127.0.0.1:55432`, auth `trust` lokal, database `sipinter_uji`, tanpa data produksi.
- Konfigurasi PHPUnit terpisah: `…\scratchpad\pg-uji\phpunit.pgsql.xml` (semua variabel DB `force="true"`, `DB_URL` dikosongkan).
- Bukti test benar-benar di Postgres: setelah run, `sipinter_uji` berisi 105 baris `migrations` dan tabel `log_aktivitas`.
- Klaster dihentikan setelah selesai. Menyalakan lagi: `pg_ctl -D <scratchpad>\pg-uji\data -l <scratchpad>\pg-uji\pg.log start`.
- **Keterbatasan:** produksi memakai Supabase (versi server dan pooler belum diverifikasi). Uji ini membuktikan kompatibilitas dengan PostgreSQL 18.3 standar, **bukan** dengan Supabase pooler.

### 4.2 Hasil (dijalankan hari ini)
| Run | Hasil |
|---|---|
| Baseline pagi (sebelum perubahan) | 663 lulus |
| SQLite — suite penuh akhir, dijalankan sendiri | **724 lulus, 0 gagal, 0 error, 0 dilewati** (2.867 asersi) |
| PostgreSQL 18.3 — suite penuh akhir, dijalankan sendiri | **724 lulus, 0 gagal, 0 error, 0 dilewati** (3.022 asersi) |
| PostgreSQL — file Bagian Kustom/log/isolasi saja | 103 lulus |
| SQLite & PG dijalankan **bersamaan** | masing-masing **1 galat** di test unggahan (`PengisianKegiatanTest::test_bukti_evaluasi_rtl_lengkap…`: respons Livewire bukan JSON; `TahapanGandaDanSubfolderTest::test_tahapan_buatan_sendiri…`: nama berkas berisi byte `0x96`). Keduanya **lulus** saat run berurutan dan saat dijalankan sendiri. [D] Penyebab: dua proses berbagi folder unggahan sementara Livewire/`Storage::fake`. **Jangan jalankan dua suite paralel** di mesin yang sama |

Per area (kelompok bisa tumpang tindih; sama di SQLite dan PG, 0 gagal):
- Bagian Kustom/dinamis/log/migrasi: 105
- Format angka & riwayat konfigurasi: 34
- Pengisian & verifikasi: 245
- Hak akses: 12
- Bukti & Drive: 89
- Notula I/II/III: 154
- Rincian N & Target Tahunan: 19

### 4.3 Skema & migrasi di PostgreSQL **[B]** (`MigrasiKompatibilitasBagianKustomTest`)
- `2026_10_21_000002` turun-naik: poin lama identik di semua kolom, kolom baru NULL (tanpa backfill), dibaca sebagai Isian Kegiatan, tidak ganda.
- `2026_10_22_100001` turun-naik: hanya menambah/menghapus `log_aktivitas`, tabel lain tidak berubah.
- Tipe di PG: `user_id int8`, `created_at timestamp DEFAULT CURRENT_TIMESTAMP`, `perubahan json`, `keterangan text`. Tidak ada `updated_at`. `aksi`/`keterangan` NOT NULL. 6 indeks ada.
- `log_aktivitas` **tanpa foreign key** (sesuai rancangan).
- FK `bagian_kustom_poin.diisi_oleh/diubah_oleh → users ON DELETE SET NULL`. Menghapus pengguna: kolom poin menjadi NULL, isi poin utuh, `log_aktivitas.user_id` dan `nama_pengguna` **tidak berubah**.
- Transaksi PG: log berhasil ikut batal saat transaksi gagal; bentrok `lockForUpdate` + sidik jari bekerja (dalam satu proses uji).
- **Belum diuji:** penguncian baris sungguhan antara dua koneksi PG paralel; migrasi `2026_10_14/15` di data Supabase nyata.

---

## 5. Integritas log dan Google Drive

### 5.1 Daftar periksa log (Tahap 3)
| # | Butir | Hasil |
|---|---|---|
| 1–2 | Pelaku dari `auth()`; `user_id`/`nama_pengguna`/`peran`/`status` dari pemanggil diabaikan (whitelist konteks) | **[B]** |
| 3 | Catatan berhasil ditulis di transaksi bisnis yang sama | **[B]** (galat tiruan: 0 poin, 0 log) |
| 4 | Penolakan dicatat sebelum 403, tanpa perubahan data; alasan berupa teks tetap, tanpa detail teknis | **[B]** |
| 5 | Log gagal ditulis → penyimpanan batal (bukan sukses palsu); log penolakan gagal → data tetap utuh (pengguna mendapat galat 500, bukan 403) | **[B]** 2 test baru |
| 6 | Sebelum/sesudah: ubah (IKU, uraian, kolom), hapus (isi poin), ganti bukti (berkas lama & baru), hapus bukti, penandaan (status & catatan) | **[B]** |
| 7 | Tidak menyimpan kata sandi, token, kredensial; `drive_file_id`/URL Drive tidak dicatat; kunci rahasia dibuang; teks dipotong 500 | **[B]**. Catatan: isi uraian yang diketik pengguna ikut tercatat (data bisnis) |
| 8 | Nama bagian, IKU, periode disalin saat kejadian | **[B]** |
| 9 | Simpan ulang / tandai ulang identik → tidak ada log baru; request ulang dengan snapshot lama → terdeteksi bentrok (`ditolak`), bukan sukses ganda | **[B]** / [K] |
| 10 | Model menolak ubah/hapus; **query langsung tidak tertahan** | **[B]** → U-4 |
| 11 | Penandaan setelah peran dicabut → ditolak & tercatat | **[B]** |
| 12 | Belum ada jalur baca log; korelasi per proses; tidak ada state log yang dibagi antar-komponen | **[K]** — uji akses wajib dibuat bersama halaman baca |

### 5.2 Model ancaman log
| Ancaman | Perlindungan sekarang | Usulan |
|---|---|---|
| Pengguna aplikasi memalsukan/mengubah log lewat UI/request | tidak ada jalur tulis/ubah dari request; pelaku dari server | — |
| Bug kode memanggil `->update()`/`->delete()` model | LogicException di model | — |
| Kode memakai query builder / `DB::table` | **tidak ada** | [R] Postgres: trigger `BEFORE UPDATE OR DELETE ON log_aktivitas` yang menolak, dibuat lewat migrasi khusus pgsql |
| Admin DB / kredensial produksi bocor | **tidak ada** | [R] role DB aplikasi hanya `INSERT, SELECT` pada `log_aktivitas`; ekspor berkala |

### 5.3 Google Drive — kondisi parsial (Tahap 4)
| Alur | Kondisi parsial | Status | Pemulihan yang ada | Usulan |
|---|---|---|---|---|
| Unggah (di dalam transaksi DB) | Drive sukses, transaksi batal → berkas Drive + file lokal yatim, tanpa baris DB/log | **[B]** | tidak ada | [R] unggah **setelah commit** (`DB::afterCommit`), atau catat rencana unggah di tabel jurnal |
| Unggah | Drive gagal → baris DB tanpa `drive_file_id`; log "BELUM tersalin" | [B] | `berkas:sinkron-drive` (tiap 5 menit) | [R] log `bukti.tersinkron` saat susulan berhasil (U-5) |
| Unggah | Drive gagal lalu container di-deploy ulang sebelum sinkron → file lokal hilang; `sinkronUlangKeDrive()` diam-diam `false` | [K] | tidak ada | [R] laporan berkas "tidak bisa dipulihkan" |
| Ganti (tanpa transaksi) | proses berhenti setelah berkas baru dibuat → dua berkas (lama "Tidak Sesuai" + baru "menunggu"), tanpa log | [K] | sebagian (sinkron) | [R] transaksi untuk langkah DB + log; pola jurnal `PenggantiBerkasAman` milik notula |
| Ganti | file lokal lama terhapus, baris lama gagal dihapus → baris menunjuk file yang hilang (salinan Drive lama masih ada) | [K] | tidak ada | idem |
| Ganti | log gagal setelah penggantian → penggantian tanpa jejak | [K] | — | idem |
| Hapus | DB + log atomik; file lokal dihapus sesudahnya (bisa gagal → file yatim); salinan Drive **sengaja** tidak dihapus | [B]/[K] | — | — |

**Mekanisme pemulihan yang diusulkan [R][X]:** perintah baca-saja `bukti:periksa-konsistensi` (pola `notula:penggantian-berkas`) yang melaporkan:
1. baris `berkas` tanpa Drive yang file lokalnya hilang;
2. file lokal `bukti-*` tanpa baris DB;
3. berkas Drive di folder Bagian Kustom tanpa `drive_file_id` padanan (pakai Drive API `list`, baca saja);
4. pasangan berkas lama + baru pada ref yang sama tanpa log `bukti.diganti`.

Perintah ini **tidak menghapus apa pun**. Tindak lanjut manual oleh Tim SAKIP, dan setiap tindakan pemulihan dicatat di `log_aktivitas`. Penghapusan otomatis berkas yatim **tidak** diusulkan.

---

## 6. Risiko konflik penyuntingan bersamaan (Tahap 5)
- **U-1 (Isian Kegiatan):** [R] sidik jari yang sama dengan Isian Bagian Kustom (`BagianKustomPoin::sidikJari()`):
  - simpan `#[Locked] public array $sidikJariBagianKustom` saat `muatBagianKustomBlocks()`;
  - di `pastikanPoinBagianKustomSah()` dan di dalam transaksi (`lockForUpdate`) bandingkan dengan DB;
  - bila berbeda → batalkan penyimpanan, muat ulang poin, tampilkan "sudah diubah pengguna lain", catat `ditolak`.
  - **Perlu keputusan Anda:** bentrok di satu poin akan membatalkan seluruh simpan formulir (Kegiatan, Kendala, dll.) karena satu transaksi. Alternatifnya hanya menolak bagian kustom. Bagian lain formulir (Kegiatan, Kendala & Solusi) juga last-write-wins [K].
  - Estimasi: 1 file (`PengisianKegiatan.php`, ±40 baris) + 2–3 test.
- **U-2 (riwayat konfigurasi):** [R] di `BagianKustomManager` (`tambah`, `salin`, `simpan`, `toggleAktif`, `naikkan`/`turunkan`, `hapus`):
  - bungkus perubahan + `RiwayatKonfigurasiBagianKustom::catat()` dalam satu `DB::transaction`;
  - ambil potret "sebelum" di dalam transaksi dengan `lockForUpdate()` pada baris bagian, supaya dua Tim SAKIP yang menyimpan bersamaan tidak menghasilkan potret basi.
  - [R] Ganti FK `user_id nullOnDelete` di riwayat konfigurasi dengan salinan nama, seperti `log_aktivitas`.
  - Estimasi: 1 file + 1 migrasi opsional + 2 test.

## 7. Rancangan halaman baca log (Tahap 6) **[X]** belum dibuat
- **Letak:** tab baru "Log Aktivitas" di *Data Master & Konfigurasi* (pola tab yang ada).
- **Akses:** komponen Livewire `LogAktivitasViewer` dengan `boot()` → hanya Tim SAKIP (pola `BagianKustomManager`), baca saja. Perlu diputuskan apakah Kepala ikut melihat.
- **Kolom:** Waktu (WITA, macro `wita()`), Pelaku, Peran, Aktivitas (label ramah), Keterangan, IKU, Periode, Status (badge berhasil/ditolak dengan token warna tema terang/gelap yang ada). Baris dapat dibuka untuk melihat tabel sebelum → sesudah (dari `perubahan`). `korelasi` dipakai untuk "lihat aktivitas lain dalam proses yang sama".
- **Filter:** rentang tanggal / tahun-bulan, pelaku, jenis aktivitas, menu, bagian, IKU, status. Urutan `created_at DESC, id DESC`. Paginasi Livewire 25 baris (`simplePaginate` agar tidak `COUNT(*)` penuh).
- **Penyembunyian:** tidak menampilkan `user_id` mentah, nama path lokal, atau detail teknis penolakan. `id_poin_dikirim` hanya di panel rinci.
- **Riwayat lain:** `riwayat_konfigurasi_bagian_kustom` dan `riwayat_status_capaian` sebaiknya ditampilkan sebagai sub-tab terpisah, bukan UNION, karena kolomnya berbeda dan paginasi gabungan mahal.
- **Indeks:** yang ada mencukupi filter waktu, pengguna, bagian, IKU+periode. Tambahan [R]: `(aksi, created_at)` dan `(status, created_at)` bila filter jenis/status sering dipakai.
- **Volume:** satu simpan = 1 log per poin berubah + 1 per berkas. Untuk satu satker, perkiraan ribuan s.d. puluhan ribu baris/tahun → ringan.
- **Estimasi:** 1 komponen Livewire, 1 Blade, 1 baris tab di `master-iku/index.blade.php`, 1 migrasi indeks opsional, 1 file test (akses per peran termasuk peran dicabut, filter, paginasi, tidak ada data sensitif, tema).

## 8. Pemisahan perubahan dan usulan commit **[X]** (urutan mengikuti dependensi nyata)
**Fakta penting [B]:** di `IsianBagianKustom` dan `PengisianKegiatan`, kode F-xx, format angka, dan log berada dalam hunk yang sama. Kode F-xx saat ini juga memanggil fungsi format angka (`kolomBerlaku`, `pesanGalatKolom`, `bersihkanNilaiKolom(…, $tahun, $tw)`, `nilaiKolomUntukForm`). Memisah dengan `git add -p` saja akan menghasilkan commit perantara yang **tidak bisa dijalankan**.

Usulan (dikerjakan di worktree terpisah agar working tree tidak terganggu, test dijalankan per commit):
1. **Rincian N** — dari `cc4d049`: hunk Rincian N di `VerifikasiCapaian` + Blade, `RincianN`, `TargetTahunan` + Blade + test, hunk [5][6] notula, migrasi `000001`, `RincianTambahanNTest`.
2. **F-01–F-04 + migrasi konteks** — sisa `cc4d049` (`BagianKustomPoin`, `IsianBagianKustom`, `PengisianKegiatan`, `BagianKustomManager` bagian F-04, `bagianKustomList()`) + migrasi `000002` + `BagianKustomIsolasiTest` versi pagi. Kondisi 1+2 = kondisi pagi yang terbukti lulus 663 test.
3. **Pusat konfigurasi / format angka** (sesi lain, perlu keputusan §9) — selisih `cc4d049` → sekarang, dikurangi baris log.
4. **Log aktivitas + cek peran penandaan** — `LogAktivitas`, `JejakAktivitasBagianKustom`, migrasi `100001`, baris log di 3 komponen, `pastikanTimSakipUntukBagianKustom()`, `PenjagaDatabaseUji`, 2 file test baru, penjaga di `BagianKustomIsolasiTest`.
   - Bila commit 3 ditunda, commit 4 harus dibangun di atas commit 2 dengan menyesuaikan baris yang memakai fungsi format angka. Itu pekerjaan tambahan yang perlu diuji ulang.
5. **Dokumen** (opsional) — `_docs/audit/`.
- **Tidak di-commit:** `.claude/`, `_docs/template notula.docx` (sampai diputuskan).

## 9. Perubahan format angka yang perlu diputuskan terpisah
Dibuat sesi lain hari ini; **di luar ruang lingkup** audit ini dan belum diaudit tersendiri:
- `FormatAngkaKolom`;
- pengaturan per kolom (`satuan`, `desimal`, `pemisah`, `ribuan`, `aktif`, `mulai_tahun/triwulan`) di JSON `bagian_kustom.kolom`, tanpa migrasi;
- keluaran notula `nilaiKolomTampil()`;
- tampilan `nilaiTampil()` di Isian Kegiatan dan Verifikasi;
- penyimpanan angka "kanonik" di `bersihkanNilaiKolom()`.

Yang terbukti hanya: 34 test format/riwayat lulus di SQLite & PG, dan seluruh test notula lulus. [D] Kolom tanpa pengaturan tercetak seperti sebelumnya (klaim kode; perlu dibandingkan dengan notula nyata). [R] Audit terpisah: bandingkan dokumen notula Bagian I sebelum/sesudah dari salinan data uji. Periksa juga apakah penyimpanan kanonik mengubah nilai lama saat poin lama disunting ulang.

## 10. Prosedur rilis dan pemulihan produksi **[R]** (menunggu persetujuan)
1. Bekukan isian sebentar (jam sepi), umumkan ke pengguna.
2. Backup penuh DB (`pg_dump` dari Supabase atau fitur Backup aplikasi) dan unduh salinannya. Catat `migrate:status`.
3. Deploy kode **tanpa** menjalankan migrasi otomatis, atau jalankan `php artisan migrate --pretend` dulu untuk meninjau SQL.
4. `php artisan migrate --force`. Urutan pending yang diketahui: 10-14 (2), 10-15 (3), 10-21 (2), 10-22 (2). Di PostgreSQL tiap migrasi berjalan dalam transaksi DDL, jadi bila gagal migrasi itu batal utuh.
5. Uji asap:
   - Isian Bagian Kustom tiap peran;
   - Isian Kegiatan simpan draf;
   - penandaan di Verifikasi;
   - susun notula Bagian I;
   - cek `log_aktivitas` dan `riwayat_konfigurasi` terisi.
6. **Pemulihan:**
   - Kode: rollback deploy.
   - Skema: `migrate:rollback` aman untuk 10-21-000002, 10-22-000001, 10-22-100001 (hanya menghapus kolom/tabel baru; isi log/riwayat ikut hilang, jadi ekspor dulu).
   - 10-15 bisa dibalik, tetapi hanya menyimpan peran pertama.
   - Jalur paling aman: restore backup langkah 2.
7. Setelah rilis: jalankan `berkas:sinkron-drive` normal, pantau log Laravel untuk galat Drive.

## 11. Persetujuan yang masih diperlukan
1. Strategi pemisahan commit §8 (termasuk worktree terpisah) dan izin staging/commit.
2. Keputusan rilis perubahan format angka (§9): ikut, tunda, atau diaudit dulu.
3. Perbaikan U-1 (dan perilaku saat bentrok) serta U-2.
4. Perbaikan Drive U-3/U-5 dan perintah `bukti:periksa-konsistensi` baca-saja.
5. Perlindungan log tingkat DB (trigger / hak akses) — U-4.
6. Pembuatan halaman baca log (§7) dan apakah Kepala ikut melihat.
7. Ref penyelamat `cc4d049` dan pengecualian `.claude/` di `.gitignore`.
8. Migrasi & deploy produksi (§10), serta uji pada salinan/branch database Supabase sebelum produksi.
