# PROMPT — Notula Menyatu (Satu Dokumen Mengalir) + TTD di Akhir (SIPINTER)

> Tempelkan seluruh isi ini ke asisten coding (Claude Code / Cursor) di root proyek
> `D:\Latsar\sipinter`, atau berikan ke developer. **Prompt ini melengkapi**
> `PROMPT_Penyempurnaan_Generator_Notula.md` (penomoran IKU, detail rapat, ringkasan capaian) —
> kerjakan prompt itu lebih dulu bila belum.

---

## Konteks & masalah

Saat ini (lihat `app/Services/NotulaService.php`):
- Bagian I dirender ke PDF sendiri, Bagian II & III **diunggah sebagai file lalu disimpan sebagai PDF terpisah** (`bagian2_pdf`, `bagian3_pdf`), lalu **digabung dengan `PdfMergeService->merge([...])`**.
- Penggabungan PDF hanya menyambung **halaman demi halaman**, sehingga **tiap bagian selalu mulai di halaman baru** dan tidak bisa menyambung di sisa ruang halaman sebelumnya.
- Blok TTD saat ini ikut pada Bagian I (`renderBagianSatuPdf(..., sertakanTtd: true)`) dan digabung pertama → **TTD keluar sebelum Bagian II & III** (salah posisi).

## Tujuan

1. **Satu dokumen mengalir**: seluruh notula (Bagian I + II + III + TTD) dirender **sekali** sebagai satu dokumen HTML → satu PDF (dompdf). Bila akhir Bagian I masih ada ruang, Bagian II **menyambung di halaman yang sama**, begitu seterusnya.
2. **TTD Kepala & Notulis di paling akhir**, menyambung setelah Bagian III.
3. **Pertahankan format asli** berkas unggahan Bagian II & III (spasi, huruf, dll.) **sebisa mungkin**; reformat hanya bila render benar-benar rusak. (Instruksi pemilik: "biarkan asli dulu, baru kalau perlu boleh diubah".)

## Prinsip solusi

Ubah Bagian II & III dari "file PDF yang digabung" menjadi **konten HTML inline** dalam satu view utuh yang dirender dompdf. Tim SAKIP **tetap boleh mengunggah file**; sistem yang mengonversinya menjadi konten inline.

---

## Tugas 1 — Konversi unggahan Bagian II & III menjadi konten inline

Di `NotulaService::terimaUploadBagian()` (dan penunjang), ganti alur "simpan sebagai PDF" menjadi "konversi ke HTML/aset inline", dengan **mempertahankan format asli**:

- **.docx / .xlsx** → konversi ke HTML dengan LibreOffice headless: `soffice --headless --convert-to html`. Sanitasi HTML (buang script/style berbahaya, tetap pertahankan tabel, font-size, spasi, bold/italic). Simpan sebagai `bagian2_html` / `bagian3_html` (kolom TEXT baru di tabel `notula`). Gambar di dalamnya di-embed sebagai `data:` URI agar dompdf bisa merender tanpa berkas eksternal.
- **Gambar (.jpg/.png)** → embed sebagai `<img>` inline (data URI) — format asli terjaga persis; mengalir sebagai blok.
- **PDF unggahan** → tiap halaman dirasterisasi jadi gambar (`pdftoppm -png` / Poppler, sudah ada di stack) lalu di-embed sebagai `<img>` inline berurutan — tampilan asli terjaga persis; mengalir sebagai blok gambar.
- Simpan juga berkas asli (untuk arsip/unduh ulang) bila diperlukan, tapi jalur **render menyatu memakai versi HTML/inline**.

> Catatan fidelitas (tulis sebagai komentar kode): rendering dompdf tidak 100% identik dengan Word;
> konten yang dirasterisasi (gambar/PDF) tidak bisa reflow teks — ia mengalir sebagai blok gambar,
> sehingga bila lebih besar dari sisa ruang halaman akan pindah ke halaman berikutnya (perilaku wajar).
> Untuk "menyambung" paling mulus, konten teks (docx/xlsx) sebaiknya jalur HTML (reflow), bukan rasterisasi.

**Acceptance criteria:** setelah unggah Bagian II/III, tersimpan `bagian2_html`/`bagian3_html` yang siap ditempel inline; RF-42e (ganti/unggah ulang → regenerasi) tetap jalan.

## Tugas 2 — View notula utuh (satu render, mengalir)

Buat Blade baru, mis. `resources/views/pdf/notula-utuh.blade.php`, yang menyusun berurutan **dalam satu aliran**:
1. Konten Bagian I = `{!! $notula->bagian1_html !!}` (sudah termasuk suntingan Tim SAKIP).
2. Konten Bagian II = `{!! $notula->bagian2_html !!}`.
3. Konten Bagian III = `{!! $notula->bagian3_html !!}`.
4. **Blok TTD** (lihat Tugas 3).

Aturan CSS penting:
- **Jangan** memberi `page-break-before` antar bagian → biar mengalir menyambung. Pakai pemisah ringan (mis. judul "Bagian II", garis tipis, atau jarak) tanpa memaksa halaman baru.
- Bungkus tiap bagian dengan wrapper agar rapi, tapi tanpa `page-break`.

**Acceptance criteria:** bila akhir Bagian I masih ada ruang, awal Bagian II tampil di halaman yang sama (untuk konten HTML/teks).

## Tugas 3 — Pindahkan TTD ke akhir dokumen

- Hapus penyertaan TTD dari Bagian I: ubah `renderBagianSatuPdf(..., sertakanTtd)` sehingga Bagian I **tidak lagi** memuat blok TTD (atau hentikan pemakaiannya pada jalur menyatu).
- Tambahkan **Blok TTD sebagai elemen terakhir** pada `notula-utuh.blade.php`: dua kolom "Mengetahui, Kepala [Satker] — (ttd)/nama Kepala/tanggal persetujuan" dan "Tempat, Tanggal — Notulis/nama notulis". Tampilkan blok ini **hanya untuk versi final** (setelah Kepala menyetujui); untuk draf, kosong/hilangkan sesuai RF-43.
- Beri `page-break-inside: avoid;` **hanya pada blok TTD** agar tidak terpotong dua halaman, tetapi tetap mengalir menyambung setelah Bagian III bila muat.

**Acceptance criteria:** TTD Kepala & Notulis muncul **sekali di paling akhir**, setelah Bagian III; menyambung di halaman yang sama bila muat, kalau tidak pindah utuh ke halaman berikutnya.

## Tugas 4 — Ganti penggabungan PDF dengan render tunggal

Di `NotulaService`:
- `gabungkan(Notula $notula)` (draf): alih-alih `PdfMergeService->merge([...])`, render `pdf.notula-utuh` (tanpa TTD final) langsung ke PDF via `PdfFacade::loadView(...)`, simpan ke `pdf_gabungan` seperti sekarang. Syarat kelengkapan (Bagian II & III sudah ada) tetap berlaku.
- `setujui(...)` (final): render `pdf.notula-utuh` **dengan** blok TTD final, simpan ke `pdf_final`, lalu arsipkan ke Drive (RF-44a) seperti sekarang.
- `PdfMergeService` boleh dipensiunkan dari alur ini (atau disimpan untuk keperluan lain).

**Acceptance criteria:** unduh draf & unduh final sama-sama menghasilkan satu PDF mengalir; alur persetujuan/arsip Drive tetap berjalan.

## Tugas 5 — Migrasi & pembersihan

- Migrasi: tambah kolom `bagian2_html` (TEXT, nullable), `bagian3_html` (TEXT, nullable) pada tabel `notula`; dengan `down()`. (Kolom `bagian2_pdf`/`bagian3_pdf` boleh dipertahankan untuk arsip berkas asli, atau ditandai deprecated.)
- Update `Notula` `$fillable`/`casts` sesuai.

---

## Batasan & aturan

- **Pertahankan format asli** konten unggahan sebisa mungkin; reformat hanya bila render rusak.
- Tetap memakai **dompdf**; manfaatkan **LibreOffice headless** & **Poppler/pdftoppm** yang sudah ada.
- Semua aset gambar di-embed sebagai **data URI** (dompdf + tanpa berkas eksternal).
- Semua perubahan DB lewat **migration** (dengan `down()`), kompatibel PostgreSQL.
- Pertahankan: penyuntingan Bagian I (`bagian1_html`), RF-42e (ganti Bagian II/III → regenerasi), status draf→persetujuan→final, arsip Drive final (RF-44a).
- Setelah selesai: `php artisan migrate` lalu `php artisan test` — **semua test lulus**. Sesuaikan test yang mengasumsikan penggabungan PDF (mis. `tests/Feature/KompilasiNotulaTest.php`, `NotulaDownloadTest.php`, `NotulaSetujuiTest.php`). Tambah test: TTD hanya muncul sekali di akhir; Bagian II/III ter-embed sebagai HTML/inline.
- Render satu PDF final contoh dan verifikasi: aliran menyambung, TTD di akhir, format konten unggahan tampak sesuai asli.

## Format keluaran yang diharapkan dari asisten

1. Ringkasan perubahan per berkas.
2. Diff/kode tiap berkas (migration, `Notula`, `NotulaService`, view `notula-utuh`, konversi unggahan, test).
3. Perintah (`php artisan migrate`, `php artisan test`).
4. Catatan verifikasi (hasil test + cara cek PDF menyatu & posisi TTD).

---

### Catatan penting untuk pemilik (bukan bagian instruksi coding)

- "Menyambung di halaman yang sama" bekerja mulus untuk **konten teks/HTML** (Bagian I selalu HTML; Bagian II/III bila dikonversi ke HTML). Untuk konten yang **dirasterisasi** (gambar atau PDF hasil pindai), ia mengalir sebagai **blok gambar** — bila blok lebih besar dari sisa ruang, ia pindah ke halaman berikutnya. Itu batas wajar rendering, bukan bug.
- Menjaga "format asli persis" (rasterisasi) dan "menyambung reflow" agak bertolak belakang. Rekomendasi: untuk Bagian II/III berupa **dokumen teks (docx/xlsx)** pakai jalur **HTML** (reflow, menyambung; format ~mirip asli). Rasterisasi hanya untuk berkas yang wajib tampil persis (mis. tanda tangan basah hasil scan).
