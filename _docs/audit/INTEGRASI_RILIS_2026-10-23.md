# Laporan Integrasi Rilis SIPINTER — cabang `integrasi/rilis-bagian-kustom` (23 Oktober 2026)

Dasar persetujuan: "Persetujuan Integrasi SIPINTER ke Produksi — 22 Oktober 2026" (§A–H).
Laporan sebelumnya: `FINALISASI_RILIS_2026-10-22.md`, `AUDIT_PRA_RILIS_BAGIAN_KUSTOM_2026-10-22.md`.

## 0. Ringkasan dan rekomendasi

**Rekomendasi: BELUM SIAP RILIS** — integrasi kode selesai dan lulus seluruh test di SQLite dan
PostgreSQL lokal, tetapi gerbang rilis berikut belum terpenuhi dan memang di luar izin tahap ini:

1. Backup produksi lewat mekanisme resmi belum dibuat (dilarang selama integrasi, §F.6).
2. Kompatibilitas Supabase (pooler, peran, beban nyata) belum terverifikasi di lingkungan target; uji
   hari ini hanya PostgreSQL 18.3 lokal + salinan skema produksi (§6).
3. Ada keputusan perilaku Drive yang perlu Anda konfirmasi (§5.1: hapus satu bukti di produksi =
   hapus **permanen**, bukan ke Sampah).
4. 11 baris `berkas` produksi belum punya salinan Drive; disk lokal kontainer terhapus saat deploy ulang
   (§7.3) — perlu diperiksa sebelum deploy.
5. U-10 tidak termasuk rilis ini (§8).

Tidak dilakukan: push, merge ke `main`, migrasi produksi, deploy, operasi tulis apa pun di produksi.
Akses produksi hanya baca-saja (`SELECT` dalam `BEGIN TRANSACTION READ ONLY … ROLLBACK`, dan
`pg_dump --schema-only`). Kredensial tidak ditulis ke berkas mana pun.

## 1. Keadaan Git

| Hal | Nilai |
|---|---|
| Cabang | `integrasi/rilis-bagian-kustom` (lokal, **tanpa upstream** — tidak melacak `origin/main`) |
| Dasar | `origin/main` `685d7ee` (kode produksi), seluruh 50 commit produksi utuh; ditambah `b2c3d40` (scheduler sebagai www-data, kotak info Backup) yang di-push ke `main` **saat integrasi berjalan** (23 Okt 09:40) — digabung di `348592e` tanpa konflik. Diperiksa ulang setelah test akhir: cabang tidak tertinggal dari `origin/main`. |
| Worktree | `D:\Latsar\sipinter-integrasi` (folder kerja utama `D:\Latsar\sipinter` tidak diubah) |
| Ref cadangan dipertahankan | `refs/cadangan/pagi-20261022` (`cc4d049`), `refs/cadangan/sebelum-finalisasi-20261022` (`6420c4a`) |
| Ref cadangan baru | `refs/cadangan/snapshot-integrasi-20261022` (`8527702`, potret perubahan belum-commit cabang kerja, dibuat lewat index sementara), `refs/cadangan/integrasi-tree-akhir-20261023` (tree yang diuji sebelum dipecah) |
| `main`, `origin/main` | tidak disentuh; tidak ada force-push |

Integrasi dikerjakan dua tahap:
- **Tahap 1** — gabung 15 commit cabang (`19ff82d`) ke `origin/main` → commit gabungan `8f443c7`.
- **Tahap 2** — terapkan potret perubahan belum-commit (3-way, basis `19ff82d`) → dipecah jadi commit
  `67ca217`, `32d460a`, `50b2845`, dan commit dokumen ini.

## 2. Konflik dan keputusan

### 2.1 Tahap 1 — 15 berkas (gabung 15 commit cabang)

Penyebab utama: sebagian commit cabang sudah di-port ke `main` dengan patch berbeda (mis. `6260d08`↔`d5ebef2`,
`8ea6d8b`↔`902713f`, `6da575c`↔`307fdbe`, `e1234ae`↔`3fdaae7`), lalu `main` berkembang lebih jauh. Yang
hanya ada di cabang: `916604c` (Verifikasi satu kartu per kegiatan induk), `19ff82d` (tema gelap navy),
dan bagian Bagian-Kustom dari `d44d935`/`780ba50`/`8641f2a`.

| Berkas | Keputusan | Alasan |
|---|---|---|
| `bootstrap/app.php`, `TokenKedaluwarsaTest` | produksi | `47eb743` menambah pencegahan pantulan Referer ke domain luar; superset `cf32c86` (keamanan) |
| `resources/js/app.js` | produksi | zona unggah seret-lepas (`a3ce582`) |
| `app/Models/Kegiatan.php` | produksi + bersihkan | versi cabang murni lebih lama; **temuan:** `origin/main` memuat baris sisa penanda konflik `<<<<<<< HEAD` di docblock (sejak `902713f`) — dibuang |
| `app/Livewire/PengisianKegiatan.php` | produksi | susunan subfolder `3fdaae7` menggantikan `e1234ae`; kolom Bagian Kustom dari cabang tetap masuk |
| `app/Livewire/VerifikasiCapaian.php` + Blade | cabang + fitur produksi | `916604c` tidak ada di produksi; salinan bukti RTL, riwayat verifikasi berkas, realisasi 5 desimal produksi utuh |
| `NotulaBagian1DocxService.php` | gabungan | penomoran Word produksi (`d263568`) + teks template Bagian Kustom cabang di dalam paragraf butir Word |
| `resources/css/sipinter.css` (11 hunk) | gabungan | palet navy & opacity minimum cabang (lebih baru dari `2bd39d7`); gaya kartu induk, zona unggah, nama berkas panjang produksi |
| `pengisian-kegiatan.blade.php` (12 hunk) | produksi + opacity cabang | kartu induk `1219777`, zona unggah, koreksi scroll `9be0c35`; hint "Lokasi unggah" cabang sengaja tidak dibawa (produksi sudah menggantinya dengan zona unggah) |
| 7 berkas test | produksi, lalu patch test `916604c` & `19ff82d` diterapkan ulang 3-way | test cabang yang mengunci perilaku lama (mis. `uraianTampil()` = nama folder) tidak dibawa |

Hasil tahap 1: SQLite **710 lulus, 0 gagal, 0 dilewati**.

### 2.2 Tahap 2 — perubahan belum-commit

Konflik tekstual: `PengisianKegiatan.php` (18 hunk), `Berkas.php`, `FolderStructureService.php`,
`GoogleDriveService.php`, `NotulaBagian1DocxService.php`. Untuk `PengisianKegiatan.php` git menempatkan hunk
`removeBlock` ke fungsi yang salah (`cobaLagiDrive`), jadi berkas itu diambil dari tahap 1 lalu **setiap**
perubahan potret dipindahkan manual dan disesuaikan ke alur produksi.

| Area | Keputusan |
|---|---|
| Unggah Drive | Perilaku produksi tetap (berkas langsung tersimpan ke Drive begitu dipilih, lewat simpan otomatis). Unggahan dijalankan **setelah commit** di request yang sama, lewat `Berkas::unggahKeDrive()` produksi (bukan jalur unggah cabang). Penyusunan ulang salinan Evaluasi-RTL saat tautan RTL berubah juga dipindah ke setelah commit. |
| Hapus/ganti bukti | Perilaku produksi tetap (§5.1). Urutan langkah disusun ulang agar kegagalan database/log tidak lagi meninggalkan baris yang merujuk berkas Drive yang sudah terhapus. |
| Penanda Drive | Duplikat cabang dibuang (`cariBerkasBertanda`, `pakaiSalinanAda`, `PENANDA_BERKAS`); dipakai `cariBerkasBerpenanda` + `PENANDA_KODE` produksi. Ditambah penanda tetap `sipinter=1` pada unggahan baru agar pemeriksa konsistensi bisa mendaftar bukti bertanda. |
| `GoogleDriveService` | API produksi (`uploadFile(..., array $penanda)`); ditambah `infoBerkas()` & `daftarBerkasBertanda()` (baca-saja). Sisa blok auto-merge yang memakai variabel tak terdefinisi (`$kodeBerkas`) dibuang. |
| Pemeriksa konsistensi | Disesuaikan: salinan bukti di folder Evaluasi-RTL (fitur produksi) tidak dilaporkan sebagai yatim bila tercatat di `salinan_bukti_rtl`. |
| Log `bukti.dihapus` | Teks cabang "salinan Drive tidak ikut dihapus" salah untuk produksi → kini menyebut "ikut dihapus permanen" / "belum punya salinan". |
| Bentrok penyuntingan | Ganti nama folder ✏️ (langsung tersimpan, fitur produksi) memperbarui versi baris yang tersentuh agar simpanan berikutnya tidak dianggap bentrok dengan perubahan sendiri. |
| Notula | penomoran Word produksi + format angka kolom (`nilaiKolomTampil`) + aturan N/n Rincian N |
| `_docs/template notula.docx` | tidak disertakan (§9) |

## 3. Daftar commit

| Commit | Judul | Cakupan | Test pada commit itu |
|---|---|---|---|
| `8f443c7` | Gabungkan 15 commit … ke origin/main | §2.1 | SQLite 710 lulus |
| `67ca217` | Rincian N: sumber rincian, rincian tambahan n, triwulan mulai berlaku | `RincianN`, Target Tahunan, migrasi `2026_10_21_000001`, hunk Rincian N di Verifikasi & Notula | SQLite 720 lulus (worktree terpisah) |
| `32d460a` | Format angka kolom Bagian Kustom: …, pembulatan eksak | `FormatAngkaKolom` + unit test (fungsi murni, belum disambungkan) | SQLite 733 lulus (worktree terpisah) |
| `50b2845` | Bagian Kustom & Isian Kegiatan: otorisasi, bentrok, log aktivitas, Drive | sisanya (lihat pesan commit) | SQLite 867, PostgreSQL 867 |
| `8b0b4d1` | Dokumen audit & `.gitignore` | `_docs/audit/*.md`, `.gitignore` (abaikan `.env.*` & pengaturan lokal `.claude/`) | — |
| `348592e` | Gabungkan origin/main `b2c3d40` | `docker/supervisord.conf`, Blade Backup (produksi) | SQLite 867, PostgreSQL 867 (HEAD akhir) |
| (commit ini) | Laporan: catat `b2c3d40` | dokumen saja | — |

Alasan pengelompokan `50b2845`: konfigurasi, otorisasi, bentrok, log, dan Drive berada di hunk yang sama
dan saling memanggil di `PengisianKegiatan`, `IsianBagianKustom`, `BagianKustomManager`, `VerifikasiCapaian`,
`BagianKustom`; memecahnya menghasilkan keadaan perantara yang tidak bisa dijalankan atau tidak aman
(mis. unggah tanpa pemeriksaan id). `32d460a` dipisah karena fungsi murni yang bisa ditinjau sendiri.

## 4. Hasil test (semua berurutan, tidak paralel)

| Putaran | Hasil |
|---|---|
| Tahap 1 (`8f443c7`), SQLite | 710 lulus, 0 gagal, 0 dilewati |
| Commit `67ca217`, SQLite | 720 lulus, 0 gagal, 0 dilewati |
| Commit `32d460a`, SQLite | 733 lulus, 0 gagal, 0 dilewati |
| **Akhir (`50b2845`, diulang di HEAD `348592e`), SQLite in-memory** | **867 lulus, 0 gagal, 0 dilewati, 3.740 asersi** |
| **Akhir (`50b2845`, diulang di HEAD `348592e`), PostgreSQL 18.3 lokal** (klaster pribadi 127.0.0.1:55432, `sipinter_uji`) | **867 lulus, 0 gagal, 0 dilewati, 4.057 asersi** |

Cakupan butir §E (semua di dalam suite di atas):

| Butir | Test |
|---|---|
| E.4 otorisasi HTTP setiap aksi, halaman terbuka, id dimanipulasi | `OtorisasiFinalisasiTest` (request Livewire HTTP sungguhan, peran dicabut saat halaman terbuka, id IKU lain), `LogAktivitasBagianKustomTest` (403 + log), `BagianKustomIsolasiTest` |
| E.5 bentrok bersamaan & muat ulang bagian | `KonflikPenyuntinganTest` |
| E.6 transaksi konfigurasi & riwayat | `RiwayatKonfigurasiTransaksiTest`, `BagianKustomPusatKonfigurasiTest` |
| E.7 perlindungan log & akses halaman log | `LogAktivitasViewerTest`, `MigrasiFinalisasiTest`, + uji trigger langsung di salinan skema produksi (§6) |
| E.8 Drive | `DriveKonsistensiBuktiTest` (17 test), `SalinanBuktiRtlTest`, `StrukturFolderSesuaiPolaTest` dan test unggah produksi lain |
| E.9 Rincian N, format angka, notula Word/PDF, Bagian Kustom, regresi produksi | `RincianTambahanNTest`, `TargetTahunanTest`, `FormatAngkaKolom*Test`, `NotulaDownloadTest`, `NamaCapaianNotulaTest`, seluruh test produksi |

Tidak dijalankan / batasan:
- `tests/Integrasi/SalinanBuktiRtlDriveNyataTest` (Drive sungguhan) — butuh folder & kredensial Drive **uji**
  khusus yang tidak tersedia; tidak dijalankan agar tidak menyentuh Drive produksi (§B.7). Drive di semua
  test lain dipalsukan.
- `tests/Browser` di luar suite bawaan.
- Konversi PDF lewat LibreOffice dimatikan di lingkungan test (`NOTULA_PDF_LIBREOFFICE=false`).
- Tampilan di peramban (spanduk bentrok, tema gelap hasil gabungan CSS, zona unggah) belum diperiksa manual.

Test yang diubah karena perilaku produksi menjadi acuan (`DriveKonsistensiBuktiTest`,
`LogAktivitasBagianKustomTest`): jalur unggah Bagian Kustom produksi (`unggahBerkasBagianKustom`); 403 terjadi
saat berkas dipilih (simpan otomatis); penggantian = unggah pengganti lalu hapus permanen bukti lama;
percobaan ulang via `cariBerkasBerpenanda`. Ditambah test baru: log gagal sebelum Drive disentuh (ganti &
hapus), Drive gagal saat hapus/ganti, hapus permanen + log, penanda unggahan.

## 5. Perilaku Google Drive

### 5.1 Perilaku produksi yang dipertahankan (acuan)

| Aksi | Produksi (dipertahankan) |
|---|---|
| Memilih berkas bukti | langsung disimpan sebagai draf dan diunggah ke Drive di request yang sama |
| Hapus **satu** bukti (Kegiatan, Bagian Kustom, Evaluasi RTL) | salinan Drive **dihapus permanen** setelah konfirmasi (`9944a9e`); Drive gagal → bukti dibiarkan |
| Hapus **kegiatan** | bukti dipindah ke **Sampah Drive** (`5c24b85`); bukti yang gagal dibuang → kegiatan tidak dihapus |
| Ganti bukti "Tidak Sesuai" | pengganti diunggah dulu, lalu bukti lama dihapus permanen; gagal hapus → bukti lama tetap tampil |

**Perlu konfirmasi Anda:** §B.2 persetujuan menyebut "penghapusan bukti yang memindahkan berkas ke Sampah
Drive". Di produksi itu hanya berlaku untuk hapus **kegiatan**; hapus **satu bukti** sejak `9944a9e`
menghapus permanen. Saya mengikuti produksi apa adanya dan tidak mengubahnya.

### 5.2 Yang ditambahkan tanpa mengubah perilaku di atas

- Unggahan setelah commit: transaksi simpan batal → tidak ada berkas Drive yatim, berkas lokal sementara
  dihapus, id baris baru tidak tertinggal (tidak ada data ganda saat simpan diulang).
- Urutan hapus: salinan Evaluasi-RTL dibuang dulu (seperti produksi) → satu transaksi: catatan pendamping +
  log → hapus salinan Drive sumber → hapus baris → setelah commit hapus berkas lokal. Kegagalan
  database/log terjadi sebelum Drive sumber disentuh dan membatalkan semuanya.
- Pencatatan hasil Drive + log "tersalin" dalam satu transaksi; bila pencatatan gagal, berkas Drive tetap
  bertanda kode berkas → percobaan berikutnya memakainya ulang, tidak menggandakan.
- Penggantian dikunci per berkas (kunci cache + `FOR UPDATE`): dua penggantian bersamaan → satu berhasil.
- `bukti:periksa-konsistensi [--drive]` (baca-saja) dan `bukti:bersihkan-lokal-yatim` (bawaan laporan).

### 5.3 Bukti uji (Drive dipalsukan)

Unggah setelah commit; transaksi gagal tanpa unggah; Drive gagal → aman lokal → disusulkan tanpa dobel;
pencatatan hasil gagal; pemakaian ulang salinan berpenanda; penanda kode + aplikasi pada unggahan;
ganti berhasil/gagal Drive/gagal log; penggantian ganda; hapus berhasil/gagal Drive/gagal log/gagal DB;
pemeriksa konsistensi tidak menulis apa pun; pembersih hanya berkas lokal yatim lama. Tidak ada uji
penghapusan terhadap bukti produksi (§B.7).

## 6. Gladi migrasi dan Supabase

### 6.1 Fakta produksi (baca-saja, 23 Okt)
PostgreSQL **17.6**; 102 migrasi, batch terakhir 68 (tidak berubah sejak 22 Okt); koneksi aplikasi lewat
**pooler Supabase mode sesi** (port 5432); `bagian_kustom` 0 baris, `bagian_kustom_poin` 0, `rincian_n` 34,
`berkas` 405 (11 tanpa `drive_file_id`); belum ada trigger buatan di `public`.
Pemeriksaan hak akses (bukan superuser, pemilik tabel) dari 22 Okt; pengulangan hari ini gagal tersambung
(lihat 6.4).

### 6.2 Gladi di salinan skema produksi
`pg_dump --schema-only --schema=public` produksi (hari ini) → dipulihkan ke klaster lokal + 102 baris
`migrations` produksi → `migrate:status`: **7 pending** — `2026_10_14_000001`, `2026_10_15_000001`,
`2026_10_21_000001`, `2026_10_21_000002`, `2026_10_22_000001`, `2026_10_22_100001`, `2026_10_22_100002`.
Tidak ada migrasi produksi yang hilang dari kode integrasi.
`migrate` ✔ → `migrate:rollback --step=7` ✔ (batch 68 utuh) → `migrate` ✔ → 0 pending.
Diverifikasi: 2 trigger `log_aktivitas`, fungsi `search_path=''`, 9 indeks log, FK baru (users `SET NULL`,
master_iku `RESTRICT`/`CASCADE`, bagian_kustom `CASCADE`/`SET NULL`), default kolom `rincian_n`
(`sumber='target'`, `triwulan_mulai_berlaku=1`), UPDATE/DELETE/TRUNCATE log ditolak. Tabel log sengaja
tanpa FK, jadi penghapusan di tabel lain tidak terhalang trigger.

### 6.3 Yang **belum** terbukti untuk Supabase
- Perilaku lewat pooler pada beban nyata, penguncian `FOR UPDATE` lintas koneksi lewat pooler, waktu
  migrasi di server produksi, hak peran Supabase lain/RLS. Uji di atas = PostgreSQL lokal.
- Gladi dengan **data** produksi (sengaja tidak disalin; data pribadi).

### 6.4 Temuan koneksi
Dari mesin ini, pooler beberapa kali memutus jabat tangan SSL (`unexpected eof`) lalu *timeout* — bergantian
antar-IP pooler. Penyebabnya belum dapat dipastikan (jaringan lokal atau pooler). Saran: verifikasi
konektivitas dari lingkungan deploy; fitur Backup aplikasi (pg_dump 17) dan migrasi saat start kontainer
bergantung padanya.

## 7. Backup dan pemulihan

### 7.1 Status produksi
**Backup produksi belum dibuat** — fitur Backup aplikasi menulis baris `backups` dan mengunggah ke Drive
(operasi tulis), dilarang selama integrasi (§F.6). Keberadaan/waktu/lokasi backup Supabase (harian/PITR)
belum diverifikasi — perlu dicek di dasbor Supabase.

### 7.2 Gladi prosedur (lokal, hari ini)
Database gladi (skema produksi + 7 migrasi + data contoh) → `pg_dump --no-owner --no-privileges
--format=plain --schema=public` (opsi sama dengan `BackupService`) → database baru → `DROP SCHEMA public
CASCADE` → `psql -v ON_ERROR_STOP=1 -f` → jumlah migrasi/batch, jumlah & hash isi log, jumlah tabel, dan
trigger **identik**; trigger aktif kembali setelah pulih. Prosedur pemulihan tidak memerlukan langkah yang
belum diuji, **kecuali** pemulihan ke Supabase itu sendiri.

### 7.3 Sebelum deploy
11 baris `berkas` produksi tanpa salinan Drive. Disk lokal kontainer terhapus saat deploy ulang, jadi
berkas yang masih hanya-lokal bisa hilang. Saran (perlu izin Anda): jalankan
`php artisan bukti:periksa-konsistensi` (baca-saja) setelah kode baru tersedia, atau `berkas:sinkron-drive`
produksi sebelum deploy.

## 8. U-10 — tidak termasuk rilis ini

U-10 (log aktivitas untuk Kegiatan, Kendala & Solusi, RTL, bukti Kegiatan) **tidak dikerjakan** dan
bukan bagian dari rilis ini. Risiko: untuk bagian-bagian itu tidak ada jejak "siapa mengubah/menghapus apa,
kapan" (termasuk hapus kegiatan dan hapus/ganti bukti Kegiatan); yang terlacak hanya perubahan status lewat
`riwayat_status_capaian` dan penandaan berkas lewat `riwayat_verifikasi_berkas` produksi. Yang sudah tercatat
di rilis ini: percobaan simpan Isian Kegiatan yang ditolak (id tidak sah) dan simpanan yang batal karena
bentrok.

## 9. `_docs/template notula.docx`

Tidak disertakan. Alasan: tidak dirujuk kode/test mana pun (runtime memakai
`template_notula/SIPINTER_Template_Bagian_I_Mesin.docx` atau template unggahan di Pengaturan);
`.dockerignore` mengecualikan seluruh `_docs`; isinya (SHA-256 `A142BBE2…8915`, 21.793 byte, 68 macro)
tidak sama dengan template mana pun di `template_notula/`; metadata `creator`/`lastModifiedBy` memuat nama
pribadi. Berkas asli di folder kerja utama tidak diubah.

## 10. Risiko residual

1. Kegagalan **commit** database tepat setelah salinan Drive sumber terhapus (sangat jarang) → baris
   merujuk berkas Drive yang sudah tidak ada; terdeteksi `bukti:periksa-konsistensi --drive`.
2. Log gagal setelah salinan Evaluasi-RTL terbuang → bukti sumber utuh, salinan dibuat ulang oleh sinkron.
3. `Kegiatan::gantiNamaFolder()` produksi mengganti nama folder Drive di dalam transaksi simpan; bila
   transaksi batal, nama folder Drive bisa berbeda dari database sampai rename berikutnya (perilaku lama).
4. Penanda `sipinter=1` hanya pada unggahan baru; berkas lama tidak terdeteksi sebagai "Drive yatim".
5. Bentrok pada bagian mana pun membatalkan simpan seluruh formulir; dengan simpan otomatis saat memilih
   berkas, berkas yang dipilih saat bentrok tetap menunggu di layar sampai data dimuat ulang.
6. Pemilik tabel/admin DB tetap bisa menonaktifkan trigger log.
7. `RtlEvaluasi` (komponen lama) — temuan #8 laporan 22 Okt, belum dicek ulang.
8. Hal yang belum terbukti: §4 (Drive sungguhan, PDF LibreOffice, tampilan peramban), §6.3, §7.1.

## 11. Langkah yang diperlukan sebelum rilis (butuh persetujuan terpisah)

1. Tinjau laporan ini dan putuskan §5.1.
2. Cek backup Supabase di dasbor; buat Backup Database dari aplikasi (pg_dump 17) dan unduh.
3. Periksa 11 berkas tanpa salinan Drive (§7.3).
4. Uji konektivitas pooler dari lingkungan deploy (§6.4).
5. Tinjau tampilan di peramban (staging bila ada): Isian Kegiatan (unggah, bentrok), Verifikasi (kartu
   induk), Bagian Kustom (konfigurasi, riwayat), tab Log Aktivitas, tema gelap.
6. Baru kemudian: push cabang, PR ke `main`, deploy, verifikasi pascadeploy (lihat §10 laporan 22 Okt).
