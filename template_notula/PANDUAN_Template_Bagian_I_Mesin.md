# Panduan `SIPINTER_Template_Bagian_I_Mesin.docx`

Berkas ini adalah **template mesin** yang dipakai `App\Services\NotulaBagian1DocxService`
untuk menghasilkan unduhan Bagian I (.docx) di halaman Kompilasi Notula (tombol
"⬇ Unduh Bagian I (.docx, terisi otomatis)"), maupun pratinjau webnya (lewat konversi
LibreOffice, lihat `NotulaService::susunBagianSatu()`).

Berkas ini di repo cuma jadi **cadangan bawaan** (`DEFAULT_TEMPLATE_PATH`) — begitu Tim
SAKIP mengunggah berkas lewat fitur "Template Notula" di menu Pengaturan
(`App\Livewire\TemplateNotula`), berkas UNGGAHAN itu yang dipakai LANGSUNG sebagai
template aktif (lihat `NotulaBagian1DocxService::resolveTemplatePath()`), bukan cadangan
bawaan ini. Jadi struktur macro di bawah berlaku SAMA untuk kedua berkas — berkas yang
diunggah lewat Pengaturan juga wajib mengikutinya persis, dan sejak
`TemplateNotula::unggah()` sudah memanggil `validasiStrukturTemplate()`, berkas yang
penanda bloknya tidak lengkap akan ditolak saat diunggah (bukan baru ketahuan rusak saat
dipakai menyusun notula).

## Yang PENTING diketahui sebelum menyunting berkas ini

**Perubahan kode/jumlah Master IKU TIDAK PERNAH mengharuskan berkas ini disunting.**
Sebelum RF ini, template lama menaruh satu macro terpisah per kode IKU (mis.
`{{sasaran_1111}}`) yang dibakukan langsung di dalam dokumen — begitu Master IKU
diberi kode berbeda (mis. lewat impor ulang dari Excel), macro-nya tidak pernah cocok
lagi dan sel-selnya tetap kosong di berkas unduhan walau pratinjau web sudah benar.
Sekarang template hanya berisi **satu blok generik** yang digandakan otomatis di kode
(`NotulaBagian1DocxService::isiPerIkuDinamis()`) sekali per Master IKU yang ada saat
unduhan diminta — jadi selalu ikut kode/jumlah IKU yang berlaku saat itu, tanpa
menyentuh berkas ini sama sekali.

**Kapan berkas ini BENAR-BENAR perlu disunting:** hanya kalau **struktur dokumen resmi**
dari pusat berubah — misalnya ada kolom baru di tabel capaian, urutan bagian berubah,
atau ada jenis indikator khusus baru (di luar pola Proksi/BerAKHLAK yang sudah ada).

## Konvensi macro

Delimiter macro-nya `{{...}}` (bukan `${...}` bawaan PhpWord — lihat
`TemplateProcessor::setMacroChars('{{', '}}')` di awal `generate()`).

### Macro global (diisi sekali, dari data Notula/Periode — lihat `isiHeader()`/`isiPenutup()`)

`{{tahun}}`, `{{triwulan_angka}}`, `{{triwulan_sebelumnya}}` (romawi triwulan
sebelumnya, mis. "II" untuk TW III; "…" di TW I — boleh dipakai juga di dalam blok IKU),
`{{nama_satker}}`, `{{agenda_pembahasan}}`,
`{{hari_tanggal}}`, `{{waktu}}`, `{{tempat}}`, `{{pimpinan_rapat}}`,
`{{capaian_triwulanan_persen}}`, `{{capaian_pk_persen}}`, `{{lampiran_basis_data}}`,
`{{kotaTtd}}`, `{{tanggal}}`, `{{namaKepala}}`, `{{namaNotulis}}`, `{{bagian_2}}`,
`{{bagian_3}}` (dua yang terakhir SENGAJA dibiarkan sebagai kode mentah — jadi
penanda tempat Tim SAKIP menempelkan Bagian II/III yang disusun terpisah di luar
sistem).

`{{kotaTtd}}`/`{{tanggal}}`/`{{namaKepala}}`/`{{namaNotulis}}` ada di dalam SATU
tabel "Mengetahui/Kepala Badan Pusat Statistik Kabupaten Buton Utara/Notulis" tepat
setelah `{{bagian_3}}` — tabel ini (dicari lewat teks label "Kepala Badan Pusat
Statistik Kabupaten Buton Utara", lihat `NotulaBagian1DocxService::
hapusBlokTtdMandiri()`) DIBUANG TOTAL, bukan cuma dikosongkan, bila notula belum
berstatus disetujui Kepala (RF-44) ATAU saat dipakai membangun dokumen gabungan
Bagian I+II+III (yang punya blok TTD akhirnya sendiri, lihat `pdf.notula-utuh.
blade.php`). Jadi label tabel itu JANGAN diganti tanpa ikut menyesuaikan teks yang
dicari `hapusBlokTtdMandiri()`.

### Blok berulang per IKU: `{{iku_blok}}` ... `{{/iku_blok}}`

Bungkus **satu tabel penuh** (baris Sasaran + tabel capaian + Analisis Capaian Kinerja
+ RO/Proksi/BerAKHLAK + Kendala/Solusi/RTL + Dasar Hitung/Bukti Dukung). Isinya digandakan
sekali per Master IKU (urutan sasaran lalu kode, sama seperti pratinjau web) oleh
`NotulaBagian1DocxService::isiPerIkuDinamis()`. Di DALAM blok ini, macro-nya GENERIK
(tanpa akhiran kode) karena setiap salinan diisi lewat instance `TemplateProcessor`
sekali-pakai yang terpisah: `{{kode}}`, `{{indikator}}`, `{{sasaran}}`, `{{target_pk}}`,
`{{target_tw}}`, `{{realisasi_tw}}`, `{{capaian_tw}}`, `{{capaian_pk}}`,
`{{analisis_capaian}}`, `{{kendala}}`, `{{solusi}}`, `{{rtl}}`, `{{pic_rtl}}`,
`{{batas_waktu_rtl}}`, `{{dasar_hitung}}`, `{{bukti_realisasi}}`,
`{{bukti_rtl_sebelumnya}}`, `{{penjelasan_lainnya}}`, serta:

- `{{rtl_evaluasi}}` — Bagian 4 Isian Kegiatan: poin RTL yang direncanakan untuk
  triwulan notula ini (isinya sama dengan `{{rtl}}`).
- `{{bukti_rtl_evaluasi}}` — tautan folder Drive "Evaluasi-RTL" triwulan notula ini
  (tempat bukti realisasi Bagian 4 diunggah); "…" bila IKU tidak punya poin RTL.
- `{{rtl_berikutnya}}` — Bagian 5 Isian Kegiatan: poin RTL untuk triwulan berikutnya
  (TW IV → TW I tahun depan; draft yang belum diajukan tidak ikut).
- `{{batas_waktu_rtl_berikutnya}}` — akhir triwulan berikutnya, mis. "Desember 2026".

### Blok opsional setelah kotak "Analisis Capaian Kinerja"

- `{{blok_proksi}}` ... `{{/blok_proksi}}` — tabel "Indikator Proksi". Tampil untuk
  IKU **apa pun** yang punya Indikator Proksi isian Tim SAKIP (kartu "Indikator
  Proksi" → "+ Tambah Proksi" di Detail Verifikasi, disimpan per IKU per triwulan,
  `App\Models\IndikatorProksi`); dibuang total bila IKU tsb tidak punya proksi.
  TIDAK lagi dideteksi dari teks "SAKIP" — `{{blok_sakip}}`, `{{target_proksi}}`,
  `{{realisasi_proksi}}` sudah tidak dipakai (bila masih ada di template lama, blok
  `blok_sakip` dibuang saat notula disusun dan muncul peringatan saat unggah).
  Urutan tulisannya di template:

  ```
  {{blok_proksi}}                                  ← paragraf/baris sendiri
  Capaian IKU ini dijelaskan melalui ... :         ← judul bebas (teks biasa, opsional)
  ┌──────────────────┬──────────────────────────┬─────────────────────────────┐
  │ Indikator Proksi │ Target s.d Triwulan      │ Realisasi s.d Triwulan      │
  │                  │ {{triwulan_angka}}       │ {{triwulan_angka}}          │
  ├──────────────────┼──────────────────────────┼─────────────────────────────┤
  │ {{proksi_uraian}}│ {{proksi_target}}        │ {{proksi_realisasi}}        │ ← digandakan per proksi
  └──────────────────┴──────────────────────────┴─────────────────────────────┘
  {{/blok_proksi}}                                 ← paragraf/baris sendiri
  ```

  Penanda `{{blok_proksi}}`/`{{/blok_proksi}}` boleh berupa **baris tabel tersendiri**
  (gaya template bawaan) ATAU **paragraf di dalam sel** yang juga berisi konten lain
  (mis. di atas Kendala/Solusi, dengan tabel proksi bersarang di dalam sel) — dideteksi
  otomatis (`NotulaBagian1DocxService::unitPenanda()`). Baris isi tabel tidak perlu
  penanda sendiri seperti `{{ro_row}}`: baris yang memuat `{{proksi_uraian}}` itulah
  yang digandakan. Kolom kosong dicetak "…".

Dua varian berikut dideteksi dari TEKS indikator/realisasi:

- `{{blok_berakhlak}}` ... `{{/blok_berakhlak}}` — pertanyaan baku, tanpa tabel
  (indikator berteks "berakhlak").
- `{{blok_ro}}` ... `{{/blok_ro}}` — tabel "Realisasi Volume RO dan Progress
  Pelaksanaan Kegiatan", HANYA tampil bila IKU tsb belum punya realisasi triwulan
  berjalan (sama seperti kondisi `@elseif (empty($rekap['realisasi']))` di Blade).
  Di dalamnya ada blok baris berulang `{{ro_row}}` ... `{{/ro_row}}` (kolom
  `{{ro_uraian}}` / `{{ro_vol}}` / `{{ro_progres}}`), digandakan sekali per
  `Kegiatan` pada IKU & triwulan itu — sumbernya **sama** dengan tabel RO di
  pratinjau PDF (`Kegiatan::rincian_output` / `volume_ro` / `progres_persen`),
  BUKAN katalog kode RO tetap seperti versi lama.

Baris pembuka/penutup `blok_berakhlak`/`blok_ro`/`ro_row` adalah baris tabel (`<w:tr>`) tersendiri berisi
HANYA teks macro-nya — jangan digabung dengan baris konten lain, karena kode
menghapus/menyimpan baris ini persis berdasarkan posisi baris (lihat
`NotulaBagian1DocxService::splitOnMarkers()`, unit `'tr'`).

## Cara menyunting berkas ini dengan aman

Karena macro blok (`{{iku_blok}}`, `{{blok_ro}}`, dst.) harus persis sendirian dalam
satu paragraf/baris tabel, **jangan** menyunting lewat "Cari & Ganti" biasa di Word —
mudah memecah satu `<w:t>` jadi beberapa run dan macronya jadi tidak terbaca lagi.
Cara paling aman:

1. Ubah dulu tampilan/urutan/tabel di Word SEPERTI BIASA, TANPA memikirkan macro
   (fokus ke tata letak resmi dari pusat dulu).
2. Setelah tata letak final, sisipkan kembali baris/paragraf marker
   (`{{iku_blok}}`, `{{/iku_blok}}`, `{{blok_ro}}`, dst.) sebagai baris/paragraf baru
   yang HANYA berisi teks macro itu sendiri, lalu ganti label field yang perlu
   dinamis dengan macro generik di atas (mis. ganti teks kode IKU contoh dengan
   `{{kode}}`).
3. Uji dengan mengunduh Bagian I dari Kompilasi Notula untuk periode mana pun yang
   sudah punya beberapa Master IKU — pastikan tidak ada teks `{{...}}` mentah yang
   tersisa di hasil unduhan (tanda ada macro yang salah ketik/salah posisi).
4. `tests/Feature/NotulaDownloadTest.php` punya beberapa pengujian regresi (kode IKU
   yang bukan bawaan template, RO dari data Kegiatan sungguhan, baris proksi, format BerAKHLAK,
   detail rapat & TTD) — jalankan `php artisan test --filter=NotulaDownloadTest`
   setelah menyunting untuk memastikan tidak ada yang rusak.
