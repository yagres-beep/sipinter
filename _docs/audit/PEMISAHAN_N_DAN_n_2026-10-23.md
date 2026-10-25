# Pemisahan angka N dan rincian n — SIPINTER (23 Oktober 2026)

Cabang: `fitur/pisah-angka-n-N` (dasar: `integrasi/rilis-bagian-kustom` @ f9ead62).
Belum di-push, belum di-merge, belum dimigrasikan ke produksi.

## 1. Keputusan bisnis (disetujui pengguna, 23 Okt 2026)

| Pertanyaan | Keputusan |
|---|---|
| N diubah di tengah tahun | Berlaku untuk **semua TW** (perilaku sebelumnya, sesuai Kertas Kerja: Y konstan). |
| Kapan rincian n masuk pembilang | Saat **Tim SAKIP mencentang dan menyimpan** di Verifikasi, apa pun status dokumen capaian (sama dengan realisasi IKU Non %). |
| Uraian rincian n yang berasal dari target | **Mengikuti target** (satu baris data, tanpa salinan). Di Verifikasi hanya status realisasinya yang diubah. Rincian tambahan bisa ditambah, dikoreksi uraiannya, dan dihapus (bila belum terealisasi). |

## 2. Definisi dan penyimpanan

| Konsep | Disimpan di | Diubah oleh |
|---|---|---|
| Angka total N (penyebut) | `capaian_tahunan.y_alokasi_tw1..4` (nilai sama) | Target Tahunan (isian eksplisit), impor Excel |
| Nilai dasar n | `capaian_tahunan.y_realisasi_tw1..4` (= N) | Ikut N setiap simpan Target Tahunan / impor |
| Daftar rincian target | `rincian_n` sumber `target`, `dilepas_dari_target_at` NULL | Target Tahunan |
| Rincian tambahan n | `rincian_n` sumber `verifikasi` | Verifikasi |
| Triwulan mulai berlaku | `rincian_n.triwulan_mulai_berlaku` (target = 1; tambahan = TW periode Capaian, dari server) | Tidak pernah diubah otomatis |
| Triwulan realisasi diakui | `rincian_n.triwulan_realisasi` | Verifikasi (centang Tim SAKIP) |
| Pembilang n per TW | `capaian_tahunan.x_realisasi_tw1..4` = jumlah baris unik yang `triwulan_realisasi = TW` dan `>= triwulan_mulai_berlaku` | Dihitung ulang dari `rincian_n` setiap simpan Verifikasi |
| Pengubah terakhir | `rincian_n.diubah_oleh` (baru), `dibuat_oleh` | Otomatis |
| Riwayat | `log_aktivitas` aksi `target_tahunan.n_diubah`, `rincian_n.*` | Otomatis, di dalam transaksi simpan |

Persentase realisasi TW t = Σ x_realisasi_tw1..t ÷ y_realisasi_tw t × 100 (tidak berubah).
Jumlah baris `rincian_n` tidak lagi dipakai sebagai N di mana pun.

## 3. Perubahan perilaku

1. **Target Tahunan**: N diketik di kolom "N =". Menambah/menghapus rincian tidak mengubah N.
   Penanda "(berbeda dari N)" dan tombol "Pakai X sebagai N" (hanya mengisi form).
   Sebelumnya N ditimpa jumlah rincian setiap Simpan — IKU hasil impor tanpa rincian berubah jadi N = 0.
2. **Verifikasi**: menampilkan N (read-only) dan ringkasan rincian n (terdaftar / mengikuti target /
   tambahan / target dilepas / terealisasi s.d. TW). Koreksi uraian rincian tambahan.
   **Perbaikan**: sesi Verifikasi yang terbuka sebelum N diubah tidak lagi menimpa N baru dengan
   nilai lama saat disimpan (kolom x/y alokasi & y realisasi tidak ditulis dari Verifikasi).
3. **Notula Bagian I**: "N = … mencakup:" memakai angka N (sama dengan rumus y = n/N), bukan jumlah rincian.
4. **Impor Excel Master IKU**: ikut mengisi nilai dasar n (`y_realisasi_tw1..4`) dari Y impor.
   Mode upsert **tidak lagi menimpa N tersimpan diam-diam**: pratinjau mendaftar IKU yang N-nya
   akan berubah (lama → baru); bawaannya N & nilai dasar n tersimpan dipertahankan (kolom lain tetap
   diimpor), diganti hanya bila "Ganti angka N tersimpan dengan nilai Excel" dicentang. Setiap
   perubahan N lewat impor (termasuk mengisi N yang masih kosong) tercatat di Log Aktivitas
   (menu "Impor Excel Master IKU"). (Tambahan 25 Okt 2026.)
5. **Dasbor**: rekap per IKU memakai rumus yang sama dengan kartu rata-rata/Notula/LAKIN (rumus kustom
   IKU Non % kini diterapkan). Untuk IKU % hasilnya identik.
6. **LAKIN**: menyimpan ulang baris yang terhubung ke IKU memakai rumus resmi (batas maksimal, "-",
   rumus kustom) — sebelumnya rasio polos sehingga angka berubah hanya karena disimpan ulang.
   Baris kustom tanpa IKU tidak berubah.
7. **Rumus kustom membagi nol** (mis. `alokasi / realisasi` saat realisasi 0) kini menghasilkan "-",
   sebelumnya galat 500 di Dasbor/Verifikasi/Notula.

## 4. Pemetaan data lama (tanpa mengubah data)

- Migrasi baru hanya satu: `2026_10_23_000001_tambah_diubah_oleh_ke_rincian_n` (kolom nullable). Tidak ada UPDATE/DELETE.
- N yang berlaku = nilai `y_alokasi_tw4` yang sudah tersimpan (hasil simpan terakhir = jumlah rincian saat itu,
  atau nilai impor). Nilainya tidak diubah; form Target Tahunan memuatnya apa adanya.
- Baris `rincian_n` lama: sumber `target`, mulai berlaku TW I (dari migrasi 2026_10_21, belum dirilis).
  Periode historisnya tidak diketahui, jadi tidak dikarang: semua dianggap berlaku sejak TW I, dan
  `triwulan_realisasi` lama dipakai apa adanya.
- `y_realisasi_tw*` yang kosong (mis. IKU hasil impor) baru terisi saat Target Tahunan disimpan atau
  impor ulang. Sebelum itu persentase realisasi IKU tsb tetap 0/"-" seperti sekarang.

## 5. Dokumen historis

- Notula final (PDF setelah disetujui Kepala) diarsip dan tidak dibuat ulang. Draf Notula selalu dari data terkini.
  Revisi IKU setelah notula disetujui mengikuti alur `NotulaService::perbaruiSetelahRevisiIku()` yang sudah ada.
- LAKIN menyimpan angka sendiri; hanya berubah lewat "Segarkan" atau sunting baris.
- Perubahan N tercatat di log (nilai lama & baru) sehingga angka lama tetap bisa ditelusuri.

## 6. Pengujian

Skenario 1–9 permintaan: `tests/Feature/RincianTambahanNTest.php` (`test_skenario_1_…` s.d. `test_skenario_9_…`)
plus sesi Verifikasi basi, koreksi uraian, N = 0/kosong, manipulasi payload.
Regresi (Skenario 10): TargetTahunanTest, VerifikasiCapaianTest, NotulaDownloadTest, DasborCapaianTest,
LakinTest, MasterIkuImportValidatorTest, CapaianTahunanTest, dan suite penuh (SQLite + PostgreSQL 18 lokal).

## 7. Perlu persetujuan sebelum rilis

- Merge ke cabang rilis/main, push, `php artisan migrate` di produksi (1 kolom baru, aditif), deploy.
- Ceklis rilis 6 poin tetap berlaku (backup produksi terverifikasi, dsb).
