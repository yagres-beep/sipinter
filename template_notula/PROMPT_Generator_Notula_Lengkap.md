# PROMPT — Notula SIPINTER: Bagian I Ter-generate Sempurna + Bagian II/III (Word) Diunggah & Digabung

> Tempelkan SELURUH isi berkas ini ke asisten coding (Claude Code / Cursor) yang dibuka di root
> proyek `D:\Latsar\sipinter`, atau berikan ke developer sebagai spesifikasi tugas.
>
> **Alur yang diinginkan pemilik (FINAL):**
> - **Bagian I (Capaian Kinerja)** → TER-GENERATE SEMPURNA di dalam sistem dari data terverifikasi,
>   tanpa perlu disunting di Word. Ini fokus utama pekerjaan.
> - **Bagian II (Prioritas & Isu Strategis) & Bagian III (Realisasi Anggaran, Efisiensi, Pembahasan)**
>   → **dibuat di luar sistem (Word)** memakai template resmi yang disediakan, lalu **diunggah** ke
>   halaman Kompilasi Notula, dan **digabung** sistem menjadi satu dokumen mengalir dengan TTD di akhir.
>   **JANGAN digenerate dari data.** Pertahankan alur unggah yang sudah ada.
>
> Template Word untuk diisi user: `template_notula/SIPINTER_Template_Bagian_II_Prioritas.docx` dan
> `template_notula/SIPINTER_Template_Bagian_III_Anggaran.docx`. Arsip format resmi lengkap:
> `template_notula/SIPINTER_Template_Notula_Lengkap.docx`.

---

## 0. Konteks proyek — JANGAN ubah arsitektur ini

- Stack: **Laravel 11 + Livewire (Blade), PostgreSQL (Supabase), PDF via dompdf**, konversi berkas via **LibreOffice headless** + **Poppler/pdftoppm**.
- **Template `.docx` yang diunggah Tim SAKIP di menu Template Notula hanya ARSIP format resmi** (`app/Livewire/TemplateNotula.php`, validasi `mimes:docx`) — TIDAK di-parse. Jangan tambah pustaka templating docx.
- **Bagian I** disusun otomatis dari data → HTML → dompdf: `NotulaService::susunBagianSatu()` + `resources/views/pdf/notula-bagian1-konten.blade.php`.
- **Bagian II & III** = **berkas UNGGAHAN** (`NotulaService::terimaUploadBagian()`), dikonversi jadi konten inline (docx/xlsx→HTML via LibreOffice; gambar/PDF→rasterisasi), disimpan di `bagian2_html`/`bagian3_html`, lalu dirender menyatu di `resources/views/pdf/notula-utuh.blade.php` (Bagian I → II → III → TTD).
- Aturan angka capaian: dokumen proyek **`SIPINTER_Rumus_Capaian_Kinerja.md`** — capaian = realisasi/target ×100, **maks 120%**, realisasi 0 atas target > 0 = **strip `−`** (bukan 0%), **baris strip dikecualikan** dari rata-rata. Format 2 desimal koma (`91,67`).

---

## FASE 0 — Prasyarat (kerjakan bila belum tuntas)

Ringkas; detail ada di `PROMPT_Penyempurnaan_Generator_Notula.md` dan `PROMPT_Notula_Menyatu_dan_TTD_Akhir.md`. Pastikan tercapai:
- **F0.1** Penomoran IKU otomatis pada tabel capaian per Sasaran (`{{ $loop->iteration }}`, mulai ulang per Sasaran).
- **F0.2** Detail Rapat: kolom `hari_tanggal`, `waktu`, `tempat`, `pimpinan_rapat` di tabel `notula` + form "Detail Rapat" di Kompilasi Notula → tampil di Bagian I (fallback `…`).
- **F0.3** Ringkasan capaian otomatis: `rataCapaianTw`/`rataCapaianPk` (kecualikan strip `−`) menggantikan "… persen" pada paragraf pembuka.
- **F0.4** Satu dokumen mengalir (`notula-utuh`): Bagian I (`bagian1_html`) → II (`bagian2_html`) → III (`bagian3_html`) → **blok TTD Kepala & Notulis HANYA di paling akhir**, versi final saja. Unggahan II/III dikonversi ke HTML inline (docx/xlsx) atau gambar inline (jpg/png/PDF) agar bisa menyambung.

Jika sudah selesai, lanjut Fase 1.

---

## FASE 1 — Bagian I ter-generate SEMPURNA (hilangkan semua `…` yang datanya tersedia)

Inilah inti permintaan. Di `resources/views/pdf/notula-bagian1-konten.blade.php` masih banyak field `…`. Lengkapi sumber datanya. Semua perubahan DB via **migration** ber-`down()`, kompatibel PostgreSQL; update `$fillable`/`casts` model.

- **F1.1 Realisasi Volume RO & Progres (%)** — tambah kolom `volume_ro` (string, nullable) & `progres_persen` (numeric, nullable) pada `Kegiatan`; input di form kegiatan/verifikasi; isi tabel bersarang "Rincian Output" (ganti kedua `…`).
- **F1.2 Dasar Hitung & Basis Data Realisasi IKU** — tambah kolom `dasar_hitung` (text, nullable) & `basis_data` (string, nullable) pada `MasterIku`; input di form Master IKU; tampilkan pada barisnya (ganti `…`).
- **F1.3 Tautan Bukti Dukung Tindak Lanjut TW Sebelumnya** — ambil link folder Drive bukti RTL triwulan sebelumnya dengan pola `FolderStructureService::linkBuktiDukungIku()` (untuk `triwulan-1`); suntik ke view (fallback `…`).
- **F1.4 Penjelasan/pembahasan lainnya per IKU** — kolom `catatan` (text, nullable) pada `Capaian`; input opsional di Detail Verifikasi; tampil bila terisi (fallback `…`).
- **F1.5 Detail rapat & ringkasan** — pastikan F0.2 & F0.3 sudah mengisi Hari/Tanggal, Waktu, Tempat, Pimpinan Rapat, dan kedua angka ringkasan (bukan `…`).

**Acceptance:** untuk IKU dengan data lengkap, Bagian I **tidak menampilkan `…` sama sekali**; field yang memang kosong tetap `…`. Angka capaian & ringkasan konsisten dengan Dasbor Capaian. `susunBagianSatu()` tetap menimpa `bagian1_html` **hanya** saat "Susun Ulang Otomatis" ditekan (jaga suntingan/data historis).

---

## FASE 2 — Perkuat alur unggah & gabung Bagian II/III (BUKAN generate)

Bagian II & III tetap **dibuat di Word lalu diunggah**. Tugas di sini hanya memastikan alur unggah→konversi→gabung mulus dan tahan banting.

- **F2.1 Slot unggah jelas** di halaman Kompilasi Notula: dua unggahan terpisah — **"Bagian II — Prioritas & Isu Strategis"** dan **"Bagian III — Realisasi Anggaran & Pembahasan"** (`terimaUploadBagian($notula, 2|3, $file)`). Terima `docx, doc, xlsx, xls, odt, ods, pdf, jpg, jpeg, png` (sesuai `konversiKeKontenInline()`); tampilkan pratinjau PDF (iframe) yang sudah ada.
- **F2.2 Tautan unduh template** di sisi tiap slot: sediakan tombol/link unduh `SIPINTER_Template_Bagian_II_Prioritas.docx` & `SIPINTER_Template_Bagian_III_Anggaran.docx` (letakkan di `storage`/`public` lalu route unduh), supaya Tim SAKIP mengisi format yang benar sebelum unggah.
- **F2.3 Fidelitas konversi** — untuk **docx** pakai jalur **HTML (reflow)** agar menyambung di sisa ruang halaman; sanitasi HTML (buang script/style berbahaya, pertahankan tabel/border/bold/italic/spasi); gambar di dalamnya di-embed **data URI**. (Sudah ada di `LibreOfficeConversionService::convertToHtml()` — pastikan dipakai untuk docx.)
- **F2.4 Ganti berkas → regenerasi** (RF-42e): unggah ulang Bagian II/III membatalkan hasil gabungan lama (`tandaiPerluDigabungUlang()`).
- **F2.5 Gabung & TTD** — `gabungkan()` (draf, tanpa TTD) & `setujui()` (final, dengan TTD di akhir) merender `pdf.notula-utuh` sekali → satu PDF mengalir; alur status draf→persetujuan→final & arsip Drive final (RF-44a) tidak berubah.

**Acceptance:** Tim SAKIP mengunduh template II/III, mengisinya di Word, mengunggah; sistem menghasilkan **satu PDF mengalir** Bagian I (tergenerate) → II → III (unggahan) → TTD di akhir. Ganti berkas memicu gabung ulang. Bagian II/III tampil mendekati format aslinya.

---

## Batasan & aturan pengerjaan (WAJIB)

- **JANGAN** menggenerate Bagian II/III dari data, **JANGAN** mem-parse `.docx`, **JANGAN** menambah pustaka templating docx. Bagian II/III **selalu** lewat unggahan.
- Semua perubahan DB via **migration** ber-`down()`, kompatibel PostgreSQL.
- Ikuti konvensi kode yang ada (penamaan & komentar domain Bahasa Indonesia, gaya Livewire/Blade).
- Aset gambar di-embed **data URI** (dompdf tanpa berkas eksternal).
- Setelah selesai: `php artisan migrate` lalu **`php artisan test` — SEMUA test lulus** (khususnya `KompilasiNotulaTest`, `NotulaDownloadTest`, `NotulaSetujuiTest`, `TemplateNotulaTest`). Tambah test: (a) field Bagian I terisi (volume RO, dasar hitung, bukti RTL TW sebelumnya, catatan) muncul & tidak `…`; (b) unggah Bagian II/III → tergabung; (c) ganti berkas → gabung ulang; (d) TTD sekali di akhir.
- Render **satu PDF final contoh** dan verifikasi kasat mata: Bagian I tanpa `…` untuk field terisi, penomoran benar, ringkasan capaian terisi; Bagian II/III tampil dari unggahan; TTD di akhir; tabel & border rapi.

## Format keluaran yang diharapkan dari asisten

1. Ringkasan perubahan per berkas.
2. Diff/kode tiap berkas (migration, model, Livewire, Blade, service, test, route unduh template).
3. Perintah (`php artisan migrate`, `php artisan test`).
4. Catatan verifikasi (hasil test + cara cek PDF menyatu, posisi TTD, kelengkapan Bagian I).

---

### Urutan eksekusi yang disarankan
Fase 0 (bila perlu) → Fase 1 (inti: Bagian I sempurna) → Fase 2 (perkuat unggah/gabung II/III). Commit per fase; akhiri tiap fase dengan `php artisan test` hijau.
