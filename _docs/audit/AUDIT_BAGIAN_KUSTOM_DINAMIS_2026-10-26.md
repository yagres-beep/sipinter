# Audit & Rencana — Bagian Kustom Dinamis (26 Oktober 2026)

Tahap 1 dari prompt "Pengembangan Sistem Bagian Kustom Dinamis". Belum ada kode yang diubah.

- Dasar audit: `main` = `origin/main` = `2b0797f` (dibaca dari worktree `sipinter-pisah-n`, bersih).
- Pekerjaan baru: worktree `D:\Latsar\sipinter-kustom-dinamis`, cabang lokal `fitur/bagian-kustom-dinamis` (tanpa upstream, tidak bisa ter-push ke main secara tidak sengaja).
- Data produksi: TIDAK diakses. Kondisi data dibaca dari berkas cadangan `backup-produksi-20261023-1534.dump` (23 Okt, baca-saja lewat `pg_restore -f -`).

Label: **[OK]** sudah berfungsi · **[SEBAGIAN]** ada tapi belum lengkap · **[BELUM]** belum tersedia · **[PERBAIKI]** perlu diperbaiki · **[RISIKO]** berpotensi merusak data · **[DB]** butuh perubahan database.

---

## 1. Kondisi Git dan produksi

| Hal | Temuan |
|---|---|
| `origin/main` | `2b0797f` (pisah N/n). Dua commit terakhir memakai `[skip render]` → produksi kemungkinan masih `b2c3d40`. |
| Migrasi produksi | 7 migrasi Bagian Kustom + `2026_10_23_000001` **belum jalan**. Di cadangan 23 Okt, `bagian_kustom` masih skema lama (`id, nama, deskripsi, aktif, urutan, frekuensi_wajib, bukti_wajib`). |
| Data Bagian Kustom produksi | `bagian_kustom` **0 baris**, `bagian_kustom_poin` **0 baris** (per 23 Okt). Risiko data historis untuk perubahan skema Bagian Kustom **rendah**, tapi wajib dicek ulang sebelum rilis. |
| Gerbang rilis | `render.yaml` tanpa `autoDeploy:false` + entrypoint `migrate --force` → **push berikutnya ke main tanpa `[skip render]` = deploy + migrasi**. |
| Folder `D:\Latsar\sipinter` | Cabang `susun-ulang-pertahankan-suntingan`, 23 berkas berubah + ±40 berkas baru belum di-commit. Isinya sudah diintegrasikan ke main (dipotret di `refs/cadangan/snapshot-*`). Tidak disentuh. |
| Worktree `sipinter-tw-mulai` | Ada perubahan **belum di-commit** milik sesi lain pada `VerifikasiCapaian.php`, `LogAktivitas.php`, `JejakRincianN.php`. Tahap 7 (verifikasi) & pencatatan log akan bersinggungan → perlu diintegrasikan setelah itu di-commit. |
| `PengisianKegiatan.php` | 5.139 baris, sering disunting sesi lain. Perubahan di sana dibuat seminimal mungkin (komponen terpisah). |

## 2. Struktur data saat ini

```
bagian_kustom                      (definisi)
  id, nama (unik tanpa beda huruf, di kode), deskripsi, aktif, urutan,
  peran JSON [ketua_tim|tim_sakip|kepala], penempatan JSON [kunci menu],
  frekuensi_wajib, bukti_wajib, label_uraian,
  kolom JSON [{kunci, label, tipe teks|angka|pilihan, opsi, wajib, aktif,
              mulai_tahun, mulai_triwulan, satuan, desimal, pemisah, ribuan}],
  masuk_notula, judul_notula, format_notula ("{kode_iku}: {teks}")
bagian_kustom_poin                 (isian; satu baris = satu poin)
  id, bagian_kustom_id FK, iku_id FK, periode_id FK (periode = bulan),
  menu, peran_pengisi, diisi_oleh, diubah_oleh, teks, nilai_kolom JSON {kunci: nilai},
  status_verifikasi, catatan, catatan_bukti_dihapus
berkas (morph ref_type/ref_id → BagianKustomPoin), kategori 'bagian_kustom'
riwayat_konfigurasi_bagian_kustom  (potret definisi sebelum/sesudah tiap perubahan)
log_aktivitas                      (append-only, dilindungi trigger)
```

Identitas stabil: `bagian_kustom.id` dan `kolom.kunci` (kunci tetap walau label diganti). Jawaban disimpan per `kunci`, bukan per label. **[OK]**

## 3. Temuan per area

### 3.1 Pengelolaan bagian (`BagianKustomManager`, di halaman Data Master)
- **[OK]** Daftar semua bagian (kartu), Edit memuat konfigurasi tersimpan, tab Dasar/Isian/Notula/Pratinjau/Riwayat, naik/turun urutan, aktif/nonaktif, hapus hanya bila belum ada poin.
- **[OK]** Duplikasi lewat "salin pengaturan dari" di form Tambah: hanya definisi, tanpa poin/bukti/riwayat.
- **[OK]** Deteksi bentrok dua Tim SAKIP (sidik jari versi), perubahan + riwayat dalam satu transaksi, cache `bagian-kustom.aktif` dilupakan setiap simpan.
- **[OK]** Otorisasi di `boot()` (setiap request Livewire), bukan hanya tombol.
- **[SEBAGIAN]** Daftar tidak menampilkan jumlah kolom, periode pengisian, status notula, waktu ubah; tidak ada pencarian, filter (status/peran/penempatan), pengurutan, maupun halaman detail baca-saja.
- **[BELUM]** Kode internal unik (`kode`) — identitas hanya `id` + `nama`. **[DB]**
- **[PERBAIKI]** Folder Drive bukti memakai `nama` bagian. Bila nama diganti, unggahan baru masuk folder bernama baru; bukti lama tetap di folder lama (tidak hilang, tapi terpecah), dan kategori Struktur Folder tidak ikut berganti.

### 3.2 Struktur isian
- **[BELUM]** Subbagian. **[DB]**
- **[SEBAGIAN]** Jenis kolom hanya Teks, Angka, Pilihan tunggal. Belum ada Teks panjang, Tanggal, Pilihan ganda.
- **[SEBAGIAN]** Validasi hanya wajib, opsi pilihan, dan format angka. Belum ada min/maks nilai, panjang min/maks, pesan validasi khusus, petunjuk/placeholder per kolom.
- **[OK]** Kolom nonaktif tetap menyimpan & menampilkan data lama; kolom baru bisa "mulai berlaku TW x"; kolom berisi data tidak bisa dihapus; ganti tipe ditolak bila data lama tidak cocok.
- **[PERBAIKI]** Batas 12 kolom per bagian (perlu dinaikkan bila ada subbagian).
- **[OK]** Validasi di backend sama untuk Isian Kegiatan dan Isian Bagian Kustom (`BagianKustom::pesanGalatKolom`).

### 3.3 Bukti dukung
- **[SEBAGIAN]** Hanya satu saklar `bukti_wajib`, hanya berlaku di Isian Kegiatan (Ketua Tim), hanya PDF, divalidasi backend. Di menu lain bukti tidak didukung sama sekali.
- **[BELUM]** Pilihan tidak perlu/opsional/wajib, jumlah min/maks, ukuran, format, tingkat subbagian/kolom, tampil di notula. **[DB]** (cukup kolom JSON pengaturan)
- **[OK]** Unggah lewat `FolderStructureService::unggahBerkasBagianKustom` (Drive), unggah setelah commit, perintah `bukti:periksa-konsistensi`.

### 3.4 Relasi antarperiode
- **[SEBAGIAN]** Isian Kegiatan menampilkan riwayat poin triwulan sebelumnya (tahun yang sama) sebagai baca-saja (`PengisianKegiatan::riwayatBagianKustom`). Isian Bagian Kustom hanya per bulan, tanpa riwayat.
- **[BELUM]** Salin sebagai isian baru, lanjutkan objek, identitas objek, status objek (selesai/tidak relevan), asal salinan, pencegahan salinan ganda, bukti sebagai referensi. **[DB]**

### 3.5 Verifikasi
- **[OK]** Poin Isian Kegiatan masuk `status_verifikasi = menunggu`, ditandai sesuai/tolak oleh Tim SAKIP di Verifikasi, terkunci saat capaian diajukan, catatan & log tercatat.
- **[SEBAGIAN]** Poin di menu lain langsung `terverifikasi` (tidak ada alur pemeriksaan). Prompt meminta "bagian dapat menentukan apakah butuh pemeriksaan" → ini alur baru, perlu keputusan (lihat §6).

### 3.6 Notula
- Alur: `NotulaService` mengumpulkan poin per triwulan → `NotulaBagian1DocxService::bagianKustomXml()` menulis judul + satu butir per poin ke DOCX → LibreOffice mengubah DOCX ke HTML (`notula.bagian1_html`, pratinjau web & suntingan) → PDF dari HTML (Dompdf). **Satu sumber**, jadi web/DOCX/PDF otomatis konsisten bila perubahan dibuat di `bagianKustomXml()`. **[OK]**
- **[OK]** Bagian nonaktif tetap tercetak bila punya poin pada triwulan itu; notula yang sudah dibuat tersimpan berversi (`notula.versi`, `bagian1_html`).
- **[SEBAGIAN]** Bentuk penyajian hanya daftar berbutir dengan format baris `{variabel}`. Belum ada tabel, paragraf, ringkasan; belum ada pengaturan per kolom (masuk notula, label, urutan, perataan, perilaku kosong, format tanggal).
- **[SEBAGIAN]** Variabel `{kunci}` per bagian + 7 variabel bawaan. Belum ada katalog lintas bagian dengan nama ber-namespace (`{{kode_bagian.kunci}}`), belum ada "dokumen/posisi tujuan" (blok selalu disisipkan setelah blok IKU terakhir).
- **[PERBAIKI]** Variabel tidak dikenal dicegah saat simpan, tapi bila tetap ada (mis. data lama) `render()` mencetaknya mentah `{xxx}` di dokumen.
- **[OK]** Rendering aman: `preg_replace_callback` + `htmlspecialchars`, tanpa eksekusi kode.

### 3.7 Versi konfigurasi
- **[SEBAGIAN]** `riwayat_konfigurasi_bagian_kustom` menyimpan potret lengkap sebelum/sesudah setiap perubahan → versi bisa direkonstruksi menurut waktu. Poin belum menunjuk versi konfigurasi yang berlaku saat diisi/diajukan. **[DB]** (satu kolom FK nullable)

### 3.8 Log
- **[OK]** Perubahan konfigurasi → `riwayat_konfigurasi_bagian_kustom` (keputusan 22 Okt: tidak digandakan ke `log_aktivitas`). Isian, bukti, penandaan verifikasi, akses ditolak → `log_aktivitas` (append-only, trigger PG), ditulis di transaksi yang sama sehingga hanya tindakan berhasil yang tercatat.
- **[BELUM]** Jenis log baru: salin antarperiode, lanjutkan objek, ubah status objek.

### 3.9 Hak akses & penempatan
- **[OK]** Katalog menu membatasi peran per menu; `tampilDi()` memeriksa aktif + peran + penempatan + izin menu; data dipisah per konteks menu+peran; IDOR id poin ditolak.
- **[PERBAIKI kecil]** Pesan kosong di Isian Bagian Kustom belum menjelaskan sebabnya (tidak ada bagian aktif vs. peran/penempatan tidak cocok).
- **[BELUM]** Peran "pembaca" terpisah dari peran "pengisi" (saat ini satu daftar `peran` = melihat & mengisi).

### 3.10 Tes
- 78 berkas tes; suite pada `main` dijalankan ulang di sesi ini (hasil di §7).

## 4. Rancangan perubahan database (semua aditif)

Prinsip: definisi tetap di JSON (`bagian_kustom.kolom`, pola yang sudah berjalan, kunci stabil); hal yang butuh integritas relasi (objek, asal salinan, referensi bukti, versi) dibuat sebagai kolom/tabel ber-FK. Tidak ada kolom lama yang dihapus atau diubah tipenya.

| Migrasi (usulan) | Isi | Data lama |
|---|---|---|
| `2026_10_26_000001_tambah_kode_dan_pengaturan_ke_bagian_kustom` | `kode` string(60) nullable + unique; `subbagian` JSON nullable; `pengaturan` JSON nullable (bukti, relasi antarperiode, notula, peran pembaca) | `kode` diisi dari slug nama + id bila kosong; JSON NULL = perilaku lama |
| `2026_10_26_000002_buat_tabel_bagian_kustom_objek` | `id, bagian_kustom_id FK restrict, iku_id FK nullable, menu, peran_pengisi, judul, status (berlanjut/selesai/tidak_relevan/dihentikan), alasan_status, dibuat_oleh, diubah_oleh, timestamps` | tabel baru |
| `2026_10_26_000003_tambah_relasi_periode_ke_bagian_kustom_poin` | `objek_id` FK nullable restrict, `disalin_dari_poin_id` FK nullable restrict, `asal` string (baru/salinan/lanjutan) default `baru`, `riwayat_konfigurasi_id` FK nullable; unique (`disalin_dari_poin_id`, `periode_id`, `menu`, `peran_pengisi`) | poin lama = `asal baru`, kolom lain NULL |
| `2026_10_26_000004_buat_tabel_referensi_bukti_bagian_kustom` | `bagian_kustom_poin_id` FK, `berkas_id` FK restrict, unique pasangan | referensi saja, tidak menggandakan file Drive |

Kolom JSON `kolom[]` bertambah kunci opsional: `subbagian`, `petunjuk`, `placeholder`, `min`, `maks`, `panjang_min`, `panjang_maks`, `pesan`, `notula{masuk,label,urutan,perataan,tampil_label,kosong,format_tanggal}`. Kolom lama tanpa kunci ini berperilaku sama seperti sekarang.

`down()` tiap migrasi hanya membuang kolom/tabel baru tersebut. Gladi: naik-turun-naik di SQLite & PostgreSQL uji + salinan cadangan 23 Okt.

## 5. Rencana bertahap

| Tahap | Isi | Berkas utama | Bersinggungan |
|---|---|---|---|
| 2 | Daftar bagian (kolom ringkasan, cari, filter, urut), halaman detail, `kode` unik, duplikasi tetap tanpa data, pesan kosong yang jelas | `BagianKustomManager` + blade, migrasi 1 | — |
| 3 | Subbagian, jenis Teks panjang/Tanggal/Pilihan ganda, validasi min/maks/panjang/pesan, petunjuk; formulir dinamis di Isian Bagian Kustom & Isian Kegiatan | `BagianKustom`, `IsianBagianKustom`, partial blade kolom bersama, sedikit `PengisianKegiatan` | PengisianKegiatan |
| 4 | Aturan bukti (tidak perlu/opsional/wajib, jumlah, format, ukuran), bukti di menu selain Isian Kegiatan lewat Drive yang sama | `IsianBagianKustom`, `FolderStructureService` (tanpa ubah perilaku lama) | GoogleDriveService |
| 5 | Relasi antarperiode: lihat riwayat, salin sebagai isian baru (Mode A), lanjutkan objek (Mode B), status objek, referensi bukti, cegah ganda, transaksi | komponen baru `RelasiAntarperiodeBagianKustom`, migrasi 2–4 | — |
| 6 | Notula: bentuk Daftar (lama)/Paragraf/Tabel, pengaturan per kolom, katalog variabel `{{kode.kunci}}`, variabel tak dikenal → kosong + peringatan, pratinjau | `NotulaBagian1DocxService::bagianKustomXml`, `TemplateBagianKustom` | Notula (diuji DOCX→HTML→PDF) |
| 7 | Verifikasi (salinan selalu mulai `menunggu`), log baru, uji regresi penuh SQLite + PG | `VerifikasiCapaian`, `JejakAktivitasBagianKustom` | **sipinter-tw-mulai** (belum commit) |
| 8 | Laporan rilis, gladi migrasi di salinan cadangan, ceklis 6 poin | — | gerbang rilis main |

Tiap tahap: tes baru + suite penuh, laporan singkat, commit lokal di cabang fitur (bila disetujui). Tidak ada push/merge/deploy tanpa persetujuan terpisah.

## 6. Keputusan pengguna (26 Okt 2026)

1. **Penyimpanan definisi**: JSON + tabel relasi (usulan §4). Tanpa normalisasi penuh.
2. **Pemeriksaan untuk bagian di luar Isian Kegiatan**: tetap langsung sah (perilaku sekarang dipertahankan).
3. **Urutan kerja**: Tahap 2 + 3 lebih dulu.
4. **Folder Drive saat bagian diganti nama**: tetap folder lama (nama folder dikunci saat bagian dibuat; file lama tidak dipindah).

## 8. Progres Tahap 2 + 3 (26 Okt, belum di-commit)

**Selesai dan teruji:**
- Kode internal unik `bagian_kustom.kode` (otomatis dari nama atau diisi manual, divalidasi pola & unik, tidak berubah saat nama diganti).
- Nama folder Drive dikunci di `bagian_kustom.nama_folder`; unggahan bukti memakai `namaFolderEfektif()` → ganti nama tidak memecah folder.
- Daftar bagian: cari (nama/kode/deskripsi), filter status/peran/penempatan, urut (urutan tampil/nama/terakhir diperbarui), ringkasan subbagian, kolom, periode, notula, waktu ubah. Tombol naik/turun disembunyikan saat filter aktif.
- Tombol Edit, Detail (baca-saja, struktur bertingkat), Duplikasi (mengisi form Tambah; tanpa isian/bukti/verifikasi/riwayat; subbagian & kolom nonaktif tidak ikut), Nonaktifkan (dengan konfirmasi) / Aktifkan kembali.
- Subbagian (opsional, maks 15): tambah, ganti nama/deskripsi, urutkan, nonaktifkan, hapus hanya bila kosong. Kolom bisa langsung di bagian atau di subbagian; urutan kolom per kelompok.
- Jenis kolom baru: Teks panjang, Tanggal (format panjang/pendek/ISO), Pilihan ganda (disimpan JSON; nilai lama pilihan tunggal tetap terbaca). Batas kolom 12 → 40.
- Validasi per kolom: min/maks angka, panjang min/maks teks (bawaan 500/5000), pesan galat khusus, petunjuk & placeholder.
- Formulir Isian Kegiatan dan Isian Bagian Kustom dirender dari satu partial (`_isian-kolom-bagian-kustom`) berdasarkan konfigurasi; kolom di subbagian nonaktif tidak tampil, nilainya tetap tersimpan.
- Ganti jenis kolom ditolak bila ada nilai lama yang tidak cocok; nilai tidak pernah dikonversi.
- Riwayat konfigurasi mencatat perubahan subbagian & pengaturan kolom baru.

**Migrasi:** `2026_10_26_000001_tambah_kode_folder_subbagian_ke_bagian_kustom` (aditif: `kode` unik nullable, `nama_folder`, `subbagian` JSON). Diuji: baris lama terisi tanpa mengubah kolom lama; turun-naik-ulang aman (SQLite). PostgreSQL belum diuji.

**Tes baru:** `BagianKustomBuilderTest` (15), `MigrasiKodeSubbagianBagianKustomTest` (1).

**Suite penuh di worktree ini (SQLite):** 901 lulus, 0 gagal, 4.007 asersi (885 lama + 16 baru). Tidak ada tes lama yang diubah. Satu teks pratinjau sempat diubah lalu dikembalikan supaya tes lama tetap berlaku apa adanya.

**Catatan proses:** worktree awalnya memakai `vendor` via junction sehingga autoloader memuat kode dari `sipinter-pisah-n`; sudah diganti salinan `vendor` dan diverifikasi (`ReflectionClass` menunjuk worktree ini). Hasil tes sebelum perbaikan itu tidak dipakai.

**Belum:** peran "pembaca" terpisah dari pengisi; duplikasi subbagian; uji PostgreSQL; uji tampilan di browser.

## 7. Hasil tes dasar (main 2b0797f, SQLite)

`php artisan test` di worktree `sipinter-pisah-n` (bersih, = main): **885 lulus, 0 gagal, 3.901 asersi, 464 detik**. PostgreSQL belum dijalankan untuk dasar ini.
