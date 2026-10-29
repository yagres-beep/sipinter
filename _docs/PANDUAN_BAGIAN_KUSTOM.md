# Panduan Bagian Kustom untuk Tim SAKIP

Bagian Kustom adalah bagian isian tambahan (mis. "Manajemen Risiko", "Register Inovasi") yang dibuat dan diatur Tim SAKIP lewat website, tanpa mengubah kode program.

Semua pengaturan ada di **Data Master & Konfigurasi**:

- tab **🧩 Bagian Kustom** untuk mengatur bagian,
- tab **🔣 Katalog Variabel** untuk definisi kolom yang dipakai bersama,
- tab **📜 Log Aktivitas** untuk melihat jejak isian dan bukti.

---

## 1. Membuat bagian

1. Buka **Bagian Kustom → Tambah Bagian Baru**.
2. Isi **Nama Bagian**. **Kode internal** boleh dikosongkan; sistem membuatnya dari nama.
   - Kode tidak bisa diubah setelah bagian dibuat.
   - Nama boleh diganti kapan saja. Isian, bukti, dan folder Google Drive tetap sama walau namanya berubah.
3. Pilih **peran pengisi** dan **menu penempatan**. Menu yang tidak bisa dibuka peran itu otomatis nonaktif.
4. Untuk menyalin pengaturan bagian lain, pilih **Mulai dari → Salin pengaturan "…"**, atau klik **⧉ Duplikasi** di daftar.
   - Yang disalin hanya pengaturan.
   - Isian, bukti, riwayat, dan status verifikasi bagian sumber tidak ikut disalin.
5. Klik **＋ Tambah Bagian**. Editor bagian baru langsung terbuka.

## 2. Mengatur bagian (tombol ✎ Edit)

| Tab | Isi |
|---|---|
| ⚙️ Informasi, Peran & Penempatan | Nama, deskripsi, peran pengisi, **peran pembaca**, menu, dan kapan bagian wajib diisi |
| 🧾 Struktur Isian & Tampilan | Bentuk formulir, bentuk penyajian, label uraian, kolom, dan subbagian |
| 📎 Bukti Dukung | Aturan bukti dukung |
| 📄 Notula & Variabel | Pengaturan notula Bagian I (tidak berubah dari sebelumnya) |
| 👁 Pratinjau | Contoh tampilan formulir dan notula |
| 🕘 Riwayat | Jejak perubahan pengaturan |

- Klik **💾 Simpan Pengaturan** untuk menyimpan, atau **Batal ubah** untuk membuang perubahan.
- Bila ada perubahan yang belum disimpan, muncul tanda **"belum disimpan"**. Website juga meminta konfirmasi saat Anda menutup editor atau meninggalkan halaman.
- Bila Tim SAKIP lain mengubah bagian yang sama lebih dulu, simpanan Anda ditolak dan isian Anda tetap di layar. Klik "Batal ubah" untuk memuat versi terbaru, lalu ulangi perubahan Anda.

### Peran pengisi dan pembaca

- **Pengisi** dapat melihat dan mengubah isian. Data dipisah per menu dan peran: satu peran di satu menu berbagi data yang sama.
- **Pembaca** hanya melihat isian semua pengisi beserta bukti dukungnya. Pembaca tidak mendapat tombol simpan, dan tetap ditolak server bila mencoba menyimpan.
- Pembaca harus bisa membuka minimal satu menu tempat bagian ditempatkan.

### Kolom dan subbagian

- Kolom bisa diletakkan langsung di bagian atau di dalam subbagian.
- Jenis kolom: Teks pendek, Teks panjang, Angka, Tanggal, Pilihan tunggal, dan Pilihan ganda.
- Validasi per kolom: wajib, nilai minimal/maksimal, panjang minimal/maksimal, pilihan yang sah, dan pesan galat khusus. Semuanya diperiksa di server.
- Kolom angka bisa diatur:
  - satuan,
  - jumlah angka di belakang koma,
  - pemisah desimal (koma atau titik),
  - pemisah ribuan,
  - **ringkasan nilai**: jumlah, rata-rata, terkecil, atau terbesar.
- Format angka hanya mengubah tampilan. Nilai tersimpan tidak dibulatkan. Contoh: nilai tersimpan `62.5` bisa tampil sebagai `62,5`, `62,50000`, atau `62.50000`.
- **Jangan menghapus kolom yang sudah berisi data.** Hapus centang **Aktif** saja; data lamanya tetap ada. Sistem juga menolak penghapusan kolom yang berisi data.
- Mengganti jenis kolom hanya diizinkan bila semua data lama cocok dengan jenis baru. Nilai lama tidak pernah dikonversi otomatis.
- Subbagian yang masih berisi kolom tidak bisa dihapus. Nonaktifkan saja.
- Kolom baru pada bagian yang sudah berisi data otomatis berlaku mulai triwulan berjalan, supaya isian lama tidak dianggap kurang.

### Bentuk tampilan

- **Bentuk formulir isian**:
  - *Kartu*: satu kartu per poin.
  - *Tabel*: satu baris per poin. Di layar kecil, tabel bisa digeser ke samping.
  - Bentuk tabel berlaku di menu selain Isian Kegiatan. Isian Kegiatan selalu memakai kartu.
- **Bentuk penyajian isian tersimpan**: *Daftar berbutir*, *Tabel*, atau *Paragraf*.
  - Dipakai di ringkasan "Isian tersimpan" dan tampilan pembaca.
  - Tidak mengubah notula.

### Aturan bukti dukung (tab 📎)

- **Kebutuhan bukti**:
  - *Tidak memakai bukti dukung*,
  - *Opsional*,
  - *Wajib per poin yang diisi*.
- **Jumlah minimal dan maksimal** berkas per poin.
- **Format**: PDF, JPG/JPEG, PNG, Word (DOCX), dan Excel (XLSX). Isi berkas ikut diperiksa, bukan hanya nama berkasnya.
- **Ukuran maksimal** per berkas, dalam MB.
- **Petunjuk pengunggahan** untuk pengisi.
- Bukti disimpan ke Google Drive lewat mekanisme yang sama dengan bukti lain, di folder bagian yang namanya tetap.

Ketentuan bila aturan diubah:

- Bukti lama **tidak diubah, tidak dipindah, dan tidak dihapus**.
- Bila ada bukti lama yang tidak sesuai aturan baru (format lain, atau jumlahnya melebihi batas), sistem menampilkan catatan setelah Anda menyimpan dan peringatan di formulir isian.
- Bagian lama yang belum pernah diatur aturan buktinya tetap berperilaku seperti sebelumnya: PDF, dan hanya di Isian Kegiatan.

### Menonaktifkan, mengaktifkan, menghapus

- **Nonaktifkan**: bagian tidak tampil untuk diisi. Isian, bukti, log, dan riwayatnya tetap tersimpan dan tetap masuk notula.
- **Aktifkan kembali**: bagian tampil lagi.
- **Hapus** hanya tersedia bila bagian belum pernah punya isian.

## 3. Katalog variabel

Gunakan katalog bila beberapa bagian memakai definisi kolom yang sama, misalnya "Persentase tindak lanjut" (angka, 2 desimal, satuan %, maksimal 100).

1. Buka tab **🔣 Katalog Variabel → ＋ Variabel baru**.
2. Isi kode (tetap), nama, jenis, satuan, format angka, pilihan, dan batas, lalu simpan.
3. Di **Bagian Kustom → Struktur Isian**, pada kolom yang diinginkan, pilih **Variabel katalog**.
   - Jenis, pilihan, format, dan batas kolom kini mengikuti katalog.
   - Label, wajib, letak, dan petunjuk tetap diatur per bagian.

Ketentuan:

- Mengubah nama variabel tidak memutus tautan.
- Mengganti jenis atau membuang pilihan **ditolak** bila ada nilai tersimpan yang tidak cocok. Nilai lama tidak dikonversi otomatis.
- Variabel tidak dihapus, cukup dinonaktifkan:
  - kolom yang sudah memakainya tetap berfungsi,
  - variabel itu tidak bisa dipilih untuk kolom baru.
- Klik **👁 Pemakaian** untuk melihat bagian dan kolom yang memakai variabel.
- Nilai tetap tersimpan di isian masing-masing bagian. Katalog hanya menyatukan definisinya.

## 4. Isian antartriwulan (untuk pengisi)

Isian disimpan per bulan. Untuk meneruskan isian dari bulan atau triwulan sebelumnya pada tahun yang sama:

- **Isian Bagian Kustom / menu lain**: buka **"↪ … isian periode sebelumnya bisa dilanjutkan"**, lalu klik **Lanjutkan** (atau **Lanjutkan semua**). Ubah isinya bila perlu, lalu simpan.
- **Isian Kegiatan**: pada "Riwayat Kumulatif Triwulan Berjalan", klik **↪ Lanjutkan ke periode ini**, lalu Simpan Draf atau Ajukan.

Yang terjadi saat melanjutkan:

- Sistem membuat **poin baru** di periode ini yang terhubung ke poin sebelumnya. Identitas objeknya tetap sama di semua periode.
- Poin lama, bukti dukungnya, dan status verifikasinya **tidak diubah**.
- Bukti tidak disalin. Unggah bukti baru bila diperlukan.
- Satu poin hanya bisa dilanjutkan sekali ke periode yang sama, termasuk bila dua orang menekan Lanjutkan bersamaan.
- Isian baru yang ditambahkan di Triwulan III **tidak** muncul di Triwulan I atau II.
- Poin yang sudah dilanjutkan, atau sudah punya bukti, tidak ikut terhapus bila barisnya dihapus dari layar.

Isian di Isian Kegiatan tetap mengikuti alur verifikasi yang berlaku: Ketua Tim mengajukan, lalu Tim SAKIP menandai Sesuai atau Tidak Sesuai. Isian di menu lain langsung dianggap sah (keputusan 26 Oktober 2026).

## 5. Hak akses bukti dukung

Tautan bukti (`/berkas/{kode}/buka`) diperiksa server setiap kali dibuka:

- **Tim SAKIP** dapat membuka semua bukti.
- **Bukti Bagian Kustom** hanya bisa dibuka oleh peran pengisi konteks poin itu dan peran pembaca bagiannya.
- **Bukti lain** tetap seperti sebelumnya: Tim SAKIP dan Ketua Tim.

Menghapus bukti di menu Isian Bagian Kustom:

- Perlu konfirmasi lebih dulu.
- Salinan Google Drive dipindah ke **Sampah**, jadi masih bisa dipulihkan dari Drive.
- Bukti terakhir pada bagian yang mewajibkan bukti tidak bisa dihapus sebelum ada bukti penggantinya.

## 6. Jejak dan riwayat

- **Perubahan pengaturan bagian** tercatat di tab 🕘 Riwayat bagian itu: dibuat, disalin, diubah, aktif/nonaktif, urutan, dan dihapus.
- **Isian, lanjutan, bukti, penandaan verifikasi, perubahan katalog variabel, dan akses yang ditolak** tercatat di 📜 Log Aktivitas.
- Log tidak bisa diubah atau dihapus, dan tidak menyimpan kata sandi, token, atau kredensial.

## 7. Form builder dinamis (29 Okt 2026)

### Jenis kolom tambahan (tab 🧾 Struktur Isian)

- **Persentase (%)**: angka dengan satuan %. Isi batas minimal/maksimal bila perlu (mis. 0–100).
- **Tautan (URL)**: harus diawali `http://` atau `https://`.
- **Perhitungan otomatis**: isi rumus dari kolom Angka/Persentase, mis. `{realisasi} / {target} * 100`. Hasil dihitung server saat poin disimpan. Bila ada nilai kosong atau pembagian dengan nol, hasilnya kosong.
- **Tabel**: atur kolom di dalam tabel (Teks, Angka, Tanggal, Pilihan; masing-masing bisa wajib) serta jumlah baris minimal/maksimal. Pengisi menambah dan menghapus baris sendiri. Di notula, tabel dicetak per baris: `1) Kolom: isi; … | 2) …`.

Dua bagian bisa punya struktur dan tabel yang berbeda sama sekali.

### Keterlihatan bersyarat dan subbagian wajib

- Setiap kolom dan subbagian bisa diatur **Tampilkan bila "kolom X" bernilai / tidak bernilai / sudah diisi**. Kolom yang tersembunyi tidak wajib diisi dan isiannya tidak disimpan.
- Centang **Wajib diisi** pada subbagian agar minimal satu isian di subbagian itu wajib diisi.

### Alur kerja dan periode (tab 🔁 Alur Kerja & Periode)

- **Langsung sah** (bawaan): isian sah begitu disimpan.
- **Diajukan lalu diperiksa**: pengisi menyimpan draf, lalu klik **Ajukan**. Pemeriksa (mis. Tim SAKIP) melihat panel **Pemeriksaan** di menu yang sama, lalu **Setujui** atau **Kembalikan** dengan catatan. Poin yang diajukan terkunci. Pengaju bisa **Tarik pengajuan** selama belum diperiksa.
- Alur ini berlaku di menu **selain Isian Kegiatan**. Isian Kegiatan tetap mengikuti alur pengajuan dan Verifikasi yang sudah ada.
- **Periode berlaku**: pilih triwulan dan rentang tahun bagian tampil untuk diisi. Data lama tidak berubah.

### Bukti dukung dan notula

- Bukti **wajib** bisa diperiksa **saat disimpan**, **sebelum diajukan**, atau **sebelum disetujui**. Dua pilihan terakhir hanya untuk alur pemeriksaan.
- Tab 📄 Notula: **Isian yang dicetak di notula** = semua isian, atau hanya isian yang disetujui atau ditandai Sesuai. Aturan ini berlaku sama untuk notula di layar, DOCX, dan PDF.

### Pratinjau dan uji sebelum diterbitkan

Tab **👁 Pratinjau & Uji** menampilkan formulir sungguhan dari pengaturan di layar, termasuk yang belum disimpan. Isi contoh lalu klik **Uji validasi** untuk melihat pesan galat, hasil rumus, dan nilai yang akan tersimpan. Isian contoh tidak disimpan.

Alur menerbitkan bagian baru:

1. Buat bagian, lalu klik **Nonaktifkan** agar belum tampil ke pengisi. Bagian hasil **Duplikasi** sudah nonaktif dari awal.
2. Susun kolomnya, lalu **Simpan Pengaturan**. Semua aturan diperiksa di server.
3. Coba formulirnya di tab **Pratinjau & Uji**.
4. Klik **Aktifkan** agar bagian tampil ke pengisi.

Yang belum tersedia: jenis kolom "referensi ke data lain", dan bukti dukung sebagai kolom tersendiri. Bukti tetap diatur per poin lewat tab 📎.
