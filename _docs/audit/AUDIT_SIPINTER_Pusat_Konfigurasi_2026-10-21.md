# Audit Menyeluruh SIPINTER — Persiapan Pusat Konfigurasi Dinamis (Bagian Kustom)

- **Tanggal audit:** 21 Oktober 2026
- **Cabang kerja:** `susun-ulang-pertahankan-suntingan` (commit terakhir `19ff82d`)
- **Cakupan:** seluruh route, middleware, controller, komponen Livewire, Blade, service, model, migration, template Notula, ekspor Excel.
- **Sifat:** hanya membaca. Tidak ada kode, database, migration, template, atau konfigurasi yang diubah. Tidak ada commit/push. Satu-satunya berkas baru adalah laporan ini.

## Konvensi penandaan

| Tanda | Arti |
|---|---|
| **[V]** | Fakta terverifikasi dengan membaca kode (baris disebut). |
| **[D]** | Dugaan kuat dari pembacaan kode, belum dibuktikan dengan uji/eksekusi. |
| **[U]** | Perlu pemeriksaan lanjutan (uji, data produksi, atau konfirmasi bisnis). |

## Catatan kondisi lingkungan saat audit

1. **Working tree belum bersih.** Ada perubahan belum di-commit pada `TargetTahunan.php`, `VerifikasiCapaian.php`, `RincianN.php`, `NotulaBagian1DocxService.php`, dua Blade, satu test, plus migration baru yang belum dilacak git: `database/migrations/2026_10_21_000001_tambah_sumber_dan_triwulan_mulai_ke_rincian_n.php` dan `tests/Feature/RincianTambahanNTest.php`. Audit ini membaca **working tree apa adanya** (termasuk perubahan tersebut).
2. **`.env` lokal menunjuk ke database produksi** (`APP_ENV=production`, `DB_CONNECTION=pgsql`, host remote). Karena tidak ada izin khusus untuk query produksi, **tidak ada satu pun perintah artisan atau query yang dijalankan** — termasuk `migrate:status`. Status migration produksi tidak diketahui (lihat §16).
3. **Bash tidak dapat dijalankan** di mesin ini (gagal fork); pemeriksaan dilakukan dengan PowerShell baca-saja dan alat pencarian berkas.
4. **"Audit rumus capaian yang sudah tersedia" tidak ditemukan sebagai berkas** di proyek maupun di folder worktree saudara (`D:\Latsar\sipinter-*`). Sebagai gantinya dipakai docblock rumus di `CapaianCalculatorService`, `CapaianTahunan`, `Capaian`, dan diverifikasi ulang terhadap seluruh pemanggilnya.

---

## 1. Ringkasan eksekutif

**Kondisi saat ini.** Bagian Kustom (`bagian_kustom` + `bagian_kustom_poin`) sudah menjadi mesin formulir generik yang cukup matang untuk satu pola: *poin per IKU per bulan*, dengan kolom bertipe teks/angka/pilihan, frekuensi wajib, bukti PDF opsional, peran & penempatan menu, serta baris notula berbasis template variabel. Namun ia **baru menguasai satu jenis bagian**. Seluruh bagian inti (Kegiatan, Kendala & Solusi, Evaluasi RTL, RTL berikutnya, Target Tahunan, Rincian N, RO, Proksi, Notula, LAKIN) masih statis di kode dengan logika bisnis khusus.

**Temuan paling penting (urut prioritas):**

1. **[V] Isian Bagian Kustom non-Ketua-Tim tidak memilah pemilik data.** `IsianBagianKustom::muat()` memuat *semua* poin satu bagian pada satu periode tanpa filter peran, IKU, atau pengisi, lalu `simpan()` **menghapus semua poin bagian+periode itu yang tidak ada di daftar layar** (kecuali yang punya berkas). Bila satu bagian ditempatkan di dua menu/peran, atau dipakai beberapa Ketua Tim lewat menu `dasbor`/`rtl_evaluasi`/`notula_riwayat`/`lakin`, satu pengguna bisa menimpa atau menghapus isian pengguna lain. (`app/Livewire/IsianBagianKustom.php:90-109`, `183-220`)
2. **[V] Ketua Tim dapat memilih IKU mana pun** di Isian Kegiatan dan di Isian Bagian Kustom — dropdown memakai `MasterIku::daftarUrutKode()` (semua IKU) dan validasinya hanya memeriksa keberadaan IKU, bukan penugasan. (`PengisianKegiatan.php:3239-3246`, `4399`; `IsianBagianKustom.php:152`, `232`)
3. **[V] Middleware `role` tidak berlaku pada aksi Livewire.** Livewire 4.3.4 hanya mengulang middleware yang terdaftar sebagai *persistent*; `EnsureUserHasRole` tidak terdaftar. Gerbang peran untuk aksi komponen bergantung pada pemeriksaan di dalam komponen — dan sebagian besar komponen (BagianKustomManager, TargetTahunan, PengaturanCapaian, VerifikasiCapaian, KompilasiNotula, PersetujuanNotula, dll.) **tidak punya pemeriksaan peran internal**. Saat ini dilindungi oleh integritas snapshot Livewire, bukan oleh otorisasi eksplisit. (`vendor/livewire/livewire/src/Mechanisms/PersistentMiddleware/PersistentMiddleware.php:16-25`, `bootstrap/app.php:18-21`)
4. **[V] Rumus capaian tidak seragam antarmodul.** Tabel Rekap di Dasbor Kinerja memakai `Capaian::hitungPersentase()` langsung sehingga **mengabaikan rumus kustom IKU** (`formula_capaian`), sedangkan Verifikasi, kartu rata-rata Dasbor, Notula, dan LAKIN otomatis memakainya. LAKIN yang disunting manual menghitung ulang capaian dengan rasio sederhana **tanpa batas 120% dan tanpa rumus kustom**. (`DasborCapaian.php:321,323`; `LakinDetail.php:118-125`)
5. **[V] Notula memuat poin Bagian Kustom tanpa melihat status verifikasi, penempatan, maupun status verifikasi IKU-nya**, padahal blok IKU dibatasi hanya untuk IKU terverifikasi. (`NotulaService.php:594-608` vs `470-478`)
6. **[V] Formatter angka tidak konsisten**: titik vs koma desimal dalam dokumen yang sama, jumlah desimal bergantung asal nilai (cast `decimal:2` → `"85.50"`, hasil hitung float → `"85.5"`), ekspor Excel berisi teks bukan angka, dan bulan bisa tercetak dalam bahasa Inggris di produksi karena `render.yaml` mengatur `APP_LOCALE=en`.
7. **[V] Tidak ada versi/periode berlaku untuk konfigurasi apa pun.** Perubahan `metode_capaian`, `formula_capaian`, `batas_maksimal_persen`, kolom Bagian Kustom, atau format notula berlaku surut ke seluruh tahun. Snapshot yang tersimpan (Bagian I HTML, `lakin_baris`, PDF) tidak ditandai basi ketika sumbernya berubah (kecuali jalur revisi dari halaman Verifikasi).

**Rekomendasi inti.** Jangan langsung menjadikan Bagian Kustom "formulir untuk segalanya". Bangun pusat konfigurasi bertahap: (0) tutup celah otorisasi & kepemilikan data dulu, (1) satukan formatter dan jalur rumus, (2) tambahkan versi + periode berlaku pada definisi bagian, (3) registri menu/penempatan berbasis kode yang bisa diaktifkan dari layar, (4) katalog variabel & formatter terkontrol, (5) adaptor untuk bagian inti yang tetap memakai kode khusus. Detail di §12–§13.

---

## 2. Inventaris menu dan halaman

### 2.1 Navigasi per peran — [V] `resources/views/layouts/app.blade.php:45-63`

| Peran | Menu di sidebar |
|---|---|
| Semua | Dasbor; Rekap Kinerja Tahunan |
| Ketua Tim | Isian Kegiatan; Notula |
| Tim SAKIP | Verifikasi & Notula; Data Master & Konfigurasi; Isian Bagian Kustom; Kelola Pengguna |
| Kepala | Persetujuan; Isian Bagian Kustom; Notula |

Semua halaman di dalam layout juga **menyisipkan komponen `isian-bagian-kustom` di bawah konten** bila nama route ada di katalog menu, kecuali `pengisian` (`app.blade.php:114-117`).

### 2.2 Daftar halaman

Semua route di bawah grup `auth` + `profile.complete` (`routes/web.php:20`). Middleware `profile.complete` memaksa pengguna tanpa email ke halaman profil (`EnsureEmailIsComplete.php`). Login menolak akun yang belum `terverifikasi` (`AuthenticatedSessionController.php:28-38`).

| Menu / halaman | Route | Peran (route) | Komponen yang benar-benar dirender | Catatan |
|---|---|---|---|---|
| Dasbor | `GET /dashboard` `dashboard` | semua | `DashboardController@index` → `dashboard.blade.php`: Tim SAKIP/Kepala = tab **Dasbor** (`DasborUtama`) + tab **Dasbor Kinerja** (`DasborCapaian`); Ketua Tim = `DasborUtama` saja | + Bagian Kustom menu `dasbor` |
| Isian Kegiatan | `GET /pengisian` `pengisian.index` | Ketua Tim | `PengisianKegiatan` (bagian 1 IKU, 2 Kegiatan, 3 Kendala & Solusi, 4 Evaluasi RTL, 5 RTL berikutnya, + Bagian Kustom ber-penempatan `pengisian`) | Bagian Kustom dirender di dalam komponen, bukan oleh layout |
| RTL & Evaluasi | `GET /rtl-evaluasi` | Ketua Tim | `RtlEvaluasi` | **Tidak ada di sidebar** (halaman lama, logikanya sudah digabung ke Isian Kegiatan — docblock `PengisianKegiatan.php:31-36`). Tetap ada di katalog Bagian Kustom (`rtl_evaluasi`) |
| Notula (Ketua Tim/Kepala) | `GET /notula-riwayat` | Ketua Tim, Kepala | `RiwayatNotula` (baca saja) | + Bagian Kustom `notula_riwayat` |
| Verifikasi & Notula | `GET /verifikasi` | Tim SAKIP | tab **Verifikasi** (`VerifikasiList`), **Kompilasi Notula** (`KompilasiNotula`), **Template Notula** (`TemplateNotula`) — ketiganya di-mount sekaligus, disembunyikan dengan Alpine | `KompilasiNotula::mount()` dapat menyusun Bagian I otomatis (LibreOffice) setiap halaman dibuka bila masih kosong (`KompilasiNotula.php:157-176`) |
| Detail Verifikasi | `GET /verifikasi/{capaian:kode}` | Tim SAKIP | `VerifikasiController@show` → `VerifikasiCapaian` | Menolak status `draft` (`VerifikasiController.php:23-28`) |
| Kompilasi Notula (mandiri) | `GET /notula` `notula.index` | Tim SAKIP | `KompilasiNotula` | **Tidak ada di sidebar**; ada di katalog (`notula`) |
| Template Notula (mandiri) | `GET /template-notula` | Tim SAKIP | `TemplateNotula` | Tidak ada di sidebar |
| Unduh template Bagian I/II/III | `GET /notula/template-bagian{1,2,3}` | Tim SAKIP | `NotulaDownloadController` | Berkas statis di `template_notula/` |
| Data Master & Konfigurasi | `GET /master-iku` | Tim SAKIP | tab **Master IKU** (`MasterIku`), **Target Tahunan** (`TargetTahunan`), **Rumus Capaian** (`PengaturanCapaian`), **Struktur Folder** (`FolderConfigManager`), **Bagian Kustom** (`BagianKustomManager`) | Tab dipilih dari `#hash` |
| Konfigurasi Folder (mandiri) | `GET /konfigurasi` | Tim SAKIP | `FolderConfigManager` | Tidak ada di sidebar |
| Isian Bagian Kustom | `GET /isian-bagian-kustom` | Tim SAKIP, Kepala | View kosong; isi dipasang layout (`IsianBagianKustom` menu `isian_bagian_kustom`) | |
| Dasbor Kinerja (mandiri) | `GET /dasbor-kinerja` | Tim SAKIP, Kepala | `DasborCapaian` | Tidak ada di sidebar (sudah jadi tab Dasbor); ada di katalog |
| Kelola Pengguna | `GET /verifikasi-akun` + `POST …/{user}/approve|reject` | Tim SAKIP | `UserVerificationController` (tabel pending) + tab `AkunAktif`, `PenugasanIku`, `StorageAccounts`, `BackupDatabase`, **Pengingat** (`PengingatEmail`, `PengaturanWhatsApp`, `PengaturanPengingat`, `PengaturanPenerimaPengingat`, `PengaturanTemplatePengingat`) | Tidak ada di katalog Bagian Kustom |
| Akun & Storage (mandiri) | `GET /akun-storage` + OAuth redirect/callback | Tim SAKIP | `StorageAccounts`, `GoogleOAuthController` | Tidak ada di sidebar |
| Persetujuan | `GET /persetujuan` | Kepala | `PersetujuanNotula` | |
| Rekap Kinerja Tahunan | `GET /rekap-kinerja-tahunan` dan `/{lakin}` | semua | `LakinList`, `LakinDetail` | Ubah hanya Tim SAKIP (`LakinList.php:25`, `LakinDetail.php:113-116`) |
| Unduh notula | `/notula/{notula}/draf|pratinjau|pratinjau-bagian2|3|unduh-bagian1-docx|pratinjau-cepat-pdf` | Tim SAKIP, Kepala | `NotulaDownloadController` | `draf` digerbang "semua bukti terverifikasi"; lainnya tidak |
| Unduh notula final | `/notula/{notula}/final` | Tim SAKIP, Kepala, Ketua Tim | `NotulaDownloadController@final` | Hanya bila `disetujui` (`:44`) |
| Pratinjau berkas bukti | `/berkas/{berkas}/buka` | Tim SAKIP, Ketua Tim | `BerkasDownloadController@show` | **Tidak memeriksa kepemilikan berkas** — Ketua Tim mana pun bisa membuka berkas mana pun bila tahu id-nya [V] `BerkasDownloadController.php:23-57` |
| Profil | `/profile` | semua | `ProfileController` | |
| Legal | `/kebijakan-privasi`, `/ketentuan-layanan` | publik | view | |

### 2.3 Proses latar belakang — [V] `routes/console.php`

`pengingat:deadline-iku`, `pengingat:iku-lengkap`, `pengingat:google-reconnect` (harian, jam dari `PengaturanPengingat`, zona `Asia/Makassar`), `google:cek-sambungan` (per jam), `whatsapp:proses-antrean` (per menit), `berkas:sinkron-drive` (5 menit). Event `CapaianStatusDiubah`/`NotulaStatusDiubah` → listener pengingat email/WA (queue `sync`).

### 2.4 Ketergantungan antarhalaman (ringkas)

```
Master IKU ──► Target Tahunan (capaian_tahunan alokasi, rincian_n target)
     │                │
     ▼                ▼
Isian Kegiatan ──► Verifikasi (realisasi, rincian_n terealisasi, RO, proksi, koreksi)
     │                │
     │                ├─► Dasbor / Dasbor Kinerja (hitung langsung)
     │                ├─► Kompilasi Notula (Bagian I disusun → snapshot HTML) ─► Persetujuan (PDF final)
     │                └─► LAKIN (salin angka ke lakin_baris → snapshot)
Bagian Kustom Manager ──► Isian Kegiatan / Isian Bagian Kustom / Verifikasi / Notula / Struktur Folder
Rumus Capaian (pengaturan_capaian) ──► semua perhitungan capaian & format nol LAKIN/Notula
```

---

## 3. Matriks bagian formulir

Kode kelompok: **1** dikelola Bagian Kustom · **2** sebagian dikonfigurasi · **3** statis di kode · **4** logika bisnis khusus · **5** tampilan/hasil hitung · **6** memengaruhi dokumen resmi.

### 3.1 Isian Kegiatan (Ketua Tim) — `PengisianKegiatan`

| Bagian | Kelompok | Kolom & tipe | Validasi / kewajiban | Frekuensi & periode | Saat disimpan |
|---|---|---|---|---|---|
| 1. Pilih IKU & periode | 3, 4 | `tahun`, `bulan`, `iku_id` | `iku_id` hanya harus ada di daftar IKU (`:3239-3257`) — **tidak dicek penugasan** | per bulan | menentukan `Periode` (`firstOrCreate` saat ajukan, `:4335-4342`) |
| 2. Kegiatan | 3, 4, 6 | uraian (≤1000), jenis (`bukan_survei_sensus`/`survei_sensus`), tahapan (teks bebas, ganda), nama folder (≤150), subfolder, bukti PDF ≤10 MB | bukti wajib ≥1 bila belum ada bukti lama (`:3280-3288`); tahapan ganda wajib bukti per tahapan (`:3412-3429`); bulan ke-3: jumlah kegiatan ≥ jumlah RTL TW sebelumnya (`:3477-3492`) | per bulan | baris `kegiatan` per tahapan, `berkas` + unggah Drive, status `diajukan` |
| 3. Kendala & Solusi | 3, 4, 6 | kendala, solusi (teks) | opsional, **wajib ≥1 per triwulan di bulan ke-3** (`:3454-3469`) | per bulan, kumulatif per triwulan di notula | `kendala_solusi`, status verifikasi `menunggu` |
| 4. Evaluasi RTL TW sebelumnya | 3, 4, 6 | realisasi teks + bukti PDF per poin RTL | semua poin wajib bukti di bulan ke-3 (`:3434-3447`) | per triwulan | `rtl_evaluasi.realisasi` + `berkas` |
| 5. RTL triwulan berikutnya | 3, 4, 6 | teks RTL, batas waktu; PIC otomatis dari tim IKU | wajib bila bulan ke-3 dan belum ada (`:3324-3334`) | hanya bulan ke-3 | `rtl_evaluasi` pada periode TW berikut |
| Bagian Kustom (penempatan `pengisian`) | 1 | uraian + kolom dinamis (teks/angka/pilihan) + bukti PDF | kolom wajib, opsi pilihan, angka (`:3494-3541`); bukti wajib bila `bukti_wajib` (`:3297-3310`); frekuensi via `wajibDiisiPada()` | per bulan | `bagian_kustom_poin` per IKU+periode; status verifikasi **tidak direset** saat diedit (`:4144-4146`) |

Kunci status: bagian terkunci saat Capaian `sedang_ditangani`/`diverifikasi`/`disetujui` (`:881-922`), dicek ulang tanpa cache pada aksi simpan (`:4313-4323`).

### 3.2 Detail Verifikasi (Tim SAKIP) — `VerifikasiCapaian`

| Bagian | Kelompok | Isian | Gerbang |
|---|---|---|---|
| Ringkasan IKU & Target (readonly) | 5 | Target Tahunan, X/Y TW IV | — |
| Alokasi/Realisasi triwulanan | 4, 6 | `realisasi_tw1..4` (Poin, kumulatif, auto-isi TW sesudahnya `:770-790`); Poin juga boleh mengubah `alokasi_tw1..4` (`:2008-2016`); Rasio: Y dari Target Tahunan | `simpanPerubahan()` **tanpa** gerbang status (`:2366-2390`) |
| Checklist Rincian N (Rasio) | 4, 6 | centang per item per TW; rincian tambahan sumber `verifikasi` | `rincianNBisaDiubah()` (`:614-623`) |
| Analisis capaian, Penjelasan lainnya | 3, 6 | teks bebas | — |
| Kegiatan (per kegiatan induk) | 4, 6 | koreksi uraian, tandai Sesuai/Tidak Sesuai + catatan, ganti nama folder | `kegiatanBisaDikoreksi()` selalu `true` (`:2061-2064`) |
| Rincian Output (RO) | 3, 6 | uraian, volume (teks), progres % (0–100) | — |
| Indikator Proksi | 3, 6 | uraian, target, realisasi — **semua teks** | — |
| Berkas bukti | 4 | Sesuai/Tidak Sesuai + catatan | `berkasBisaDiverifikasi()` |
| Kendala & Solusi | 4, 6 | koreksi teks, tandai, tambah/hapus | |
| Bagian Kustom | 1, 4 | tandai Sesuai/Tidak Sesuai + catatan (tidak bisa koreksi teks/kolom) | hanya bagian ber-penempatan `pengisian` (`:1110-1120`) |
| Evaluasi RTL TW sebelumnya | 4, 6 | koreksi realisasi, tandai | |
| RTL berikutnya | 4, 6 | koreksi teks, tandai, PIC wajib (`:2612-2618`), tambah/hapus | |
| Aksi | 4 | Simpan, Simpan Sementara, Verifikasi Selesai, Kembalikan, Buka Kembali | §9 |

### 3.3 Data Master & Konfigurasi (Tim SAKIP)

| Bagian | Kelompok | Isi | Catatan |
|---|---|---|---|
| Master IKU (`MasterIku`) | 2, 4, 6 | kode, indikator, tim (multi), sasaran, dasar hitung (sintaks `RumusMarkup`), basis data, satuan, metode (`langsung`/`rasio`), rumus kustom, jenis periode, label X/Y; impor Excel 2 tahap | Satuan **dipaksa** mengikuti metode (`MasterIku.php:65-71`); rumus kustom divalidasi `FormulaCapaianService::valid()` (`MasterIku` Livewire `:172-179`) |
| Target Tahunan (`TargetTahunan`) | 4, 6 | per IKU: alokasi TW1–4 (Poin) atau X TW1–4 + daftar Rincian N (Rasio; Y = jumlah rincian) | Y & Realisasi Y disalin ke 4 TW (`:320-344`) |
| Rumus Capaian (`PengaturanCapaian`) | 2 | `batas_maksimal_persen`, `tampilkan_nol_sebagai_strip` | satu baris global, cache permanen |
| Struktur Folder (`FolderConfigManager`) | 2 | hierarki & kategori folder Drive, pola per IKU | Bagian Kustom baru menambah kategori otomatis (`FolderConfig.php:126-141`) |
| Bagian Kustom (`BagianKustomManager`) | 1 | §8 / §H | |

### 3.4 Lainnya

| Halaman | Bagian | Kelompok |
|---|---|---|
| Kompilasi Notula | Detail rapat (hari/tanggal, waktu, tempat, pimpinan, notulis, kepala satker, kota TTD, link lampiran) | 3, 6 |
| | Editor Bagian I (HTML hasil susun), susun ulang (pertahankan suntingan/timpa), pulihkan cadangan | 4, 6 |
| | Unggah Bagian II/III (.docx), gabungkan → PDF | 4, 6 |
| Persetujuan | Setujui, kembalikan notula, kembalikan isian IKU | 4, 6 |
| Dasbor Kinerja | Nilai SAKIP (input Tim SAKIP), predikat, kartu & tabel | 2 (input), 5 |
| LAKIN | Pilih IKU, segarkan angka, sunting baris (target, realisasi, kegiatan, solusi-kendala, RTL, PIC, batas waktu, 2 link), baris custom, ekspor Excel | 3, 4, 6 |
| Kelola Pengguna | Verifikasi akun, akun aktif & tim, penugasan IKU (manual/pengecualian), storage, backup, pengingat (jam, H-minus, penerima per jenis, template pesan, WhatsApp) | 2–4 |
| Isian Bagian Kustom | Poin per IKU per bulan untuk Tim SAKIP/Kepala/menu lain | 1 |

### 3.5 Katalog menu Bagian Kustom dan keterbatasannya — [V] `app/Support/MenuBagianKustom.php:22-41`

11 kunci: `dasbor`, `pengisian`, `rtl_evaluasi`, `notula_riwayat`, `verifikasi`, `notula`, `master_iku`, `persetujuan`, `dasbor_kinerja`, `lakin`, `isian_bagian_kustom`.

- Katalog **ditulis di kode**; menambah menu = ubah kode.
- Pencocokan hanya lewat **nama route persis** (`dariRoute()`), sehingga route `verifikasi.show` (Detail Verifikasi), `lakin.show`, `verifikasi-akun.index`, `profile.edit` **tidak dapat dipilih**.
- Tiga kunci menunjuk halaman yang **tidak ada di sidebar**: `rtl_evaluasi`, `notula`, `dasbor_kinerja`. Bagian yang ditaruh di sana praktis tak terlihat.
- Hanya ada dua mode penempatan: *di dalam form Isian Kegiatan* (per IKU, ikut alur verifikasi) atau *di bawah halaman* (per IKU per bulan, tanpa verifikasi). Tidak ada posisi di dalam tab, sebelum/sesudah bagian tertentu, atau per tahap alur.
- Halaman bertab (Data Master, Verifikasi & Notula, Dasbor) menampilkan Bagian Kustom di bawah **semua tab**, bukan di tab tertentu.

---

## 4. Inventaris variabel dan sumber data

### 4.1 Variabel inti capaian

| Variabel (identitas) | Sumber & jalur | Tipe | Rumus / transformasi | Disimpan / dihitung | Pemakai |
|---|---|---|---|---|---|
| `alokasi_tw{n}` | `capaian_tahunan`, diisi Target Tahunan (+ Verifikasi untuk Poin) | decimal(15,2) | kumulatif apa adanya | disimpan | `alokasiKumulatif()` |
| `x_alokasi_tw{n}`, `y_alokasi_tw{n}` | `capaian_tahunan`; Y = jumlah `rincian_n` penyebut saat simpan Target Tahunan | decimal | Y diulang 4 TW | disimpan | |
| `realisasi_tw{n}` | Verifikasi (Poin) | decimal | kumulatif apa adanya | disimpan | `realisasiKumulatif()`, Dasbor "realisasi TW ini", Notula `realisasi_tw` |
| `x_realisasi_tw{n}` | Verifikasi; = jumlah `rincian_n` terealisasi **pada** TW n (`RincianN::jumlahRealisasiPerTw`) | decimal | **non-kumulatif**, dijumlahkan TW1..n saat dibaca | **hasil hitung disimpan** | |
| `y_realisasi_tw{n}` | disalin dari Y alokasi | decimal | konstan | disimpan | |
| Alokasi Target Kumulatif | `CapaianTahunan::alokasiKumulatif($tw)` `:113-120` | float | Rasio: `round(X/Y×100, 2)`, Y=0 → 0; Poin: apa adanya | dihitung | |
| Realisasi Kumulatif | `realisasiKumulatif($tw)` `:143-152` | float | Rasio: `round(ΣX₁..ₙ / Yₙ ×100, 2)`; Poin: apa adanya | dihitung | |
| Target Tahunan | `targetTahunan()` `:164-167` | float | = alokasi kumulatif TW IV | dihitung | |
| Capaian thd Target Triwulanan (Rumus 2.3) | `capaianTriwulanan($tw)` `:190-196` | ?float | rumus kustom (bila ada) ?? `hitungPersentase(alokasi, realisasi)` | dihitung | Verifikasi, Dasbor kartu, Notula, LAKIN (tidak) |
| Capaian thd Target Setahun (Rumus 2.4) | `capaianSetahun($tw)` `:203-209` | ?float | sama, pembagi Target Tahunan | dihitung | Verifikasi, Dasbor kartu, Notula, LAKIN |
| `batas` | `pengaturan_capaian.batas_maksimal_persen` (cache permanen) | decimal(6,2), default 120 | — | konfigurasi | semua |
| `formula_capaian` | `master_iku`, hanya metode `langsung` | text | ExpressionLanguage dengan `alokasi`, `realisasi`, `batas` | konfigurasi | `capaianFormula()` `:219-230` |
| N (penyebut) | `RincianN::jumlahPenyebut()` = sumber `target` belum dilepas | int | count | dihitung | Target Tahunan (→Y), Notula "N = … mencakup" |
| n (pembilang) | baris `rincian_n` terealisasi s.d. TW dan ≥ `triwulan_mulai_berlaku` | int | count | dihitung | Notula, Verifikasi |
| Rata-rata capaian TW (paragraf pembuka notula, kartu dasbor) | `rataRataCapaianTriwulanan()` | float\|"-" | rerata nilai valid, **abaikan "-" dan 0** | dihitung | Dasbor, Notula |
| Rata-rata capaian setahun | `rataRataCapaianSetahun()` | float\|"-" | Σ (− dianggap 0) / jumlah **seluruh** IKU | dihitung | Dasbor, Notula |
| Subtotal per sasaran | `subtotalPerSasaran()` | float\|"-" | rerata, abaikan "-", **0 ikut** | dihitung | Dasbor |
| Rata-rata capaian kartu ringkasan Dasbor | `DasborCapaian::rataRataPersentase()` `:335-340` | float | rerata `persentase` non-null tabel rekap; kosong → **0.0** (bukan "-") | dihitung | Dasbor |
| Predikat SAKIP | `predikatSakip(nilai_sakip)` | string | ambang 90/80/70/60/50/30 | dihitung | header Dasbor |
| Progres per TW | `DasborCapaian::progresPerTriwulan()` | float | kegiatan diverifikasi/disetujui ÷ total ×100, `round(…,1)` | dihitung | Dasbor |

### 4.2 Variabel Notula Bagian I (macro template)

Global (`NotulaBagian1DocxService::isiHeader()` `:408-431`): `tahun`, `triwulan_angka`, `triwulan_sebelumnya`, `nama_satker` (**hard-code "BPS KABUPATEN BUTON UTARA"**, `:418`), `agenda_pembahasan`, `hari_tanggal`, `waktu`, `tempat`, `pimpinan_rapat`, `capaian_triwulanan_persen`, `capaian_pk_persen`, `lampiran_basis_data`.

Per IKU (`isiSatuIku()` `:510-681`): `kode`, `indikator`, `sasaran`, `target_pk`, `target_tw`, `realisasi_tw`, `capaian_tw`, `capaian_pk`, `analisis_capaian`, `kendala`, `solusi`, `rtl`, `pic_rtl`, `batas_waktu_rtl`, `formula_capaian`, `dasar_hitung`, `bukti_realisasi`, `rtl_evaluasi`, `bukti_rtl_evaluasi`, `rtl_berikutnya`, `batas_waktu_rtl_berikutnya`, `bukti_rtl_sebelumnya`, `penjelasan_lainnya`; blok kondisional `iku_blok`, `blok_proksi`, `blok_ro`, `blok_formula`, `blok_sakip`/`blok_berakhlak` (lama). Daftar macro yang dikenal ada di `:1261` (validasi template).

Variabel Bagian Kustom (`TemplateBagianKustom::VARIABEL_BAWAAN` `:19-27`): `kode_iku`, `indikator_iku`, `teks`, `nama_bagian`, `triwulan`, `tahun`, `bulan` + satu per kunci kolom.

### 4.3 Variabel bernama sama dengan makna berbeda — [V]

| Nama | Makna di satu tempat | Makna di tempat lain |
|---|---|---|
| `realisasi` | Dasbor/Notula: `realisasi_tw{tw}` (kolom Poin, **triwulan ini saja**, `DasborCapaian.php:310`, `NotulaService.php:538`) — untuk IKU Rasio kolom ini biasanya kosong → tampil 0 [D] | LAKIN: realisasi kumulatif TW IV (`LakinBuilderService.php:38`) — untuk Rasio berupa **persen** |
| `target_pk`/`target_tw` | `capaian` (kolom lama, tidak lagi diisi — [V] tidak ada penulis di `app/`) | Notula/Dasbor: hasil `targetTahunan()`/`alokasiKumulatif()` |
| `persentase_capaian`, `capaian.realisasi` | kolom `capaian` lama, tidak dipakai | `Capaian::realisasiKumulatif()` (`Capaian.php:226-243`) masih menjumlah `capaian.realisasi` tetapi **tidak dipanggil di mana pun** [V] |
| `target_tahunan`, `x_target`, `y_target` | kolom `capaian_tahunan` lama (masih ditulis impor Excel `MasterIkuImportValidator.php:278-291`) | `rekap['y_target']` di Notula = `y_alokasi_tw4` (`NotulaService.php:552`) |
| `tim` | `master_iku.tim` (string gabungan, disinkronkan) | `iku_tim` (sumber kebenaran), `user_tim` (keanggotaan) |
| `status_verifikasi` | `bagian_kustom_poin`: `menunggu/terverifikasi/ditolak`; poin isian non-Ketua-Tim langsung `terverifikasi` (`IsianBagianKustom.php:207-208`) | `users.status_verifikasi`: `pending/terverifikasi/ditolak` |
| `status` | `capaian` (6 nilai), `notula` (4), `kegiatan.status_dokumen` (5), `rtl_evaluasi.status_dokumen` & `kendala_solusi.status_dokumen` (`draft/diajukan`) | |
| N | Rumus notula: `y_alokasi_tw4` (`NotulaBagian1DocxService.php:723`) | Keterangan "N = x mencakup": count `rincian_n` saat dokumen disusun (`:831-848`). Sama **hanya** bila Target Tahunan disimpan setelah perubahan rincian terakhir [D] |
| `bulan` (Bagian Kustom) | nama bulan poin | Notula mengelompokkan poin per triwulan, jadi tiga bulan bercampur dalam satu daftar |

---

## 5. Peta rumus dan dependensi

### 5.1 Pohon dependensi

```
pengaturan_capaian.batas_maksimal_persen ─────────────┐
master_iku.metode_capaian ──┬─► alokasiKumulatif(tw) ─┼─► capaianTriwulanan(tw) ─┬─► Verifikasi tabel %
                            │                          │                          ├─► Dasbor kartu TW & per sasaran
capaian_tahunan.*_alokasi_* ┘                          │                          └─► Notula capaian_tw, rata-rata TW
                                                       │
rincian_n ──► x_realisasi_tw* (disimpan) ─► realisasiKumulatif(tw) ─┤
capaian_tahunan.realisasi_tw* ───────────────────────┘             │
                                                                    ├─► capaianSetahun(tw) ─┬─► Verifikasi
targetTahunan() = alokasiKumulatif(4) ──────────────────────────────┘                     ├─► Dasbor kartu setahun
                                                                                           ├─► Notula capaian_pk, rata-rata PK
master_iku.formula_capaian (langsung saja) ─► capaianFormula() ─────► menggantikan keduanya └─► LAKIN capaian_persen (TW IV)

Dasbor tabel Rekap: Capaian::hitungPersentase(alokasi/target, realisasi)  ← TIDAK lewat capaianFormula()
LAKIN sunting manual: round(realisasi/target×100, 2)                      ← TIDAK lewat hitungCapaian()
```

### 5.2 Aturan nilai khusus — `CapaianCalculatorService::hitungCapaian()` `:67-78`

| Kondisi | Hasil |
|---|---|
| alokasi = 0, realisasi > 0 | `batas` |
| alokasi = 0, realisasi ≤ 0 | "-" (null) |
| alokasi > 0, realisasi ≤ 0 | "-" (null) |
| keduanya > 0 | `min(realisasi/alokasi×100, batas)` |
| target/realisasi bukan numerik | null (`Capaian::hitungPersentase` `:191-193`) |

Pembulatan: `round(…, 2)` di `Capaian::hitungPersentase()` (`:199`), di `rasioNilai()` **sebelum** dipakai sebagai pembilang/penyebut capaian (`CapaianTahunan.php:180`) dan di `capaianFormula()` (`:229`). Rata-rata dibulatkan 2 di Notula (`NotulaService.php:1300`), tidak dibulatkan di Dasbor sampai Blade `round(…,2)`.

**Kehilangan presisi [V]:** realisasi & alokasi kumulatif Rasio dibulatkan 2 desimal sebagai persen, lalu dibagi lagi untuk capaian. Contoh: X/Y = 1/3 → 33,33; alokasi 66,67 → capaian 49,99 (bukan 50,00).

### 5.3 Rumus kustom — risiko [V]/[D]

- `FormulaCapaianService::valid()` hanya mencoba `alokasi=100, realisasi=80, batas=120` (`:55-64`). Rumus seperti `realisasi/alokasi*100` lolos validasi tetapi akan **melempar `DivisionByZeroError` saat alokasi 0** di runtime → halaman Verifikasi/Dasbor/Notula/LAKIN bisa error 500 [D].
- Hasil rumus kustom **selalu float**: aturan "-" dan batas tidak berlaku kecuali ditulis eksplisit dalam rumus; kolom `lakin_baris.capaian_persen decimal(6,2)` bisa meluap bila hasil > 9999,99 [D].
- ExpressionLanguage menyediakan fungsi `constant()` bawaan; ruang lingkupnya terbatas pada konstanta PHP, tetapi tetap perlu dimatikan bila ingin "rumus aman" [U].

### 5.4 Konsistensi Verifikasi, Dasbor, Notula, LAKIN

| Nilai | Verifikasi | Dasbor kartu | Dasbor tabel Rekap | Notula | LAKIN |
|---|---|---|---|---|---|
| Populasi IKU | satu IKU | semua `capaian_tahunan` tahun itu | IKU dengan kegiatan **terverifikasi** di TW itu | blok: IKU dengan Capaian `diverifikasi/disetujui` di TW + punya sasaran; rata-rata: **semua** `capaian_tahunan` tahun itu | IKU yang dipilih Tim SAKIP |
| Rumus kustom | ya | ya | **tidak** | ya | ya saat tambah/segarkan; **tidak** saat sunting manual |
| Batas 120% | ya | ya | ya | ya | **tidak** saat sunting manual |
| Rata-rata "kosong" | — | "-" | **0.0** | null → "…" | `round(avg,1)` atau "-" |

Akibat [V]: paragraf pembuka Notula ("rata-rata capaian triwulanan …%") bisa memasukkan IKU yang tidak tampil di tabel IKU Notula karena belum terverifikasi; kartu "rata-rata capaian" di Dasbor dihitung dari populasi berbeda dengan kartu "Capaian Kinerja IKU per Triwulan".

---

## 6. Inventaris satuan dan format angka

### 6.1 Satuan

- `master_iku.satuan` hanya `Persen` atau `Poin`, **dipaksa** dari metode (`MasterIku.php:65-71`); validasi form `in:Persen,Poin`. Satuan lain (orang, dokumen, rupiah, hektare) **tidak didukung** — bila ada, hanya bisa disisipkan sebagai teks di indikator/dasar hitung.
- `indikator_proksi.target/realisasi` berupa **teks bebas** (satuan apa pun bisa diketik, tidak bisa dihitung).
- `rincian_output.volume_ro` teks; `progres_persen decimal(5,2)` 0–100.
- Kolom Bagian Kustom tipe `angka` disimpan sebagai **string apa adanya**; koma diterima saat validasi (`str_replace(',', '.')`) tetapi tidak dinormalisasi (`IsianBagianKustom.php:172`, `PengisianKegiatan.php:3518`).
- Rupiah: tidak ada formatter rupiah di aplikasi (Bagian III Anggaran adalah unggahan .docx, bukan data).

### 6.2 Formatter yang ditemukan

| Lokasi | Cara | Desimal | Pemisah desimal / ribuan | Nol / null |
|---|---|---|---|---|
| `PengaturanCapaian::formatAngka()` `:68-79` | `(string) $nilai` | tergantung asal: cast `decimal:2` → 2 digit (`"85.50"`); float → minimal (`"85.5"`, `"100"`) | titik / **tanpa ribuan** | null/"" → "-"; 0 → "0" atau "-" (pengaturan) |
| `PengaturanCapaian::formatPersen()` `:84-89` | formatAngka + "%" | idem | idem | idem |
| Notula RO progres `NotulaBagian1DocxService.php:929` | `number_format(…, 2, ',', '.')` + "%" | 2 | **koma / titik** | — |
| Notula rumus n/N `:730-731` | `(int) round()` lalu formatAngka | 0 | — | |
| Notula rata-rata `:424-425` | `(string) float` (sudah `round 2`) | ≤2 | titik | null → "…" |
| Notula nilai kosong umum `set()` `:303-306` | — | — | — | kosong → "…" |
| Dasbor kartu `dasbor-capaian.blade.php:98,104,125` | `round(x,2).'%'` | ≤2 | titik | "-" |
| Dasbor tabel `:212-233` | formatAngka | idem | titik | |
| Dasbor progres `DasborCapaian.php:242` | `round(…,1)` | 1 | titik | 0.0 |
| Verifikasi `verifikasi-capaian.blade.php:128` | `{{ $capaianTahunan->targetTahunan() }}%` **tanpa formatter** | float mentah | titik | 0 tampil "0%" |
| Verifikasi tabel `:269-320` | formatAngka/formatPersen | | | |
| Target Tahunan `target-tahunan.blade.php:60` | `round(x/y*100, 2)` | ≤2 | titik | "—" (em dash) |
| LAKIN layar `lakin-detail.blade.php:2-3,57` | formatAngka; rata-rata `round(avg,1)` | 2 (dari DB) / 1 | titik | "-" |
| LAKIN Excel `LakinExport.php:30-32` | formatAngka → **string** | 2 | titik | Excel menerima sebagai teks, tidak bisa dijumlah |
| Backup ukuran `Backup.php:77` | `round(…,1)` + satuan | 1 | titik | |
| Storage kuota `StorageAccount.php:259` | `round(…,1)` | 1 | | |

Tiga lambang "kosong" berbeda dipakai untuk konsep sama: "-" (aplikasi), "—" (Target Tahunan/UI), "…" (Notula).

### 6.3 Tanggal & periode

- Notula batas waktu: `Carbon::…->locale('id')->translatedFormat('F Y')` (`NotulaBagian1DocxService.php:688-693`) — aman.
- **Tanpa `locale('id')`**: `translatedFormat('d F Y')` di `pengisian-kegiatan.blade.php:929,1054`, `rtl-evaluasi.blade.php:55,77`, `verifikasi-capaian.blade.php:88,953`, `persetujuan-notula.blade.php:176,192`, `kompilasi-notula.blade.php:619`, `lakin-list.blade.php:47`, `backup-database.blade.php:52`, `template-notula.blade.php:64`, `KirimPengingatStatusCapaian.php:82`, `PengingatDeadlineIkuCommand.php:65,69`. `.env` lokal `APP_LOCALE=id`, tetapi **`render.yaml` produksi `APP_LOCALE=en`** → bulan tercetak "October" di produksi [V konfigurasi; U apakah dasbor Render menimpanya].
- `d-m-Y` (LAKIN), `d/m/y H:i` (riwayat), `Y-m-d` (input). Waktu disimpan UTC, ditampilkan WITA lewat macro `wita()` (`AppServiceProvider.php`).
- Triwulan: angka Romawi dibentuk di beberapa tempat secara terpisah (`['I','II','III','IV']` di PengisianKegiatan, TemplateBagianKustom, NotulaService, Blade).

### 6.4 Pembulatan sebelum tampil

`rasioNilai()` membulatkan persen sebelum dipakai sebagai operand (§5.2). `lakin_baris` menyimpan hasil (target, realisasi, capaian) dalam `decimal(15,2)`/`decimal(6,2)` → presisi sumber hilang di snapshot.

---

## 7. Peta pembentukan Notula dan dokumen

### 7.1 Notula — alur

```
Kompilasi Notula (pilih tahun+TW) ─► NotulaService::untukTriwulan() → notula di periode bulan pertama TW
  ├─ Bagian I: kumpulkanDataBagianSatu() → NotulaBagian1DocxService::generate(template mesin .docx)
  │             → LibreOffice docx→HTML → penanda rumus diganti RumusMarkup HTML → BlokBagianSatu::bungkus()
  │             → notula.bagian1_html (SNAPSHOT, bisa disunting di editor)
  │     Susun ulang: pertahankan suntingan (BlokBagianSatu::gabungkan) atau timpa semua; cadangan → bagian1_html_cadangan
  ├─ Bagian II/III: unggah .docx → PDF (pratinjau) + HTML (render menyatu) + .docx asli base64 (bagian{2,3}_docx)
  ├─ Gabungkan: bagianLengkap() → renderNotulaUtuhPdf (dompdf/pdf.notula-utuh atau PDF dari DOCX) → pdf_gabungan,
  │             status draft/dikembalikan → menunggu_persetujuan
  └─ Persetujuan Kepala: render ulang dengan TTD → pdf_final (nama ber-versi), kegiatan TW → disetujui,
                Capaian → disetujui, arsip ke Drive (Berkas kategori notula)
Unduh .docx lengkap: Bagian I dari bagian1_html (rumus span.rumus → OMML), Bagian II/III disisipkan dari .docx asli (PenggabungDocx)
```

### 7.2 Sumber data per bagian Bagian I — [V] `NotulaService::kumpulkanDataBagianSatu()` `:449-691`

| Data | Filter |
|---|---|
| Daftar IKU | Capaian TW berstatus `diverifikasi/disetujui` **dan** `master_iku.sasaran` terisi (`:470-478`) — IKU tanpa sasaran tidak pernah masuk notula |
| Angka capaian | `capaian_tahunan` tahun itu (`:530-554`) |
| Analisis & catatan | Capaian terbaru TW (`:488-491`) |
| Kegiatan, RO | kegiatan `diverifikasi/disetujui` TW (`:493-515`) |
| Proksi | `indikator_proksi` TW yang terisi |
| Kendala & Solusi | **semua** baris TW1..TW berjalan, tanpa filter status verifikasi/dokumen (`:560-564`) [D: kendala `draft`/`ditolak` ikut] |
| RTL TW ini, RTL TW sebelumnya | tanpa filter status (`:566-578`) |
| RTL TW berikutnya | `status_dokumen != draft` (`:585-588`) |
| Bagian Kustom | `masuk_notula = true` + punya poin di TW; **tanpa filter status verifikasi, penempatan, aktif, atau status IKU** (`:594-608`); dicetak sebagai daftar global setelah IKU terakhir (`NotulaBagian1DocxService.php:457-502`) |
| Link folder | Drive via `FolderStructureService` (gagal → null) |
| Rata-rata pembuka | semua `capaian_tahunan` tahun itu (`:656-666`) |

### 7.3 Template

- Template mesin Bagian I: `resolveTemplatePath()` `NotulaBagian1DocxService.php:267` — unggahan Tim SAKIP (`folder_config.template_notula_*`, Drive) atau bawaan `template_notula/SIPINTER_Template_Bagian_I_Mesin.docx`. Validasi struktur & macro tidak dikenal (`validasiStrukturTemplate()` `:1206`, `macroTidakDikenal()` `:1276`).
- Template II/III statis untuk diunduh (`NotulaDownloadController.php:164-178`).
- Konvensi macro: `template_notula/PANDUAN_Template_Bagian_I_Mesin.md`.
- **Bagian Kustom tidak punya macro** di template; disisipkan sebagai paragraf XML mentah (judul tebal rata tengah + bullet "• ") — gaya tidak bisa diatur dari template [V `:471-502`].

### 7.4 Snapshot & pembaruan

| Pemicu perubahan sumber | Apakah notula ditandai basi? |
|---|---|
| Simpan revisi di Detail Verifikasi (Capaian sudah diverifikasi/disetujui) | Ya — Bagian I **disusun ulang penuh** (bukan mode pertahankan suntingan; cadangan disimpan) + `tandaiPerluDigabungUlang()` (`NotulaService.php:1253-1290`) |
| Kepala mengembalikan isian | Ya — notula dikembalikan atau versi baru (`:1212-1237`) |
| Ganti Bagian II/III | Ya (`:962-966`) |
| Ubah Target Tahunan / Rincian N | **Tidak** |
| Ubah Master IKU (metode, rumus, sasaran, indikator, satuan) | **Tidak** |
| Ubah `batas_maksimal_persen` | **Tidak** |
| Ubah/isi Bagian Kustom (definisi atau poin di Isian Bagian Kustom) | **Tidak** |
| Tim SAKIP menandai item Sesuai/Tidak Sesuai | Tidak (hanya status Capaian) |

PDF final versi lama tetap tersimpan per versi (`Notula::namaUnduhan()`), tetapi **`bagian1_html` hanya satu** (plus satu cadangan) — tidak ada riwayat isi per versi. Data sumber tidak dibekukan saat disetujui, jadi menyusun ulang notula lama akan memakai rumus/konfigurasi terbaru.

### 7.5 LAKIN & Excel

- `LakinBuilderService::ikuTersediaUntukTahun()` (`:31-52`): target = Target Tahunan, realisasi = realisasi kumulatif TW IV, capaian = `capaianSetahun(4)`; disalin ke `lakin_baris` (snapshot) hanya untuk IKU yang dipilih. `segarkanBaris()` menimpa angka **termasuk suntingan manual** pada baris ber-IKU (`:117-140`).
- Ekspor Excel `LakinExport` — angka sebagai teks (§6.2).
- Ekspor lain: template impor Master IKU (`MasterIkuTemplateExport`, `MasterIkuTemplateSheet`, `DaftarNamaSheet`).

### 7.6 Elemen yang dapat vs tidak dapat dikonfigurasi aman

- **Dapat** (dengan validasi): judul & format baris Bagian Kustom (sudah), detail rapat, teks satker (sekarang hard-code), link lampiran, label X/Y, ambang batas, simbol nol, format angka per variabel.
- **Perlu penanganan khusus**: struktur tabel per IKU, blok kondisional, rumus OMML, penggabungan docx Bagian II/III, blok TTD, penomoran halaman/tata halaman template — harus tetap lewat template Word + validator macro, bukan editor bebas.

---

## 8. Peta database, histori, dan snapshot

### 8.1 Tabel terkait (dari migration)

| Tabel | Tujuan | Kolom penting | Relasi | Jenis data |
|---|---|---|---|---|
| `roles` | 3 peran | `nama` enum | users | referensi |
| `users` | akun | `username`, `email`, `nip`, `nomor_telepon`, `wa_opt_in`, `status_verifikasi`, `role_id` | `user_tim`, `iku_penugasan`, `iku_pengecualian` | master |
| `user_tim`, `iku_tim`, `iku_penugasan`, `iku_pengecualian` | keanggotaan tim, tim IKU, penugasan manual, pengecualian | unique pasangan | | master |
| `periode` | bulan | `tahun`, `triwulan`, `bulan`, `bulan_ke`, `flag_bulan_terlewat`; unique (tahun,bulan) | hampir semua transaksi | referensi (dibuat on-demand) |
| `master_iku` | definisi IKU | `kode` unik, `indikator`, `tim`, `sasaran`, `dasar_hitung`, `basis_data`, `satuan`, `metode_capaian`, `formula_capaian`, `jenis_periode`, `deskripsi_x/y` | banyak | **konfigurasi tanpa versi** |
| `capaian_tahunan` | target/alokasi/realisasi per IKU per tahun | 4×(alokasi, realisasi, x/y alokasi, x/y realisasi), kolom lama `target_tahunan/x_target/y_target`; unique (iku,tahun) | master_iku cascade | transaksi + **hasil hitung tersimpan** (`x_realisasi_*`, `y_*`) |
| `rincian_n` | item N/n | `uraian`, `triwulan_realisasi`; **baru (pending)**: `sumber`, `triwulan_mulai_berlaku`, `dilepas_dari_target_at`, `dibuat_oleh` | master_iku cascade | transaksi |
| `capaian` | status per IKU+bulan | `kode` (route), `status`, `analisis_capaian`, `catatan`, kolom lama `target_pk/target_tw/realisasi/persentase_capaian` | `riwayat_status_capaian` | transaksi + alur |
| `riwayat_status_capaian`, `riwayat_status_notula` | audit trail status | `status`, `user_id`, `catatan`, `created_at` | | **histori** |
| `kegiatan`, `rincian_output` | kegiatan per IKU+bulan, RO | `status_dokumen`, `status_verifikasi_uraian`, `catatan_uraian`, folder | `berkas` morph | transaksi |
| `kendala_solusi`, `rtl_evaluasi` | | `status_verifikasi`, `catatan`, `status_dokumen`, `pic`, `batas_waktu` | | transaksi |
| `indikator_proksi` | proksi per IKU per TW | teks | | transaksi |
| `bagian_kustom` | **definisi bagian** | `nama`, `deskripsi`, `peran` json, `penempatan` json, `label_uraian`, `kolom` json, `frekuensi_wajib`, `bukti_wajib`, `masuk_notula`, `judul_notula`, `format_notula`, `aktif`, `urutan` | poin cascade on delete | **konfigurasi tanpa versi** |
| `bagian_kustom_poin` | isian | `bagian_kustom_id`, `iku_id`, `periode_id`, `teks`, `nilai_kolom` json, `status_verifikasi`, `catatan`, `catatan_bukti_dihapus` — **tanpa `user_id`/pengisi/peran/menu/`capaian_id`** | `berkas` morph | transaksi |
| `berkas` | bukti & arsip (polimorfik) | `kode`, `kategori` enum/CHECK (`…,'bagian_kustom'`), `path`, `drive_file_id`, `subfolder`, `status_verifikasi`, `diunggah_oleh` | storage_account | transaksi |
| `notula` | dokumen triwulan | detail rapat, `bagian1_html`, `bagian1_html_cadangan`, `bagian{2,3}_{pdf,html,docx}`, `pdf_gabungan`, `pdf_final`, `status`, `versi`, `disetujui_*` | periode unique | **snapshot dokumen** |
| `lakin`, `lakin_baris` | rekap tahunan | angka + kolom tindak lanjut | iku nullOnDelete | **snapshot** + suntingan manual |
| `pengaturan_capaian` | batas, format nol (kolom PKO lama) | singleton id=1 | | konfigurasi global |
| `nilai_sakip` | nilai per tahun | | | input |
| `folder_config`, `iku_folder_config` | pola folder Drive + template notula | `pola_json` | | konfigurasi |
| `pengaturan_pengingat`, `pengaturan_penerima_pengingat`, `pengaturan_template_pengingat` | jadwal, penerima per jenis, template pesan | | | konfigurasi |
| `riwayat_pengiriman_email`, `riwayat_pengiriman_wa` | log | | | histori |
| `storage_account`, `backups`, `cache`, `jobs`, `sessions` | infrastruktur | token OAuth terenkripsi/teks [U] | | — |

### 8.2 Status migration

- Migration terakhir yang terlacak git: `2026_10_15_100001_simpan_docx_asli_bagian_notula.php`.
- **`2026_10_21_000001_tambah_sumber_dan_triwulan_mulai_ke_rincian_n.php` belum dilacak git** dan dipakai oleh kode working tree (`RincianN::scopePenyebut`, `jumlahRealisasiPerTw` dengan `whereColumn('triwulan_realisasi','>=','triwulan_mulai_berlaku')`, Target Tahunan, Verifikasi, Notula). Jika kode ini dideploy tanpa migration, query akan gagal karena kolom tidak ada [D].
- Status penerapan di produksi **tidak diperiksa** (§16).

### 8.3 Risiko perubahan skema

- `berkas.kategori` dibatasi CHECK/enum: kategori baru butuh migration (`2026_08_08_005039_…`).
- `capaian.status`, `riwayat_status_capaian.status`: CHECK constraint Postgres ditulis manual di migration — menambah status butuh migration khusus driver.
- `bagian_kustom_poin.iku_id` `restrictOnDelete` → IKU tidak bisa dihapus bila ada poin (sudah dicek `MasterIku::relasiYangMenghalangiHapus()`).
- `bagian_kustom` → `poin` **cascadeOnDelete** di DB; aplikasi mencegah hapus bila ada poin (`BagianKustomManager.php:483-496`), tetapi DB sendiri tidak.
- Kolom JSON (`peran`, `penempatan`, `kolom`, `nilai_kolom`) tidak punya skema; query `whereJsonContains('penempatan', …)` (Verifikasi `:1117`) tergantung driver.
- `DasborUtama::ringkasan()` memakai `count(*) filter (where …)` khusus Postgres (`:101-104`).

---

## 9. Matriks hak akses

### 9.1 Siapa melakukan apa

| Aksi | Ketua Tim | Tim SAKIP | Kepala | Gerbang backend |
|---|---|---|---|---|
| Isi/ubah kegiatan, kendala, RTL, Bagian Kustom `pengisian` | ✔ (IKU **mana pun**) | — | — | route `role:Ketua Tim`; status dicek fresh; **tanpa cek penugasan** |
| Ajukan / ajukan ulang | ✔ | — | — | status Capaian `draft/diajukan/dikembalikan` |
| Hapus bukti lama | ✔ | — | — | `buktiLamaBisaDikelola()` (`PengisianKegiatan.php:1397`) |
| Isi Bagian Kustom di menu lain | ✔ (dasbor, rtl_evaluasi, notula_riwayat, lakin) | ✔ | ✔ | `bagianUntukSaya()`; **tanpa pemisahan data antarpengguna** |
| Tandai Sesuai/Tidak Sesuai, verifikasi selesai, kembalikan | — | ✔ | — | `bisaDiverifikasi()` = `diajukan/sedang_ditangani` |
| Koreksi teks, angka realisasi, RO, proksi, rincian N | — | ✔ kapan pun (termasuk `disetujui`) | — | **tanpa gerbang status** (`simpanPerubahan()` `:2366`, `kegiatanBisaDikoreksi()` `:2061`) |
| Buka kembali isian disetujui | — | ✔ | — | `bisaDibukaKembali()` |
| Master IKU, Target Tahunan, Rumus, Folder, Bagian Kustom | — | ✔ | — | route saja (kecuali formula `isTimSakip()`) |
| Susun/sunting/gabungkan notula | — | ✔ | — | route saja |
| Setujui / kembalikan notula / kembalikan isian | — | — | ✔ | route saja; status dicek di model |
| Unduh draf/pratinjau/docx lengkap | — | ✔ | ✔ (termasuk notula yang belum dikirim) | route |
| Unduh final | ✔ | ✔ | ✔ | status `disetujui` |
| Lihat berkas bukti | ✔ (**semua berkas**) | ✔ | — | route saja |
| Nilai SAKIP | — | ✔ | lihat | `abort_unless` |
| LAKIN ubah | — | ✔ | lihat | `abort_unless` |
| Verifikasi akun | — | ✔ | — | route; tidak mengecek status pending |

### 9.2 Risiko akses silang

1. **[V] Kepemilikan Bagian Kustom non-pengisian** (temuan #1 §1): satu set poin per bagian+periode dipakai bersama oleh semua pengguna/peran yang boleh membuka menunya; simpan menghapus poin yang tak terlihat di layar.
2. **[V] IKU tidak dibatasi penugasan** (Isian Kegiatan & Isian Bagian Kustom).
3. **[D] Properti Livewire tidak dikunci.** `IsianBagianKustom::$menu` (`:20`) dan id blok di `PengisianKegiatan` (`bagianKustomBlocks.*.id`, update lewat `BagianKustomPoin::whereKey($blok['id'])` tanpa cek bagian/IKU/periode `:4137-4146`) dapat diubah dari payload klien. Ketua Tim yang mengganti `menu` menjadi `pengisian` di komponen Bagian Kustom halaman Dasbor akan mendapat bagian ber-penempatan `pengisian` lewat jalur tanpa verifikasi & tanpa kunci status, lalu dapat menimpa/menghapus poin Ketua Tim lain. **Perlu uji** dengan payload Livewire termodifikasi.
4. **[V] Peran tidak dicek di aksi komponen** (temuan #3 §1).
5. **[V] Berkas bukti terbuka lintas tim** untuk Ketua Tim (`BerkasDownloadController`).
6. **[V] Poin Bagian Kustom yang diisi Tim SAKIP/Kepala langsung `terverifikasi`** dan, bila bagian juga ditaruh di `pengisian`, ikut tampil sebagai poin terverifikasi pada Detail Verifikasi IKU tersebut.
7. **[V] Penonaktifan bagian** (`toggleAktif()`) tidak punya konfirmasi atau pengecekan; bagian nonaktif tetap tercetak di Notula bila punya poin (sengaja, `NotulaService.php:590-593`).

### 9.3 Yang tidak boleh dikendalikan hanya oleh konfigurasi tampilan

Transisi status (Capaian, Kegiatan, Notula), penguncian isian saat ditangani, kepemilikan per tim/IKU, siapa boleh memverifikasi, gerbang unduh final, penghapusan bukti, pembukaan versi baru notula. Konfigurasi boleh *memilih* aturan dari daftar yang disediakan kode, tetapi penegakannya harus di service/policy.

---

## 10. Status konfigurasi A/B/C/D

**Tidak ada bagian yang diberi status A penuh.** Bagian Kustom ber-penempatan `pengisian` mendekati A, tetapi tidak punya versi/periode berlaku dan gaya notulanya tidak bisa diatur; bagian di menu lain cacat kepemilikan data.

| Bagian | Status | Alasan | Pendekatan |
|---|---|---|---|
| Bagian Kustom — penempatan `pengisian` | **B** | Struktur, validasi, penyimpanan, verifikasi, notula sudah dinamis; kurang: versi, periode berlaku, tipe kolom (tanggal, persen, rupiah, tautan, teks panjang), satuan & formatter per kolom angka, posisi notula per IKU | Perluas skema `bagian_kustom` + versi |
| Bagian Kustom — menu lain (`IsianBagianKustom`) | **B (dengan perbaikan wajib)** | Kepemilikan data & alur persetujuan tidak ada | Tambah pemilik/scope (pengguna/tim/peran) + filter muat/hapus sebelum diperluas |
| Katalog menu & penempatan | **B** | Registri di kode, cocok nama route persis, beberapa menu tak terlihat | Registri slot di kode (id slot, halaman, posisi, peran) + pilihan dari layar |
| Label & teks statis (judul bagian, petunjuk, nama satker, agenda) | **B** | Teks sebagian besar hard-code | Tabel pengaturan teks per kunci |
| Kendala & Solusi | **C** | Aturan triwulan (wajib bulan ke-3), verifikasi, notula kumulatif | Adaptor: konfigurasi label, kewajiban (pilihan terbatas), petunjuk; logika tetap kode |
| Evaluasi RTL & RTL berikutnya | **D** | Lintas triwulan, PIC otomatis dari tim IKU, bukti wajib, batas waktu resmi | Tetap kode; hanya label/teks via konfigurasi |
| Kegiatan + bukti + folder Drive | **D** | Tahapan, kegiatan induk, struktur folder, unggah Drive | Tetap kode |
| Target Tahunan / Rincian N | **D** | Kertas Kerja resmi, dua pola kumulatif, sinkron Y | Tetap kode |
| Realisasi & rumus capaian 2.3/2.4 | **C** | Rumus baku harus tunggal; rumus kustom per IKU sudah ada tapi tanpa versi & tidak dipakai di semua jalur | Satu service rumus + katalog rumus terkontrol + versi per tahun |
| Batas maksimal & format nol | **B** | Sudah konfigurasi, tetapi global tanpa periode | Tambah periode berlaku |
| Satuan & formatter angka | **C** | Satuan dipaksa dari metode, formatter tersebar | Registri formatter (persen, poin, bilangan bulat, rupiah, dst.) dipilih per IKU/variabel |
| RO & Indikator Proksi | **C** | Tabel notula kondisional, nilai teks | Adaptor: bisa menjadi Bagian Kustom tipe "tabel per IKU per TW" setelah tipe kolom & posisi notula tersedia |
| Analisis capaian / penjelasan lainnya | **C** | Teks bebas tetapi terpaut macro tetap di template | Adaptor variabel |
| Notula Bagian I (struktur) | **D** | Template resmi + OMML + blok kondisional + penggabungan docx | Template Word tetap sumber; konfigurasi hanya peta variabel & formatter |
| Notula Bagian II/III | **D** | Unggahan .docx asli | Tetap |
| Detail rapat notula | **B** | Kolom tetap | Bisa jadi kolom konfigurasi bila perlu |
| Alur status & persetujuan | **D** | Otorisasi & integritas dokumen | Tetap kode |
| Dasbor (kartu & tabel) | **C** | Murni hasil hitung, tetapi populasi & rumus perlu satu sumber | Adaptor ke service rumus tunggal; pilihan widget terbatas |
| LAKIN | **C** | Snapshot + suntingan manual | Hitung ulang lewat service rumus; tandai baris manual |
| Struktur folder | **B** | Sudah konfigurasi JSON | Tetap; tambah sinkron nama saat bagian diganti nama |
| Pengingat (jadwal, penerima, template) | **B** | Sudah konfigurasi | Tetap |
| Kelola pengguna, storage, backup | **D** | Administratif/keamanan | Di luar cakupan pusat konfigurasi |

---

## 11. Daftar masalah dan inkonsistensi

Urut dari dampak terbesar. "Ref" = lokasi kode.

| # | Masalah | Tanda | Ref | Dampak |
|---|---|---|---|---|
| 1 | Poin Bagian Kustom menu non-pengisian dimuat & dihapus tanpa memilah pengisi/peran/IKU | V | `IsianBagianKustom.php:90-109,183-220` | Kehilangan/tertimpanya data antarpengguna |
| 2 | Ketua Tim bebas memilih IKU mana pun | V | `PengisianKegiatan.php:3239-3246,4399`; `IsianBagianKustom.php:152,232` | Isian silang tim |
| 3 | Middleware `role` tidak persistent di Livewire; komponen tanpa cek peran internal | V | `PersistentMiddleware.php:16-25`; mis. `BagianKustomManager`, `TargetTahunan`, `PengaturanCapaian` | Otorisasi bergantung pada snapshot |
| 4 | Properti Livewire `menu` & id blok tidak dikunci / tidak di-scope | D (perlu uji) | `IsianBagianKustom.php:20`; `PengisianKegiatan.php:4137-4146` | Lewati kunci status & verifikasi |
| 5 | Ketua Tim dapat membuka berkas bukti mana pun | V | `BerkasDownloadController.php:23` | Kebocoran dokumen lintas tim |
| 6 | Dasbor tabel Rekap mengabaikan rumus kustom | V | `DasborCapaian.php:321,323` | Angka beda dengan Verifikasi/Notula |
| 7 | LAKIN sunting manual menghitung capaian tanpa batas & tanpa rumus kustom | V | `LakinDetail.php:118-125,144,179` | Capaian bisa >120% setelah "Simpan baris" tanpa ubah angka |
| 8 | Notula memuat poin Bagian Kustom tanpa filter status/penempatan/status IKU | V | `NotulaService.php:594-608` | Data belum diverifikasi atau ditolak masuk dokumen resmi |
| 9 | Kendala & solusi dan RTL TW ini di notula tanpa filter status | D | `NotulaService.php:560-578` | Isian ditolak/draft dapat tercetak |
| 10 | Rata-rata pembuka notula memakai populasi berbeda dari tabel IKU notula | V | `NotulaService.php:470-478` vs `656-666` | Angka ringkasan tidak sesuai isi tabel |
| 11 | Kartu rata-rata Dasbor: kosong = 0.0 dan populasi beda dari kartu TW | V | `DasborCapaian.php:335-340` | Inkonsistensi tampilan |
| 12 | Rumus kustom dapat membagi nol saat runtime; validasi hanya satu titik uji | D | `FormulaCapaianService.php:39-64` | Error 500 di banyak halaman |
| 13 | Rumus kustom mengabaikan aturan "-" & batas; bisa meluap `decimal(6,2)` | D | `CapaianTahunan.php:219-230`; migration lakin_baris | Nilai tak wajar / galat simpan |
| 14 | Pembulatan persen sebelum dipakai sebagai operand rasio | V | `CapaianTahunan.php:175-181` | Selisih desimal kecil |
| 15 | N di rumus notula (`y_alokasi_tw4`) bisa beda dari "N = … mencakup" (count rincian) | D | `NotulaBagian1DocxService.php:723,831-848`; `TargetTahunan.php:320-344` | Dokumen tidak konsisten bila rincian diubah tanpa simpan Target Tahunan, atau Verifikasi menyimpan Y basi dari properti yang dimuat sebelumnya (`VerifikasiCapaian.php:1997-1999`) |
| 16 | Dua penulis untuk `alokasi_tw1..4` (Target Tahunan & Verifikasi Poin) | V | `VerifikasiCapaian.php:2008-2016` | Perubahan saling menimpa tanpa jejak |
| 17 | Kolom & metode lama tetap ada/ditulis (`target_tahunan`, `x_target`, `y_target`, `capaian.target_pk…`, `Capaian::realisasiKumulatif()` tak terpakai, `batas_normalisasi_pko`) | V | §4.3 | Kebingungan sumber kebenaran |
| 18 | Formatter angka tidak seragam (titik/koma, jumlah desimal, lambang kosong "-", "—", "…") | V | §6.2 | Dokumen resmi tidak rapi |
| 19 | Excel LAKIN menulis angka sebagai teks | V | `LakinExport.php:30-32` | Tidak bisa dihitung di Excel |
| 20 | Bulan bahasa Inggris di produksi pada banyak tampilan & pesan pengingat | V (konfigurasi) | `render.yaml` `APP_LOCALE=en`; §6.3 | Tampilan & email salah bahasa |
| 21 | Nama satker hard-code di notula | V | `NotulaBagian1DocxService.php:418` | Tidak bisa dipakai satker lain |
| 22 | Tidak ada versi/periode berlaku untuk konfigurasi | V | §8.1 | Perubahan berlaku surut |
| 23 | Snapshot notula/LAKIN tidak ditandai basi saat Target Tahunan, Master IKU, batas, atau Bagian Kustom berubah | V | §7.4 | Dokumen berbeda dengan dasbor |
| 24 | Revisi dari Verifikasi menyusun ulang Bagian I penuh (tidak memakai mode pertahankan suntingan) | V | `NotulaService.php:1271-1273` | Suntingan manual hilang dari versi aktif (ada di cadangan, satu tingkat saja) |
| 25 | Katalog menu memuat halaman yang tidak ada di sidebar; tidak bisa memilih Detail Verifikasi/LAKIN detail | V | `MenuBagianKustom.php:31,34,37` | Bagian "hilang" |
| 26 | Bagian Kustom di halaman bertab tampil di bawah semua tab | V | `app.blade.php:114-117` | Penempatan tidak presisi |
| 27 | Ganti nama Bagian Kustom tidak mengganti kategori folder; unggahan berikutnya pakai nama baru | V | `FolderConfig.php:126-141`; `PengisianKegiatan.php:4179-4183` | Bukti tersebar di dua folder |
| 28 | Isian Bagian Kustom tidak bisa disimpan bila baris `periode` bulan itu belum pernah dibuat | V | `IsianBagianKustom.php:137-143` | Tim SAKIP/Kepala terhambat di awal bulan |
| 29 | Kolom angka Bagian Kustom disimpan sebagai string (koma/titik campur) | V | `BagianKustom.php:179-192` | Tidak bisa diagregasi |
| 30 | `frekuensi_wajib` tidak ditegakkan di Isian Bagian Kustom | V | `IsianBagianKustom.php:130-224` | Konfigurasi "wajib" tidak berlaku di menu lain |
| 31 | `KompilasiNotula` menjalankan penyusunan (LibreOffice) saat mount, ikut ter-mount di tab tersembunyi halaman Verifikasi | V | `verifikasi/index.blade.php`; `KompilasiNotula.php:157-176` | Beban & efek samping saat sekadar membuka Verifikasi |
| 32 | Kepala dapat mengunduh `.docx` lengkap & pratinjau notula berstatus apa pun | V | `routes/web.php:65-72` | Draf belum final terbaca Kepala (mungkin disengaja) [U] |
| 33 | `segarkanBaris()` LAKIN menimpa suntingan angka manual tanpa peringatan | V | `LakinBuilderService.php:117-140` | Suntingan hilang |
| 34 | Migration `rincian_n` baru belum dilacak git, kode sudah bergantung | V | §8.2 | Risiko deploy |

---

## 12. Rekomendasi arsitektur target

Prinsip: **kode mendefinisikan kemampuan, layar memilih dan mengisi parameter.** Bagian Kustom menjadi pusat konfigurasi untuk hal yang aman dikonfigurasi; bagian inti tetap di kode dan dihubungkan lewat adaptor.

### 12.1 Komponen dan apa yang dipakai ulang

| Kebutuhan | Pakai yang ada | Perluasan skema | Butuh kode khusus/adaptor |
|---|---|---|---|
| **Registrasi menu & penempatan** | `MenuBagianKustom` | — (tetap di kode) | Ubah menjadi registri **slot**: `{kunci, halaman/route, tab, posisi, peran, mode (per-IKU-dalam-alur / per-IKU-per-bulan / per-triwulan)}`. Layout merender slot, bukan nama route. Hapus/sembunyikan slot ke halaman yang tidak di sidebar |
| **Definisi bagian & kolom** | `bagian_kustom.kolom` json, `kolomList()`, `BagianKustomManager` | tabel `bagian_kustom_versi` (snapshot definisi: kolom, format, aturan) + `berlaku_mulai_tahun/triwulan`; poin menyimpan `bagian_kustom_versi_id` | Tipe kolom baru: tanggal, persen, bilangan bulat, desimal, rupiah, tautan, teks panjang — tiap tipe punya validator & formatter di kode |
| **Hak melihat & mengisi** | `peran`, `tampilDi()` | kolom poin `diisi_oleh` (user), `peran_pengisi`, `slot`; opsi scope `per_pengguna/per_tim/bersama` di definisi | **Policy** Laravel (`BagianKustomPoinPolicy`) + filter muat/hapus + `#[Locked]` pada properti identitas |
| **Definisi variabel** | `TemplateBagianKustom::VARIABEL_BAWAAN`, macro notula | tabel `katalog_variabel` (kunci, label, tipe, satuan, formatter default, sumber) — **diisi dari kode** (seeder/registri), hanya label & formatter yang bisa diubah | Resolver per sumber |
| **Sumber data yang diizinkan** | — | — | Daftar putih resolver di kode: `capaian_tahunan.*`, `rincian_n`, kolom Bagian Kustom, detail notula. Tidak ada query bebas |
| **Rumus yang aman** | `FormulaCapaianService`, `CapaianCalculatorService` | `master_iku_rumus` (atau kolom ber-versi per tahun) | Satu **`PenghitungCapaian`** yang dipakai Verifikasi, Dasbor (kartu & tabel), Notula, LAKIN (termasuk sunting manual). Rumus kustom: whitelist fungsi (`min`, `max`, `round`), matikan `constant()`, uji pada kisi nilai (0, negatif, null), tangkap galat → "-" |
| **Satuan & formatter** | `PengaturanCapaian::formatAngka/formatPersen` | kolom `satuan` bebas dari registri + `formatter` per IKU/variabel; `pengaturan_format` (desimal, pemisah, lambang kosong) ber-periode | Satu `FormatAngka` service + Blade directive; Excel menulis nilai numerik dengan format sel |
| **Periode mulai berlaku** | — | `berlaku_mulai_tahun`, `berlaku_mulai_triwulan` pada versi bagian, pengaturan capaian, rumus IKU | Resolver "konfigurasi yang berlaku untuk periode X" |
| **Versi konfigurasi** | `notula.versi` (pola), `riwayat_status_*` (pola audit) | tabel versi per entitas konfigurasi; poin & notula merujuk versi | Saat notula disetujui, simpan versi konfigurasi yang dipakai |
| **Integrasi template Notula** | `NotulaBagian1DocxService`, validator macro, `bagianKustomXml()` | opsi `posisi_notula` (global/per IKU), `macro_notula` opsional | Macro `{{bagian_kustom:<kunci>}}` di template agar gaya ikut template; validator mengenali macro dinamis dari bagian aktif |
| **Pratinjau & validasi** | `pratinjauNotula()`, `variabelTidakDikenal()`, `macroTidakDikenal()` | — | Pratinjau memakai formatter & versi yang sama dengan dokumen sungguhan; validasi rumus pada kisi nilai |
| **Audit perubahan** | pola `riwayat_status_*` | tabel `riwayat_konfigurasi` (entitas, id, sebelum/sesudah json, user, waktu) | Dicatat di service simpan, bukan di layar |

### 12.2 Yang sengaja **tidak** direkomendasikan

- Editor rumus bebas berbasis SQL atau PHP.
- Menjadikan Kegiatan, RTL, Target Tahunan, atau alur status sebagai formulir generik.
- Editor struktur template Word di layar — template tetap berkas Word dengan validator.
- Mesin workflow generik; cukup daftar aturan bernama di kode yang dapat dipilih.

---

## 13. Tahapan implementasi dan prioritas

| Tahap | Isi | Alasan urutan | Ketergantungan |
|---|---|---|---|
| **0 — Pengaman (wajib dulu)** | (a) Scope muat/hapus `IsianBagianKustom` + kolom pengisi pada poin; (b) batasi IKU ke penugasan di Isian Kegiatan & Isian Bagian Kustom; (c) cek peran di aksi komponen (helper/trait atau daftarkan `EnsureUserHasRole` sebagai persistent middleware Livewire); (d) `#[Locked]` untuk `menu` & scope id poin; (e) cek kepemilikan berkas di `BerkasDownloadController`; (f) commit + terapkan migration `rincian_n` baru secara terkoordinasi | Konfigurasi dinamis memperbesar permukaan; tanpa ini setiap bagian baru menambah risiko | — |
| **1 — Satu sumber rumus & format** | `PenghitungCapaian` dipakai Dasbor tabel & LAKIN manual; rumus kustom aman (kisi uji, tangkap galat); `FormatAngka` tunggal; locale tanggal eksplisit; Excel numerik; satukan populasi rata-rata (keputusan bisnis) | Prasyarat agar variabel & formatter dari konfigurasi menghasilkan angka sama di semua keluaran | Tahap 0 |
| **2 — Versi & periode berlaku** | `bagian_kustom_versi`, periode berlaku pada pengaturan capaian & rumus IKU; poin merujuk versi; notula menyimpan versi yang dipakai | Mencegah perubahan berlaku surut sebelum konfigurasi diperluas | Tahap 1 |
| **3 — Registri slot & penempatan** | Ganti katalog route menjadi slot; buang slot ke halaman tak terlihat; slot per tab; mode isi per triwulan | Memperluas tempat pakai dengan aman | Tahap 0 |
| **4 — Tipe kolom, satuan, variabel** | Tipe kolom baru dengan validator/formatter; katalog variabel; macro Bagian Kustom di template; posisi notula per IKU | Membuka migrasi RO/Proksi ke Bagian Kustom | Tahap 1–3 |
| **5 — Adaptor bagian inti** | Label/petunjuk/kewajiban terbatas untuk Kendala & Solusi, RO, Proksi, detail rapat; nama satker dari pengaturan | Nilai tambah tanpa menyentuh logika inti | Tahap 4 |
| **6 — Audit & penanda basi** | `riwayat_konfigurasi`; tandai notula/LAKIN basi saat sumber berubah; banner "data sumber berubah sejak disusun" | Transparansi dokumen | Tahap 2 |

---

## 14. Risiko migrasi dan strategi kompatibilitas

| Risiko | Strategi |
|---|---|
| Poin lama tanpa pengisi/peran | Isi pengisi dari `berkas.diunggah_oleh` bila ada; selebihnya tandai `bersama` dan tampilkan hanya-baca sampai diklaim; jangan hapus |
| Bagian yang ditempatkan di beberapa menu sekaligus | Saat migrasi, pecah menjadi slot per peran; poin lama dipetakan ke slot asal dengan heuristik (status `terverifikasi` + tanpa berkas = isian non-Ketua-Tim) — **perlu tinjauan manual** [U] |
| Versi pertama definisi | Buat `versi 1` dari baris `bagian_kustom` sekarang, berlaku sejak awal; semua poin lama → versi 1 |
| Rumus kustom yang sudah ada | Uji setiap `formula_capaian` produksi pada kisi nilai sebelum diaktifkan validator baru; yang gagal ditandai, bukan dibuang |
| Perubahan angka setelah penyatuan rumus | Hitung "sebelum vs sesudah" per IKU/tahun di lingkungan uji; laporkan selisih ke Tim SAKIP sebelum rilis; notula yang sudah disetujui tidak disusun ulang otomatis |
| Formatter baru mengubah tampilan dokumen resmi | Formatter default = perilaku lama; perubahan format berlaku dengan periode mulai |
| Kolom lama (`target_tahunan`, `capaian.target_pk`, `batas_normalisasi_pko`) | Hentikan penulisan dulu (impor Excel), pertahankan kolom satu siklus, baru hapus |
| CHECK constraint Postgres & JSON | Migration khusus driver seperti pola yang ada; uji di Postgres, bukan hanya SQLite |
| Cache `bagian-kustom.aktif` (file, 1 jam) | Kunci cache ikut versi; lupakan cache di service, bukan di komponen |
| Livewire persistent middleware | Uji semua komponen setelah menambah middleware persistent (alur upload file Livewire juga lewat endpoint khusus) |
| Deploy migration `rincian_n` | Terapkan migration sebelum kode; kolom ber-default sehingga aman untuk data lama |

---

## 15. Daftar file penting

**Bagian Kustom:** `app/Models/BagianKustom.php`, `app/Models/BagianKustomPoin.php`, `app/Support/MenuBagianKustom.php`, `app/Support/TemplateBagianKustom.php`, `app/Livewire/BagianKustomManager.php`, `app/Livewire/IsianBagianKustom.php`, `resources/views/livewire/bagian-kustom-manager.blade.php`, `resources/views/livewire/isian-bagian-kustom.blade.php`, `resources/views/livewire/partials/_peran-penempatan.blade.php`, `resources/views/layouts/app.blade.php:114-117`, migration `2026_08_08_005036/005037/005039`, `2026_08_10_013350`, `2026_08_11_000002`, `2026_08_27_000002`, `2026_08_29_000001`, `2026_10_14_000001_konfigurasi_lengkap_bagian_kustom`, `2026_10_15_000001_bagian_kustom_peran_dan_penempatan_menu`; test `tests/Feature/BagianKustomTest.php`, `BagianKustomKonfigurasiTest.php`.

**Rumus & capaian:** `app/Services/CapaianCalculatorService.php`, `app/Services/FormulaCapaianService.php`, `app/Models/CapaianTahunan.php`, `app/Models/Capaian.php`, `app/Models/RincianN.php`, `app/Models/PengaturanCapaian.php`, `app/Models/MasterIku.php`, `app/Livewire/TargetTahunan.php`, `app/Livewire/VerifikasiCapaian.php`, `app/Livewire/DasborCapaian.php`, `app/Livewire/PengaturanCapaian.php`, `app/Livewire/MasterIku.php`, `app/Services/MasterIkuImportValidator.php`.

**Isian & alur:** `app/Livewire/PengisianKegiatan.php`, `app/Models/Kegiatan.php`, `app/Models/KendalaSolusi.php`, `app/Models/RtlEvaluasi.php`, `app/Livewire/VerifikasiList.php`, `app/Http/Controllers/VerifikasiController.php`, `app/Livewire/DasborUtama.php`.

**Notula:** `app/Services/NotulaService.php`, `app/Services/NotulaBagian1DocxService.php`, `app/Services/LibreOfficeConversionService.php`, `app/Support/BlokBagianSatu.php`, `app/Support/RumusMarkup.php`, `app/Support/PenggabungDocx.php`, `app/Support/HtmlNotulaDompdf.php`, `app/Support/PenggantiBerkasAman.php`, `app/Models/Notula.php`, `app/Livewire/KompilasiNotula.php`, `app/Livewire/PersetujuanNotula.php`, `app/Livewire/TemplateNotula.php`, `app/Livewire/RiwayatNotula.php`, `app/Http/Controllers/NotulaDownloadController.php`, `resources/views/pdf/notula-utuh.blade.php`, `template_notula/*`.

**LAKIN & ekspor:** `app/Services/LakinBuilderService.php`, `app/Livewire/LakinList.php`, `app/Livewire/LakinDetail.php`, `app/Exports/LakinExport.php`, `app/Exports/MasterIkuTemplate*.php`.

**Akses:** `routes/web.php`, `bootstrap/app.php`, `app/Http/Middleware/EnsureUserHasRole.php`, `app/Http/Middleware/EnsureEmailIsComplete.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `app/Http/Controllers/BerkasDownloadController.php`, `app/Models/Berkas.php:113-140`, `vendor/livewire/livewire/src/Mechanisms/PersistentMiddleware/PersistentMiddleware.php`.

**Konfigurasi lain:** `app/Models/FolderConfig.php`, `app/Services/FolderStructureService.php`, `app/Models/Pengaturan*.php`, `routes/console.php`, `render.yaml`, `config/app.php`.

---

## 16. Hal yang belum dapat dipastikan

1. **Status migration dan isi data produksi** — tidak ada query produksi yang dijalankan (tidak ada izin khusus; `.env` lokal menunjuk produksi). Belum diketahui: apakah migration `rincian_n` 2026-10-21 sudah diterapkan, berapa bagian kustom yang ada dan di menu mana ditempatkan, apakah ada bagian yang ditaruh di lebih dari satu peran (menentukan apakah temuan #1 sudah menimbulkan kehilangan data), isi `formula_capaian` per IKU, dan nilai `pengaturan_capaian`.
2. **Variabel lingkungan produksi** di dasbor Render (apakah `APP_LOCALE=en` dari `render.yaml` ditimpa).
3. **Eksploitabilitas properti Livewire** (temuan #4) — belum diuji dengan payload termodifikasi.
4. **Isi Blade besar** (`pengisian-kegiatan.blade.php` 1324 baris, `verifikasi-capaian.blade.php` 1160 baris, `kompilasi-notula.blade.php` 597 baris) hanya dibaca sebagian lewat pencarian; bagian kondisional kecil (badge, petunjuk) mungkin belum seluruhnya tercatat.
5. **Komponen yang hanya dipindai sekilas**: `FolderConfigManager`, `StorageAccounts`, `BackupDatabase`, `AkunAktif`, `PenugasanIku`, `RtlEvaluasi`, pengingat & WhatsApp, `GoogleDriveService`, `FolderStructureService`, `PenggabungDocx`, `HtmlNotulaDompdf`. Tidak diaudit mendalam karena di luar inti konfigurasi; temuan di sana mungkin terlewat.
6. **Isi template Word** (`template_notula/*.docx`) tidak dibuka; daftar macro diambil dari kode validator dan panduan Markdown, bukan dari berkas Word.
7. **Perilaku `render.yaml` vs `Dockerfile`** dan apakah ada lebih dari satu instance (berpengaruh pada cache file & kunci sesi).
8. **Keputusan bisnis** yang memengaruhi rekomendasi: apakah rata-rata pembuka notula memang harus mencakup IKU yang belum terverifikasi; apakah Kepala boleh melihat draf notula; apakah Ketua Tim boleh melihat bukti tim lain; apakah poin Bagian Kustom yang diisi Tim SAKIP/Kepala perlu alur persetujuan.
9. **Test suite tidak dijalankan** (larangan menyentuh database; konfigurasi test belum diperiksa apakah memakai DB terpisah).
10. Laporan audit rumus capaian terdahulu yang disebut dalam permintaan **tidak ditemukan**; bila ada di lokasi lain, perlu dicocokkan dengan §4–§5.
