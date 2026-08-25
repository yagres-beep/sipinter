# PROMPT — Penyempurnaan Generator Notula Bagian I (SIPINTER)

> Tempelkan seluruh isi berkas ini ke asisten coding (Claude Code / Cursor) yang dibuka
> di root proyek `D:\Latsar\sipinter`, atau berikan ke developer sebagai spesifikasi tugas.

---

## Konteks proyek (jangan diubah arsitekturnya)

- Stack: **Laravel 11 + Livewire (Blade), PostgreSQL (Supabase), PDF via dompdf**, konversi berkas via LibreOffice headless.
- Bagian I notula **disusun otomatis dari data terverifikasi**, BUKAN dari mem-parse template `.docx`.
  Template `.docx` yang diunggah Tim SAKIP **hanya arsip format resmi** (lihat `app/Livewire/TemplateNotula.php`) — biarkan tetap begitu.
- Berkas kunci yang akan disentuh:
  - `app/Services/NotulaService.php` → method `susunBagianSatu(Notula $notula)` (menyiapkan data & merender view).
  - `resources/views/pdf/notula-bagian1-konten.blade.php` → tampilan konten Bagian I (tempat banyak field masih tampil `…`).
  - `app/Models/Notula.php` → model notula (skala triwulan, dianchor ke periode bulan pertama).
  - `app/Livewire/KompilasiNotula.php` + view `resources/views/livewire/kompilasi-notula.blade.php` → halaman tempat Tim SAKIP menyusun & menyunting Bagian I.
- Sumber data yang sudah dipakai generator: `MasterIku`, `CapaianTahunan`, `Capaian`, `KendalaSolusi`, `RtlEvaluasi`, `Kegiatan`, `BagianKustom`.
- Aturan angka capaian ada di dokumen proyek `SIPINTER_Rumus_Capaian_Kinerja.md`: rasio realisasi/target ×100, **batas maksimum 120%**, realisasi 0 atas target > 0 ditampilkan **strip `−`** (bukan 0%), dan **baris strip dikecualikan** saat menghitung rata-rata.

## Tujuan

Buat Bagian I ter-generate lengkap dan rapi sesuai format resmi, **tanpa lagi menampilkan `…` untuk data yang bisa diisi**, dengan penomoran IKU otomatis dan input metadata rapat. Kerjakan tugas berurutan di bawah. Setiap tugas punya *acceptance criteria* — penuhi semuanya.

---

## Tugas 1 — Penomoran IKU otomatis (No. bertambah sesuai jumlah IKU)

**Masalah:** pada tabel capaian per sasaran di `notula-bagian1-konten.blade.php`, kolom pertama menampilkan `{{ $iku->kode }}`, bukan nomor urut yang bertambah.

**Lakukan:**
1. Di `resources/views/pdf/notula-bagian1-konten.blade.php`, pada tabel capaian per sasaran:
   - Ubah header kolom pertama menjadi `No.`.
   - Di dalam `@foreach ($daftarIku as $iku)`, ganti isi sel pertama menjadi nomor urut Blade:
     `{{ $loop->iteration }}` (nomor **dimulai ulang dari 1 di setiap tabel sasaran** — sesuai format resmi BPS).
   - Kode IKU tetap tampil pada judul blok analisis di bawahnya (`{{ $iku->kode }} — {{ $iku->indikator }}`), jadi informasi kode tidak hilang.
2. Jika diinginkan penomoran **berlanjut lintas sasaran** (1..N total), gunakan variabel penghitung manual (mis. `@php $no = 0; @endphp` sebelum loop sasaran dan `{{ ++$no }}` di tiap baris IKU) — pilih salah satu dan konsisten. **Default: mulai ulang per sasaran.**

**Acceptance criteria:**
- Kolom pertama tabel = nomor urut 1, 2, 3, … sesuai jumlah IKU pada sasaran tersebut.
- Tidak ada IKU yang terlewat atau bernomor sama dalam satu tabel.
- Tampilan tabel & border tetap rapi saat dirender dompdf.

---

## Tugas 2 — Input Detail Rapat (Hari/Tanggal, Waktu, Tempat, Pimpinan Rapat)

**Masalah:** di view, baris Hari/Tanggal, Waktu, Tempat, Pimpinan Rapat masih hardcoded `…` karena datanya belum ditangkap.

**Lakukan:**
1. **Migrasi** (PostgreSQL): tambah kolom nullable pada tabel `notula`:
   `hari_tanggal (string)`, `waktu (string)`, `tempat (string)`, `pimpinan_rapat (string)`.
   Buat lewat `php artisan make:migration tambah_detail_rapat_ke_notula` lalu isi `up()`/`down()`.
2. **Model** `app/Models/Notula.php`: tambahkan keempat kolom ke `$fillable`.
3. **Form input** di `app/Livewire/KompilasiNotula.php` + view-nya:
   - Tambahkan properti publik untuk keempat field, muat nilainya dari `Notula` saat komponen di-mount, dan sediakan aksi `simpanDetailRapat()` yang menyimpan ke model (validasi: string nullable, maks 255).
   - Tampilkan blok form "Detail Rapat" di halaman Kompilasi Notula (di atas pratinjau Bagian I).
4. **View PDF** `notula-bagian1-konten.blade.php`: ganti `…` pada baris Hari/Tanggal, Waktu, Tempat, Pimpinan Rapat dengan nilai dari `$notula` (fallback tetap `…` bila kosong). Pastikan `susunBagianSatu()` mengirim `$notula` (atau keempat nilai) ke view.

**Acceptance criteria:**
- Tim SAKIP bisa mengisi & menyimpan Hari/Tanggal, Waktu, Tempat, Pimpinan Rapat di halaman Kompilasi Notula.
- Nilai tersimpan muncul di Bagian I (dan ikut ke PDF). Kosong → tetap `…`.
- Migrasi punya `down()` yang menghapus kolom; `php artisan migrate` & `migrate:rollback` jalan tanpa error.

---

## Tugas 3 — Ringkasan capaian otomatis (ganti "… persen")

**Masalah:** paragraf pembuka menampilkan capaian "sebesar **… persen**" untuk target triwulanan dan tahunan, padahal angkanya bisa dihitung dari `rekapPerIku`.

**Lakukan (di `NotulaService::susunBagianSatu()`):**
1. Dari `rekapPerIku`, hitung **rata-rata `capaian_tw`** dan **rata-rata `capaian_pk`** HANYA atas IKU yang **punya nilai** (kecualikan yang strip `−`/null). Gunakan nilai penuh lalu bulatkan 2 desimal di akhir (format koma Indonesia, mis. `98,50`) — konsisten dengan `PengaturanCapaian::formatPersen`.
2. Kirim kedua angka itu ke view (mis. `rataCapaianTw`, `rataCapaianPk`).
3. Di `notula-bagian1-konten.blade.php`, ganti kedua `…` pada paragraf pembuka dengan variabel tersebut (fallback `…` bila tak ada satu pun IKU bernilai).

**Acceptance criteria:**
- Paragraf pembuka menampilkan angka rata-rata capaian (bukan `…`) bila minimal satu IKU punya nilai.
- Baris strip `−` tidak ikut menurunkan rata-rata (dikecualikan).
- Angka konsisten dengan yang tampil di Dasbor Capaian.

---

## Tugas 4 — (Opsional, prioritas lebih rendah) Lengkapi sisa field "…"

Kerjakan bila waktu memungkinkan; masing-masing berdiri sendiri:

- **Realisasi Volume RO & Progres (%)**: tambah kolom `volume_ro` (string) & `progres_persen` (numeric, nullable) pada `Kegiatan` + input di form kegiatan/verifikasi; tampilkan di tabel bersarang (ganti `…`).
- **Dasar Hitung & Basis Data Realisasi IKU**: tambah kolom `dasar_hitung` / `basis_data` pada `MasterIku`; isi via form Master IKU; tampilkan.
- **Tautan Bukti Dukung Tindak Lanjut Triwulan Sebelumnya**: ambil link folder Drive bukti RTL triwulan sebelumnya memakai pola yang sama seperti `FolderStructureService::linkBuktiDukungIku()`; suntikkan ke view.
- **Penjelasan/pembahasan lainnya (per IKU)**: field catatan opsional per IKU per triwulan (kolom baru di `Capaian` atau tabel catatan); tampilkan bila terisi.

**Acceptance criteria:** field yang dikerjakan tidak lagi `…` bila datanya ada; bila kosong tetap `…`.

---

## Tugas 5 — (Opsional) Validasi penanda saat unggah template

Di `app/Livewire/TemplateNotula.php`, setelah unggah berhasil, **beri peringatan (bukan error keras)** bila arsip `.docx` tidak memuat kelima penanda `{{iku}}`, `{{analisis_capaian}}`, `{{kendala_solusi_kumulatif}}`, `{{rtl}}`, `{{ttd_kepala}}` — agar arsip tetap acuan format yang sahih. (Baca teks docx via ekstraksi sederhana; jangan menambah dependensi berat.)

---

## Batasan & aturan pengerjaan

- **Jangan** mengubah alur arsitektur: template `.docx` tetap arsip, Bagian I tetap digenerate dari data → dompdf. **Jangan** menambah pustaka parsing docx untuk mengisi notula.
- Ikuti konvensi kode yang ada: penamaan Bahasa Indonesia untuk method/komentar domain, gaya Livewire & Blade yang sudah dipakai.
- Semua perubahan DB lewat **migration** (dengan `down()`), kompatibel PostgreSQL.
- Jangan merusak data historis: `susunBagianSatu()` menimpa `bagian1_html` hanya saat "Susun Ulang Otomatis" ditekan — pertahankan perilaku ini.
- Setelah selesai: jalankan `php artisan migrate`, lalu `php artisan test` — **seluruh test harus tetap lulus** (khususnya `tests/Feature/KompilasiNotulaTest.php`, `NotulaDownloadTest.php`, `TemplateNotulaTest.php`). Tambahkan test secukupnya untuk fitur baru (penomoran & detail rapat).
- Render satu PDF Bagian I contoh dan pastikan: penomoran benar, detail rapat & ringkasan capaian terisi, tidak ada `…` untuk field yang datanya ada, tabel & border rapi.

## Format keluaran yang diharapkan dari asisten

1. Ringkasan perubahan per berkas.
2. Diff/kode setiap berkas yang diubah atau dibuat (migration, model, Livewire, Blade, service, test).
3. Perintah yang perlu dijalankan (`php artisan migrate`, `php artisan test`).
4. Catatan verifikasi (hasil test + cara mengecek PDF).
