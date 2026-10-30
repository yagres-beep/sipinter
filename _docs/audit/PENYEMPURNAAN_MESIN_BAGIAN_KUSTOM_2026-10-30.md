# Penyempurnaan Mesin Bagian Kustom — 30 Oktober 2026

Cabang: `fitur/bagian-kustom-mesin` (dasar `origin/main` a56dc6a). Bagian III Notula tidak disentuh.

## 1. Audit awal

Sebagian besar permintaan sudah ada di main dan teruji:

- Konfigurasi di DB dengan kunci kolom stabil.
- Subbagian dan tabel berulang (JSON terstruktur).
- Rumus tanpa `eval`.
- Validasi server terpusat (`BagianKustom::galatNilaiPoin`).
- Alur 2 mode.
- Aturan bukti dan Drive.
- Periode berlaku.
- Matriks hak aksi.
- Penguncian konflik konfigurasi.
- Riwayat potret sebelum/sesudah.
- Log aktivitas tak bisa diubah.
- Pratinjau formulir dari pengaturan yang belum disimpan.

Di produksi (salinan 30 Okt pagi), satu-satunya bagian ("Manajemen Resiko") belum punya kolom. Struktur yang kosong itu terjadi karena belum diisi pengguna, bukan karena fitur rusak.

| Prioritas | Temuan | Tindakan |
|---|---|---|
| P1 | Pemeriksa hanya melihat poin dari menu yang sama (`where menu = menu layar`). Bagian yang diisi Ketua Tim di RTL & Evaluasi tidak bisa diperiksa Tim SAKIP, sehingga penempatan ganda tidak berbagi alur. | Diperbaiki. Poin dari semua menu penempatan (kecuali Isian Kegiatan) bisa diperiksa. Server memastikan menu poin termasuk penempatan bagian. |
| P1 | Alur hanya satu tahap. Persetujuan Kepala setelah Tim SAKIP tidak bisa diatur. | Tahapan pemeriksaan berjenjang (maks. 3), giliran per tahap diperiksa server. |
| P2 | Jenis kolom belum ada: kode, email, mata uang, waktu, tanggal-waktu, ya/tidak, referensi data, teks informasi, bilangan bulat. | Ditambahkan di semua jalur: editor, renderer, validasi, simpan, tampil, notula, ganti jenis, katalog. |
| P2 | Syarat tampil hanya 3 operator. | 7 operator dari daftar tetap, evaluasi identik di browser dan server. |
| P2 | Tidak ada nomor versi definisi per isian. | `versi_konfigurasi` di bagian, poin, dan riwayat. |
| P2 | Pemeriksaan sebelum terbit belum memeriksa acuan syarat/rumus dan belum punya tingkat informasi. | Diperluas, ditampilkan di tab Pratinjau beserta simulasi alur. |
| P2 | Katalog Variabel (pekerjaan sesi lain, belum di-commit) | Diverifikasi (tes lulus) lalu di-commit apa adanya (fc35a31). |
| P3 | Riwayat tidak menyebut perubahan alur, periode, rumus, tabel, atau syarat. | Dilengkapi. |
| P3 | Kotak info alur terpecah tampilannya (flex). | Diperbaiki. |

## 2. Perubahan database (aditif)

`2026_10_30_100001_tambah_tahap_dan_versi_ke_bagian_kustom` hanya menambah kolom nullable:

- `bagian_kustom.versi_konfigurasi`
- `bagian_kustom_poin.versi_konfigurasi`
- `bagian_kustom_poin.tahap_periksa`
- `riwayat_konfigurasi_bagian_kustom.versi`

Tidak ada data yang diubah. `down()` hanya membuang kolom baru.

Gladi migrasi dilakukan pada salinan data produksi (`gladi_30okt_asli`, 117 migrasi) di klaster uji lokal :55432. Urutannya naik, turun 2 langkah, lalu naik lagi. Hasilnya: 48 tabel data identik (hash per baris atas kolom lama), dan tabel `migrations` bertambah 2. Data lama terbaca kode baru: bagian v1, satu tahap, 405 berkas.

## 3. Kompatibilitas

- Alur lama tanpa `tahapan` dianggap satu tahap. Poin lama tanpa `tahap_periksa` dianggap tahap 1.
- Bagian ber-`alur` NULL tetap NULL bila alurnya tidak diubah.
- Isian Kegiatan tidak memakai alur generik. Poin menu `pengisian` ditolak (403) oleh pemeriksaan generik.
- Jenis kolom lama tidak berubah tafsir.
- Nilai referensi disimpan sebagai identitas data, dan label dibaca ulang saat tampil.
- Ganti sumber referensi atau pola kode ditolak bila nilai lama tidak cocok.
- Berkas Drive tidak disentuh.

## 4. Keterbatasan yang masih ada

- Belum ada draf konfigurasi terpisah dari versi terbit. Simpan langsung berlaku, dengan riwayat potret, nomor versi, dan penguncian konflik.
- Belum ada bukti dukung per kolom/subbagian. Bukti tetap per poin.
- Kolom Uraian bawaan belum bisa disembunyikan. Labelnya bisa diganti.
- Belum ada nilai unik (mis. kode risiko unik per periode).
- Subbagian bertingkat belum didukung (satu tingkat).
- Notifikasi per transisi alur belum ada.
- Matriks hak per status belum ada. Penguncian per status mengikuti alur: diajukan terkunci, disetujui terkunci kecuali diizinkan.
