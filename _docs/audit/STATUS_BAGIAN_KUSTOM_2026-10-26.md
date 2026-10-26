# Status & Rilis — Bagian Kustom Dinamis (26 Oktober 2026, sesi kedua)

Lanjutan dari `AUDIT_BAGIAN_KUSTOM_DINAMIS_2026-10-26.md` (Tahap 2 & 3, sudah dirilis di `50f0728`). Bagian III Notula **tidak** disentuh dalam pekerjaan ini.

## 1. Audit awal (kondisi kode sebelum sesi ini)

| Fitur | Status awal | Tindakan sesi ini |
|---|---|---|
| Daftar, cari, filter, urut, detail, duplikasi, aktif/nonaktif | Selesai & teruji (rilis 50f0728) | Detail dilengkapi pembaca, aturan bukti, tampilan |
| Kode internal stabil, folder Drive terkunci | Selesai & teruji | — |
| Subbagian, 6 jenis kolom, validasi backend | Selesai & teruji | Ditambah tautan katalog & ringkasan nilai |
| Aturan bukti dukung | Sebagian (hanya saklar `bukti_wajib`, PDF, hanya Isian Kegiatan) | **Diimplementasikan** |
| Relasi antartriwulan | Sebagian (riwayat baca-saja di Isian Kegiatan) | **Diimplementasikan** |
| Katalog variabel | Belum (hanya `{kunci}` per bagian untuk notula) | **Diimplementasikan** |
| Bentuk tampilan (tabel/paragraf/ringkasan) | Belum | **Diimplementasikan** (UI web; notula tidak diubah) |
| Peran pembaca | Belum | **Diimplementasikan** |
| Hak akses bukti lewat URL | Bermasalah: semua Ketua Tim/Tim SAKIP bisa membuka berkas mana pun | **Diperbaiki** untuk bukti Bagian Kustom |
| Verifikasi | Selesai untuk Isian Kegiatan; menu lain langsung sah (keputusan pengguna) | Dipertahankan; lanjutan di Isian Kegiatan masuk alur verifikasi biasa |
| Log aktivitas & riwayat konfigurasi | Selesai | Ditambah: lanjutan, hapus bukti oleh pengisi, katalog variabel |
| Peringatan perubahan belum disimpan | Belum | **Diimplementasikan** |

## 2. Keputusan desain (diambil dari struktur data & kebutuhan)

- **Relasi antarperiode**: pola *objek baru yang terkait objek sebelumnya*.
  - "Lanjutkan" membuat poin baru dengan `lanjutan_dari_id` (asal) dan `akar_id` (identitas objek yang tetap).
  - Nilai disalin sebagai isian awal. Poin asal tidak pernah diubah.
  - Bukti tidak disalin dan tidak diunggah ulang.
  - Duplikasi dicegah oleh indeks unik `(lanjutan_dari_id, periode_id)` dan pemeriksaan "objek terbuka".
  - Cakupan: periode sebelumnya dalam tahun yang sama.
- **Aturan bukti** disimpan di `bagian_kustom.aturan_bukti` (JSON).
  - `NULL` = perilaku lama (PDF, wajib bila `bukti_wajib`, hanya di Isian Kegiatan).
  - `bukti_wajib` tetap diisi selaras untuk kode lama.
- **Hapus bukti oleh pengisi** (menu Isian Bagian Kustom): salinan Drive ke **Sampah**, bukan hapus permanen.
- **Katalog variabel** hanya menyatukan definisi. Nilai tetap disimpan per poin, sehingga tidak ada nilai ganda.
  - Kolom bertaut mengikuti definisi katalog saat dibaca.
  - Perubahan jenis atau opsi yang tidak cocok dengan nilai tersimpan ditolak.
- **Tampilan tabel** untuk formulir hanya di menu Isian Bagian Kustom/menu lain. Isian Kegiatan tetap kartu (form 6.000+ baris dengan alur verifikasi; tidak diubah bentuknya).

## 3. Perubahan database (semua aditif)

| Migrasi | Isi |
|---|---|
| `2026_10_26_100001_tambah_aturan_bukti_pembaca_tampilan_ke_bagian_kustom` | 3 kolom JSON nullable: `aturan_bukti`, `peran_pembaca`, `tampilan` |
| `2026_10_26_100002_tambah_relasi_antarperiode_ke_bagian_kustom_poin` | `lanjutan_dari_id`, `akar_id` (FK ke poin, nullable, tanpa aksi hapus), unik `(lanjutan_dari_id, periode_id)`, indeks `akar_id` |
| `2026_10_26_100003_buat_tabel_variabel_katalog` | Tabel baru `variabel_katalog` |

Tidak ada kolom atau tabel lama yang diubah tipe, diganti nama, atau dihapus. `down()` hanya membuang struktur baru.

## 4. Keterbatasan & pekerjaan lanjutan

- Bentuk tabel/paragraf berlaku di tampilan web; **notula tidak diubah** (Bagian I tetap daftar berbutir; Bagian III ditunda).
- Status objek (selesai / tidak relevan) belum ada. Objek yang tidak dilanjutkan cukup tidak diklik "Lanjutkan".
- Relasi antarperiode lintas tahun belum didukung.
- Bukti dukung per kolom belum ada; bukti melekat pada poin.
- Sumber data katalog saat ini hanya "isian pengguna".
- Verifikasi untuk bagian di luar Isian Kegiatan tetap "langsung sah", sesuai keputusan 26 Oktober.

## 5. Pekerjaan Bagian III Notula (ditunda, misi terpisah)

- Master Sasaran Kegiatan dan relasinya ke anggaran.
- Kolom PAGU Awal, PAGU Revisi, dan Realisasi per sasaran.
- Generator tabel anggaran Bagian III dan perubahan templat Bagian III.
- Integrasi data Bagian Kustom/katalog variabel ke Bagian III.

Katalog variabel dan bentuk tabel dari pekerjaan ini bisa menjadi fondasinya.
