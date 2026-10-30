<?php

namespace App\Support;

/**
 * Registri statis variabel SISTEM SIPINTER — satu sumber untuk:
 * - halaman Katalog Variabel (dokumentasi),
 * - daftar placeholder yang dikenali template Bagian I notula (.docx, sintaks {{kode}}),
 *   lihat NotulaBagian1DocxService::macroDikenal(),
 * - variabel bawaan format baris notula Bagian Kustom (sintaks {kode}), lihat
 *   TemplateBagianKustom.
 *
 * Variabel sistem sengaja TIDAK disimpan sebagai baris database: nilainya diisi oleh kode
 * (resolver) yang sudah ada, jadi definisinya ikut kode dan tidak bisa terduplikasi saat
 * deploy. Hanya variabel yang benar-benar diisi resolver yang didaftarkan di sini; kode
 * (kunci array) adalah identitas tetap — nama/deskripsi boleh diubah, kode tidak.
 *
 * Variabel buatan pengguna (definisi bersama untuk kolom isian) tetap di tabel
 * variabel_katalog; variabel kolom Bagian Kustom dibentuk dari konfigurasi bagian.
 * Ketiganya disatukan untuk tampilan oleh KatalogVariabel.
 */
final class RegistriVariabel
{
    public const KONTEKS_NOTULA_DOCX = 'notula_docx';

    public const KONTEKS_FORMAT_BAGIAN_KUSTOM = 'format_bagian_kustom';

    public const KONTEKS_KOLOM_BAGIAN_KUSTOM = 'kolom_bagian_kustom';

    /** Tempat variabel bisa dipakai, sintaks penulisannya, dan letak pengaturannya. */
    public const KONTEKS = [
        self::KONTEKS_NOTULA_DOCX => [
            'label' => 'Template Bagian I Notula (.docx)',
            'sintaks' => '{{%s}}',
            'tempat' => 'Verifikasi & Notula → Template Notula (berkas .docx diunggah)',
        ],
        self::KONTEKS_FORMAT_BAGIAN_KUSTOM => [
            'label' => 'Format baris notula Bagian Kustom',
            'sintaks' => '{%s}',
            'tempat' => 'Data Master → Bagian Kustom → tab Notula & Variabel',
        ],
        self::KONTEKS_KOLOM_BAGIAN_KUSTOM => [
            'label' => 'Kolom isian Bagian Kustom',
            'sintaks' => null,
            'tempat' => 'Data Master → Bagian Kustom → Struktur Isian → kolom → Variabel katalog',
        ],
    ];

    public const KATEGORI = [
        'organisasi' => 'Organisasi',
        'periode' => 'Periode',
        'rapat' => 'Rapat & Notula',
        'iku' => 'Master IKU',
        'target_capaian' => 'Target, Realisasi & Capaian (termasuk n/N)',
        'kegiatan' => 'Kegiatan & Rincian Output',
        'bukti' => 'Bukti Dukung',
        'rtl' => 'Kendala, Solusi, RTL & Evaluasi',
        'verifikasi' => 'Verifikasi & Persetujuan',
        'bagian_kustom' => 'Bagian Kustom',
        'struktur' => 'Struktur Template (blok berulang/bersyarat)',
    ];

    /** Asal nilai variabel. */
    public const JENIS = [
        'sistem' => 'Sistem — diambil langsung dari data SIPINTER',
        'turunan' => 'Turunan — dihitung/diformat sistem',
        'penyajian' => 'Khusus template — penanda blok/penyajian',
        'kustom' => 'Kolom Bagian Kustom — isian pengguna',
        'definisi' => 'Definisi bersama — dipakai kolom isian',
    ];

    public const TIPE = [
        'teks' => 'Teks',
        'teks_panjang' => 'Teks panjang',
        'angka' => 'Angka',
        'persen' => 'Persentase',
        'tanggal' => 'Tanggal/waktu',
        'tautan' => 'Tautan',
        'daftar' => 'Daftar bernomor (koleksi)',
        'rumus' => 'Rumus',
        'blok' => 'Blok berulang/bersyarat',
    ];

    public const STATUS = [
        'aktif' => 'Aktif',
        'usang' => 'Historis — tidak disarankan untuk pemakaian baru',
        'nonaktif' => 'Nonaktif',
    ];

    /** Lingkup placeholder di template .docx — di mana placeholder harus diletakkan agar terisi. */
    public const LINGKUP = [
        'global' => 'Di mana saja dalam dokumen',
        'iku_blok' => 'Hanya di dalam {{iku_blok}} … {{/iku_blok}} (diisi per IKU)',
        'blok_proksi' => 'Hanya di baris tabel di dalam {{blok_proksi}} … {{/blok_proksi}} (diulang per proksi)',
        'ro_row' => 'Hanya di dalam {{ro_row}} … {{/ro_row}} (diulang per rincian output)',
    ];

    private const KOSONG_DOCX = 'Dicetak "…" bila belum ada nilai.';

    private const KOSONG_ANGKA = 'Kosong dicetak "-" (lalu "…" bila tidak ada data sama sekali); nol dicetak "0", atau "-" bila Pengaturan Capaian "tampilkan nol sebagai strip" aktif.';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $memo = null;

    /**
     * Semua variabel sistem, dikunci pada kode.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function semua(): array
    {
        return self::$memo ??= self::bangun();
    }

    /** @return array<string, mixed>|null */
    public static function cari(string $kode): ?array
    {
        return self::semua()[$kode] ?? null;
    }

    /**
     * Kode variabel yang berlaku di satu konteks.
     *
     * @return list<string>
     */
    public static function kodeKonteks(string $konteks, bool $termasukUsang = true): array
    {
        return array_values(array_keys(array_filter(self::semua(), fn (array $v) => in_array($konteks, $v['konteks'], true)
            && ($termasukUsang || $v['status'] === 'aktif'))));
    }

    /**
     * Placeholder .docx yang benar-benar DIISI/diproses generate() — termasuk penanda
     * penutup blok ("/iku_blok"). Variabel usang yang tidak lagi diproses tidak ikut.
     *
     * @return list<string>
     */
    public static function placeholderDocxDiproses(): array
    {
        $hasil = [];

        foreach (self::semua() as $kode => $v) {
            if (! in_array(self::KONTEKS_NOTULA_DOCX, $v['konteks'], true) || ! $v['diproses']) {
                continue;
            }

            $hasil[] = $kode;

            if ($v['penutup'] !== null) {
                $hasil[] = $v['penutup'];
            }
        }

        return $hasil;
    }

    /** Tulisan variabel di sebuah konteks, mis. "{{kode}}" atau "{kode_iku}". */
    public static function tulisan(string $kode, string $konteks): ?string
    {
        $pola = self::KONTEKS[$konteks]['sintaks'] ?? null;

        return $pola !== null ? sprintf($pola, $kode) : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function bangun(): array
    {
        $docx = [self::KONTEKS_NOTULA_DOCX];
        $format = [self::KONTEKS_FORMAT_BAGIAN_KUSTOM];

        $daftar = [
            /* ---------- Organisasi ---------- */
            self::v('nama_satker', 'Nama satuan kerja', 'organisasi', 'sistem', 'teks', $docx, [
                'deskripsi' => 'Nama satuan kerja pada kop/judul notula.',
                'sumber' => 'Nilai tetap di NotulaBagian1DocxService::isiHeader().',
                'aturan' => 'Selalu "BPS KABUPATEN BUTON UTARA".',
                'contoh' => 'BPS KABUPATEN BUTON UTARA',
                'catatan' => 'Belum bisa diubah lewat pengaturan — perubahan nama satker memerlukan perubahan kode.',
            ]),

            /* ---------- Periode ---------- */
            self::v('tahun', 'Tahun', 'periode', 'sistem', 'angka', [self::KONTEKS_NOTULA_DOCX, self::KONTEKS_FORMAT_BAGIAN_KUSTOM], [
                'deskripsi' => 'Tahun anggaran. Di template notula: tahun periode notula. Di format Bagian Kustom: tahun periode poin.',
                'sumber' => 'periode.tahun (Notula → periode; Bagian Kustom → poin → periode).',
                'aturan' => 'Diambil apa adanya, tanpa pemisah ribuan.',
                'contoh' => '2026',
                'kosong' => 'Template notula: selalu terisi. Format Bagian Kustom: kosong bila poin tanpa periode.',
            ]),
            self::v('triwulan_angka', 'Triwulan (romawi)', 'periode', 'turunan', 'teks', $docx, [
                'deskripsi' => 'Triwulan notula dalam angka romawi.',
                'sumber' => 'periode.triwulan notula.',
                'aturan' => '1–4 diubah menjadi I–IV.',
                'contoh' => 'III',
            ]),
            self::v('triwulan_sebelumnya', 'Triwulan sebelumnya (romawi)', 'periode', 'turunan', 'teks', $docx, [
                'deskripsi' => 'Triwulan sebelum triwulan notula pada tahun yang sama, mis. untuk kalimat "RTL triwulan II".',
                'sumber' => 'periode.triwulan notula − 1.',
                'aturan' => 'TW II → "I", TW III → "II", TW IV → "III".',
                'contoh' => 'II',
                'kosong' => 'Notula TW I tidak punya triwulan sebelumnya: dicetak "…".',
            ]),
            self::v('triwulan', 'Triwulan poin (romawi)', 'periode', 'turunan', 'teks', $format, [
                'deskripsi' => 'Triwulan periode poin Bagian Kustom.',
                'sumber' => 'bagian_kustom_poin → periode.triwulan.',
                'aturan' => '1–4 diubah menjadi I–IV.',
                'contoh' => 'I',
                'kosong' => 'Kosong bila poin tanpa periode.',
            ]),
            self::v('bulan', 'Bulan poin', 'periode', 'turunan', 'teks', $format, [
                'deskripsi' => 'Nama bulan periode poin Bagian Kustom diisi.',
                'sumber' => 'bagian_kustom_poin → periode.bulan.',
                'aturan' => '1–12 diubah menjadi Januari–Desember.',
                'contoh' => 'Maret',
                'kosong' => 'Kosong bila poin tanpa periode.',
            ]),

            /* ---------- Rapat & notula ---------- */
            self::v('agenda_pembahasan', 'Agenda pembahasan', 'rapat', 'turunan', 'teks', $docx, [
                'deskripsi' => 'Kalimat agenda rapat monitoring.',
                'sumber' => 'Periode notula.',
                'aturan' => '"Monitoring Kinerja Triwulan {romawi} Tahun {tahun}".',
                'contoh' => 'Monitoring Kinerja Triwulan III Tahun 2026',
            ]),
            self::v('hari_tanggal', 'Hari, tanggal rapat', 'rapat', 'sistem', 'tanggal', $docx, [
                'deskripsi' => 'Hari dan tanggal rapat seperti diisi di Detail Rapat.',
                'sumber' => 'notula.hari_tanggal (Kompilasi Notula → Detail Rapat).',
                'aturan' => 'Teks apa adanya.',
                'contoh' => 'Senin, 6 Oktober 2026',
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('waktu', 'Waktu rapat', 'rapat', 'sistem', 'teks', $docx, [
                'deskripsi' => 'Jam rapat.',
                'sumber' => 'notula.waktu (Detail Rapat).',
                'aturan' => 'Teks apa adanya.',
                'contoh' => '09.00 – 11.00 WITA',
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('tempat', 'Tempat rapat', 'rapat', 'sistem', 'teks', $docx, [
                'deskripsi' => 'Lokasi rapat.',
                'sumber' => 'notula.tempat (Detail Rapat).',
                'aturan' => 'Teks apa adanya.',
                'contoh' => 'Ruang Rapat Kantor',
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('pimpinan_rapat', 'Pimpinan rapat', 'rapat', 'sistem', 'teks', $docx, [
                'deskripsi' => 'Nama pimpinan rapat.',
                'sumber' => 'notula.pimpinan_rapat (Detail Rapat).',
                'aturan' => 'Teks apa adanya.',
                'contoh' => 'Kepala BPS Kabupaten',
                'kosong' => self::KOSONG_DOCX,
            ]),

            /* ---------- Master IKU ---------- */
            self::v('kode', 'Kode IKU', 'iku', 'sistem', 'teks', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Kode Master IKU pada salinan blok IKU.',
                'sumber' => 'master_iku.kode.',
                'aturan' => 'Teks apa adanya.',
                'contoh' => '1131',
            ]),
            self::v('indikator', 'Indikator IKU', 'iku', 'sistem', 'teks_panjang', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Teks indikator Master IKU.',
                'sumber' => 'master_iku.indikator.',
                'aturan' => 'Teks apa adanya.',
                'contoh' => 'Persentase rekomendasi hasil pengawasan yang ditindaklanjuti',
            ]),
            self::v('sasaran', 'Sasaran IKU', 'iku', 'sistem', 'teks_panjang', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Sasaran strategis Master IKU.',
                'sumber' => 'master_iku.sasaran.',
                'aturan' => 'Teks apa adanya. IKU tanpa sasaran tidak masuk Bagian I.',
                'contoh' => 'Meningkatnya kualitas tata kelola',
            ]),
            self::v('kode_iku', 'Kode IKU poin', 'iku', 'sistem', 'teks', $format, [
                'deskripsi' => 'Kode IKU tempat poin Bagian Kustom diisi.',
                'sumber' => 'bagian_kustom_poin → master_iku.kode.',
                'aturan' => 'Teks apa adanya.',
                'contoh' => '1131',
                'kosong' => 'Kosong bila poin tidak terkait IKU.',
            ]),
            self::v('indikator_iku', 'Indikator IKU poin', 'iku', 'sistem', 'teks_panjang', $format, [
                'deskripsi' => 'Teks indikator IKU tempat poin diisi.',
                'sumber' => 'bagian_kustom_poin → master_iku.indikator.',
                'aturan' => 'Teks apa adanya.',
                'contoh' => 'Persentase rekomendasi yang ditindaklanjuti',
                'kosong' => 'Kosong bila poin tidak terkait IKU.',
            ]),
            self::v('sasaran_iku', 'Sasaran IKU poin', 'iku', 'sistem', 'teks_panjang', $format, [
                'deskripsi' => 'Sasaran strategis IKU tempat poin diisi.',
                'sumber' => 'bagian_kustom_poin → master_iku.sasaran.',
                'aturan' => 'Teks apa adanya.',
                'contoh' => 'Meningkatnya kualitas tata kelola',
                'kosong' => 'Kosong bila poin tidak terkait IKU atau IKU tanpa sasaran.',
            ]),
            self::v('satuan_iku', 'Satuan IKU poin', 'iku', 'sistem', 'teks', $format, [
                'deskripsi' => 'Satuan IKU tempat poin diisi (mengikuti metode perhitungan: Rasio → Persen, Langsung → Poin).',
                'sumber' => 'bagian_kustom_poin → master_iku.satuan.',
                'aturan' => 'Teks apa adanya.',
                'contoh' => 'Persen',
                'kosong' => 'Kosong bila poin tidak terkait IKU.',
            ]),

            /* ---------- Target, realisasi, capaian ---------- */
            self::v('target_pk', 'Target PK (tahunan)', 'target_capaian', 'turunan', 'angka', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Target Perjanjian Kinerja setahun.',
                'sumber' => 'CapaianTahunan::targetTahunan() tahun notula (diisi Tim SAKIP di Target Tahunan/Verifikasi).',
                'aturan' => 'Diformat PengaturanCapaian::formatAngka().',
                'contoh' => '100',
                'kosong' => self::KOSONG_ANGKA,
            ]),
            self::v('target_tw', 'Target s.d. triwulan', 'target_capaian', 'turunan', 'angka', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Target kumulatif sampai triwulan notula.',
                'sumber' => 'CapaianTahunan::alokasiKumulatif(triwulan).',
                'aturan' => 'Diformat PengaturanCapaian::formatAngka().',
                'contoh' => '75',
                'kosong' => self::KOSONG_ANGKA,
            ]),
            self::v('realisasi_tw', 'Realisasi s.d. triwulan', 'target_capaian', 'turunan', 'angka', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Realisasi triwulan notula. IKU metode Rasio memakai Realisasi Kumulatif (%) yang sama dengan Verifikasi Capaian.',
                'sumber' => 'IKU Rasio: CapaianTahunan::realisasiKumulatifPresisi(tw). IKU Langsung: capaian_tahunan.realisasi_tw{n}.',
                'aturan' => 'Diformat PengaturanCapaian::formatRealisasiKumulatif(): 5 desimal, koma desimal.',
                'contoh' => '62,50000',
                'kosong' => 'Kosong dicetak "-".',
            ]),
            self::v('capaian_tw', 'Capaian terhadap target triwulan', 'target_capaian', 'turunan', 'persen', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Persentase capaian terhadap target s.d. triwulan.',
                'sumber' => 'CapaianTahunan::capaianTriwulanan(tw).',
                'aturan' => 'Diformat PengaturanCapaian::formatPersen() (angka + "%").',
                'contoh' => '83.33%',
                'kosong' => self::KOSONG_ANGKA,
            ]),
            self::v('capaian_pk', 'Capaian terhadap target PK', 'target_capaian', 'turunan', 'persen', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Persentase capaian terhadap target setahun.',
                'sumber' => 'CapaianTahunan::capaianSetahun(tw).',
                'aturan' => 'Diformat PengaturanCapaian::formatPersen().',
                'contoh' => '62.5%',
                'kosong' => self::KOSONG_ANGKA,
            ]),
            self::v('capaian_triwulanan_persen', 'Rata-rata capaian kinerja triwulan', 'target_capaian', 'turunan', 'persen', $docx, [
                'deskripsi' => 'Rata-rata capaian kinerja seluruh IKU tahun ini terhadap target triwulanan, untuk paragraf pembuka.',
                'sumber' => 'CapaianCalculatorService::rataRataCapaianTriwulanan() atas seluruh CapaianTahunan tahun notula — sama dengan Dasbor Capaian.',
                'aturan' => 'Dibulatkan 2 desimal, tanpa tanda "%" (titik desimal).',
                'contoh' => '98.5',
                'kosong' => 'Belum ada IKU yang ternilai: dicetak "…".',
            ]),
            self::v('capaian_pk_persen', 'Rata-rata capaian kinerja setahun', 'target_capaian', 'turunan', 'persen', $docx, [
                'deskripsi' => 'Rata-rata capaian seluruh IKU tahun ini terhadap target setahun (pembagi = seluruh indikator).',
                'sumber' => 'CapaianCalculatorService::rataRataCapaianSetahun() — sama dengan Dasbor Capaian.',
                'aturan' => 'Dibulatkan 2 desimal, tanpa tanda "%" (titik desimal).',
                'contoh' => '71.25',
                'kosong' => 'Belum ada IKU yang ternilai: dicetak "…".',
            ]),
            self::v('formula_capaian', 'Rumus capaian (n/N)', 'target_capaian', 'turunan', 'rumus', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Rumus bersusun "y = n/N × 100%" untuk IKU bersatuan Persen, atau rumus pada baris pertama Dasar Hitung (sintaks rumus) untuk IKU lain. Letakkan di dalam {{blok_formula}}.',
                'sumber' => 'n = jumlah x_realisasi TW I s.d. triwulan notula; N = capaian_tahunan.y_alokasi_tw4; atau master_iku.dasar_hitung baris pertama.',
                'aturan' => 'Dicetak sebagai rumus Word (OOXML Math) bersusun.',
                'contoh' => 'y = 5/8 × 100%',
                'kosong' => 'Data n/N belum lengkap dan tidak ada rumus manual: seluruh {{blok_formula}} dibuang.',
            ]),
            self::v('dasar_hitung', 'Dasar hitung & basis data', 'target_capaian', 'turunan', 'teks_panjang', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Keterangan n/N dan rincian N, ditambah Dasar Hitung serta Basis Data Master IKU.',
                'sumber' => 'Rincian n/N CapaianTahunan + master_iku.dasar_hitung + master_iku.basis_data.',
                'aturan' => 'Sintaks rumus diubah ke teks polos; daftar bernomor dipertahankan.',
                'contoh' => 'dimana: n = 5 rekomendasi ditindaklanjuti, N = 8 rekomendasi. Basis Data: Laporan TL',
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('proksi_uraian', 'Uraian indikator proksi', 'target_capaian', 'sistem', 'teks', $docx, [
                'lingkup' => 'blok_proksi',
                'deskripsi' => 'Uraian satu indikator proksi; baris tabel yang memuatnya diulang per proksi.',
                'sumber' => 'indikator_proksi.uraian (Detail Verifikasi → Indikator Proksi), tahun & triwulan notula.',
                'aturan' => 'Teks apa adanya.',
                'contoh' => 'Nilai SAKIP',
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('proksi_target', 'Target indikator proksi', 'target_capaian', 'sistem', 'teks', $docx, [
                'lingkup' => 'blok_proksi',
                'deskripsi' => 'Target satu indikator proksi.',
                'sumber' => 'indikator_proksi.target.',
                'aturan' => 'Teks apa adanya.',
                'contoh' => '75',
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('proksi_realisasi', 'Realisasi indikator proksi', 'target_capaian', 'sistem', 'teks', $docx, [
                'lingkup' => 'blok_proksi',
                'deskripsi' => 'Realisasi satu indikator proksi.',
                'sumber' => 'indikator_proksi.realisasi.',
                'aturan' => 'Teks apa adanya.',
                'contoh' => '72,5',
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('target_proksi', 'Target proksi (lama)', 'target_capaian', 'penyajian', 'teks', $docx, [
                'status' => 'usang', 'pengganti' => 'proksi_target', 'diproses' => false,
                'deskripsi' => 'Placeholder template lama untuk tabel proksi khusus SAKIP.',
                'sumber' => 'Tidak lagi diisi.',
                'aturan' => 'Tidak diganti — tercetak mentah. Gunakan {{proksi_target}} di dalam {{blok_proksi}}.',
                'contoh' => '—',
            ]),
            self::v('realisasi_proksi', 'Realisasi proksi (lama)', 'target_capaian', 'penyajian', 'teks', $docx, [
                'status' => 'usang', 'pengganti' => 'proksi_realisasi', 'diproses' => false,
                'deskripsi' => 'Placeholder template lama untuk tabel proksi khusus SAKIP.',
                'sumber' => 'Tidak lagi diisi.',
                'aturan' => 'Tidak diganti — tercetak mentah. Gunakan {{proksi_realisasi}} di dalam {{blok_proksi}}.',
                'contoh' => '—',
            ]),

            /* ---------- Kegiatan ---------- */
            self::v('analisis_capaian', 'Analisis capaian & daftar kegiatan', 'kegiatan', 'turunan', 'daftar', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Narasi analisis capaian dari Tim SAKIP, diikuti daftar bernomor nama kegiatan pendukung IKU yang sudah diverifikasi pada triwulan notula.',
                'sumber' => 'capaian.analisis_capaian (baris terbaru triwulan ini) + Kegiatan::daftarNamaCapaian() kegiatan berstatus diverifikasi/disetujui.',
                'aturan' => 'Satu nama per kegiatan induk (tahapan tidak diulang); daftar bernomor Word.',
                'contoh' => "Capaian sesuai target.\n1. Rapat koordinasi\n2. Penyusunan laporan",
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('ro_uraian', 'Uraian rincian output', 'kegiatan', 'sistem', 'teks', $docx, [
                'lingkup' => 'ro_row',
                'deskripsi' => 'Nama rincian output (RO) satu baris tabel RO.',
                'sumber' => 'rincian_output.uraian, atau nama kegiatan induk bila RO belum diberi nama.',
                'aturan' => 'Hanya RO yang sudah punya realisasi volume/progres yang dicetak.',
                'contoh' => 'Laporan Monitoring',
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('ro_vol', 'Volume rincian output', 'kegiatan', 'sistem', 'teks', $docx, [
                'lingkup' => 'ro_row',
                'deskripsi' => 'Realisasi volume RO.',
                'sumber' => 'rincian_output.volume_ro.',
                'aturan' => 'Teks apa adanya.',
                'contoh' => '2 Laporan',
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('ro_progres', 'Progres rincian output', 'kegiatan', 'turunan', 'persen', $docx, [
                'lingkup' => 'ro_row',
                'deskripsi' => 'Progres pelaksanaan RO.',
                'sumber' => 'rincian_output.progres_persen.',
                'aturan' => '2 desimal, koma desimal, diakhiri "%".',
                'contoh' => '75,00%',
                'kosong' => self::KOSONG_DOCX,
            ]),

            /* ---------- Bukti dukung ---------- */
            self::v('bukti_realisasi', 'Tautan bukti realisasi', 'bukti', 'turunan', 'tautan', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Tautan folder Google Drive "Capaian" milik IKU pada triwulan notula.',
                'sumber' => 'FolderStructureService::linkKategoriIku(periode, IKU, capaian) — hanya IKU yang punya kegiatan triwulan ini.',
                'aturan' => 'Dicetak sebagai hyperlink Word yang bisa diklik.',
                'contoh' => 'https://drive.google.com/drive/folders/…',
                'kosong' => self::KOSONG_DOCX,
                'catatan' => 'Hanya tautan folder yang ditampilkan — tidak ada ID berkas, token, atau akun penyimpanan.',
            ]),
            self::v('bukti_rtl_sebelumnya', 'Tautan bukti RTL triwulan sebelumnya', 'bukti', 'turunan', 'tautan', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Tautan folder bukti dukung IKU pada triwulan sebelumnya, bila ada RTL triwulan sebelumnya.',
                'sumber' => 'FolderStructureService::linkBuktiDukungIku(triwulan − 1, IKU).',
                'aturan' => 'Hyperlink Word.',
                'contoh' => 'https://drive.google.com/drive/folders/…',
                'kosong' => 'TW I atau tidak ada RTL triwulan sebelumnya: dicetak "…".',
            ]),
            self::v('bukti_rtl_evaluasi', 'Tautan bukti evaluasi RTL', 'bukti', 'turunan', 'tautan', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Tautan folder "Evaluasi-RTL" IKU pada triwulan notula (bukti Bagian 4 Isian Kegiatan).',
                'sumber' => 'FolderStructureService::linkKategoriIku(periode RTL, IKU, evaluasi_rtl).',
                'aturan' => 'Hyperlink Word.',
                'contoh' => 'https://drive.google.com/drive/folders/…',
                'kosong' => 'IKU tanpa poin RTL: dicetak "…".',
            ]),
            self::v('lampiran_basis_data', 'Tautan lampiran basis data', 'bukti', 'sistem', 'tautan', $docx, [
                'deskripsi' => 'Tautan lampiran basis data notula (sama untuk seluruh IKU).',
                'sumber' => 'Notula::linkLampiranBasisDataEfektif() (Detail Rapat).',
                'aturan' => 'Hyperlink Word.',
                'contoh' => 'https://drive.google.com/drive/folders/…',
                'kosong' => self::KOSONG_DOCX,
            ]),

            /* ---------- Kendala, solusi, RTL ---------- */
            self::v('kendala', 'Kendala', 'rtl', 'sistem', 'daftar', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Daftar kendala IKU pada triwulan notula (tidak kumulatif).',
                'sumber' => 'kendala_solusi.kendala, periode triwulan notula.',
                'aturan' => 'Daftar bernomor.',
                'contoh' => "1. Data mitra terlambat",
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('solusi', 'Solusi', 'rtl', 'sistem', 'daftar', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Daftar solusi IKU pada triwulan notula.',
                'sumber' => 'kendala_solusi.solusi, periode triwulan notula.',
                'aturan' => 'Daftar bernomor.',
                'contoh' => "1. Jadwal pengumpulan dipercepat",
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('rtl', 'Rencana tindak lanjut', 'rtl', 'sistem', 'daftar', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Poin RTL yang direncanakan untuk triwulan notula.',
                'sumber' => 'rtl_evaluasi.rtl_teks, periode triwulan notula.',
                'aturan' => 'Daftar bernomor.',
                'contoh' => "1. Koordinasi ulang dengan mitra",
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('rtl_evaluasi', 'RTL yang dievaluasi', 'rtl', 'sistem', 'daftar', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Bagian 4 Isian Kegiatan: poin RTL triwulan notula yang dievaluasi. Isinya sama dengan {{rtl}}.',
                'sumber' => 'rtl_evaluasi.rtl_teks, periode triwulan notula.',
                'aturan' => 'Daftar bernomor.',
                'contoh' => "1. Koordinasi ulang dengan mitra",
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('rtl_berikutnya', 'RTL triwulan berikutnya', 'rtl', 'sistem', 'daftar', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Bagian 5 Isian Kegiatan: poin RTL untuk triwulan berikutnya (TW IV → TW I tahun depan).',
                'sumber' => 'rtl_evaluasi.rtl_teks periode triwulan berikutnya, selain draf.',
                'aturan' => 'Daftar bernomor; draf yang belum diajukan tidak ikut.',
                'contoh' => "1. Evaluasi kualitas data",
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('pic_rtl', 'PIC tindak lanjut', 'rtl', 'sistem', 'teks', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Tim yang ditugaskan pada IKU (Master IKU), bukan nama orang.',
                'sumber' => 'MasterIku::namaTimList() (iku_tim).',
                'aturan' => 'Digabung dengan "dan", mis. "Tim A dan Tim B".',
                'contoh' => 'Tim Umum dan Tim SAKIP',
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('batas_waktu_rtl', 'Batas waktu tindak lanjut', 'rtl', 'turunan', 'tanggal', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Akhir bulan triwulan notula — satu batas waktu untuk semua poin RTL triwulan itu.',
                'sumber' => 'Tahun & triwulan notula.',
                'aturan' => 'Format "Bulan Tahun" berbahasa Indonesia.',
                'contoh' => 'September 2026',
                'kosong' => 'IKU tanpa poin RTL: dicetak "…".',
            ]),
            self::v('batas_waktu_rtl_berikutnya', 'Batas waktu RTL triwulan berikutnya', 'rtl', 'turunan', 'tanggal', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Akhir bulan triwulan berikutnya.',
                'sumber' => 'Triwulan berikutnya dari periode notula.',
                'aturan' => 'Format "Bulan Tahun".',
                'contoh' => 'Desember 2026',
                'kosong' => 'Tidak ada RTL triwulan berikutnya: dicetak "…".',
            ]),

            /* ---------- Verifikasi & persetujuan ---------- */
            self::v('penjelasan_lainnya', 'Penjelasan lainnya (catatan verifikasi)', 'verifikasi', 'sistem', 'teks_panjang', $docx, [
                'lingkup' => 'iku_blok',
                'deskripsi' => 'Catatan Tim SAKIP pada Detail Verifikasi capaian IKU triwulan notula.',
                'sumber' => 'capaian.catatan (baris terbaru triwulan ini).',
                'aturan' => 'Teks apa adanya; baris berawalan nomor menjadi daftar.',
                'contoh' => 'Data sudah dicek silang dengan laporan tim.',
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('kotaTtd', 'Kota tanda tangan', 'verifikasi', 'sistem', 'teks', $docx, [
                'deskripsi' => 'Kota pada blok tanda tangan.',
                'sumber' => 'notula.kota_ttd (Detail Rapat).',
                'aturan' => 'Seluruh tabel TTD hanya tercetak setelah notula disetujui Kepala.',
                'contoh' => 'Buranga',
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('tanggal', 'Tanggal tanda tangan', 'verifikasi', 'turunan', 'tanggal', $docx, [
                'deskripsi' => 'Tanggal pada blok tanda tangan, mengikuti tanggal rapat (tanpa nama hari), bukan tanggal klik "Setuju".',
                'sumber' => 'Notula::tanggalTtd() dari notula.hari_tanggal.',
                'aturan' => 'Seluruh tabel TTD hanya tercetak setelah notula disetujui Kepala.',
                'contoh' => '6 Oktober 2026',
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('namaKepala', 'Nama Kepala', 'verifikasi', 'sistem', 'teks', $docx, [
                'deskripsi' => 'Nama Kepala satker penanda tangan.',
                'sumber' => 'notula.kepala_satker (Detail Rapat).',
                'aturan' => 'Seluruh tabel TTD hanya tercetak setelah notula disetujui Kepala.',
                'contoh' => 'Nama Kepala',
                'kosong' => self::KOSONG_DOCX,
            ]),
            self::v('namaNotulis', 'Nama notulis', 'verifikasi', 'sistem', 'teks', $docx, [
                'deskripsi' => 'Nama notulis rapat.',
                'sumber' => 'notula.notulis (Detail Rapat).',
                'aturan' => 'Seluruh tabel TTD hanya tercetak setelah notula disetujui Kepala.',
                'contoh' => 'Nama Notulis',
                'kosong' => self::KOSONG_DOCX,
            ]),

            /* ---------- Bagian Kustom ---------- */
            self::v('teks', 'Uraian poin', 'bagian_kustom', 'sistem', 'teks_panjang', $format, [
                'deskripsi' => 'Isi uraian poin Bagian Kustom (kolom "Uraian").',
                'sumber' => 'bagian_kustom_poin.teks.',
                'aturan' => 'Teks apa adanya.',
                'contoh' => 'Keterlambatan data mitra',
                'kosong' => 'Kosong bila uraian tidak diisi.',
            ]),
            self::v('nama_bagian', 'Nama bagian kustom', 'bagian_kustom', 'sistem', 'teks', $format, [
                'deskripsi' => 'Nama bagian kustom tempat poin berada.',
                'sumber' => 'bagian_kustom.nama.',
                'aturan' => 'Teks apa adanya.',
                'contoh' => 'Manajemen Risiko',
            ]),
            self::v('bagian_2', 'Tempat Bagian II', 'struktur', 'penyajian', 'blok', $docx, [
                'deskripsi' => 'Penanda tempat Bagian II yang disusun terpisah.',
                'sumber' => 'Tidak diisi.',
                'aturan' => 'Sengaja dibiarkan apa adanya sebagai penanda tempat menempelkan Bagian II.',
                'contoh' => '{{bagian_2}}',
            ]),
            self::v('bagian_3', 'Tempat Bagian III', 'struktur', 'penyajian', 'blok', $docx, [
                'deskripsi' => 'Penanda tempat Bagian III yang disusun terpisah.',
                'sumber' => 'Tidak diisi.',
                'aturan' => 'Sengaja dibiarkan apa adanya sebagai penanda tempat menempelkan Bagian III.',
                'contoh' => '{{bagian_3}}',
            ]),

            /* ---------- Struktur template (blok) ---------- */
            self::v('iku_blok', 'Blok berulang per IKU', 'struktur', 'penyajian', 'blok', $docx, [
                'penutup' => '/iku_blok',
                'deskripsi' => 'Pembungkus satu tabel IKU. Isinya digandakan sekali per Master IKU yang sudah diverifikasi pada triwulan notula (urut sasaran lalu kode).',
                'sumber' => 'Master IKU dengan capaian triwulan berstatus diverifikasi/disetujui.',
                'aturan' => 'Penanda pembuka & penutup masing-masing satu paragraf tersendiri. Placeholder per IKU hanya terisi di dalam blok ini.',
                'contoh' => '{{iku_blok}} … {{/iku_blok}}',
                'catatan' => 'Wajib ada di template; template tanpa pasangan penanda ditolak saat unggah.',
            ]),
            self::v('blok_proksi', 'Blok indikator proksi', 'struktur', 'penyajian', 'blok', $docx, [
                'lingkup' => 'iku_blok',
                'penutup' => '/blok_proksi',
                'deskripsi' => 'Tabel indikator proksi; baris yang memuat {{proksi_uraian}} diulang per proksi.',
                'sumber' => 'indikator_proksi IKU pada tahun & triwulan notula.',
                'aturan' => 'Dibuang seluruhnya bila IKU tidak punya proksi terisi.',
                'contoh' => '{{blok_proksi}} … {{/blok_proksi}}',
            ]),
            self::v('blok_ro', 'Blok tabel rincian output', 'struktur', 'penyajian', 'blok', $docx, [
                'lingkup' => 'iku_blok',
                'penutup' => '/blok_ro',
                'deskripsi' => 'Tabel "Realisasi Volume RO dan Progress Pelaksanaan Kegiatan".',
                'sumber' => 'Rincian output kegiatan IKU yang terverifikasi pada triwulan notula.',
                'aturan' => 'Hanya tampil bila IKU belum punya realisasi triwulan dan ada RO yang sudah direalisasikan; selain itu dibuang.',
                'contoh' => '{{blok_ro}} … {{/blok_ro}}',
            ]),
            self::v('ro_row', 'Baris berulang rincian output', 'struktur', 'penyajian', 'blok', $docx, [
                'lingkup' => 'iku_blok',
                'penutup' => '/ro_row',
                'deskripsi' => 'Baris tabel RO yang digandakan sekali per RO.',
                'sumber' => 'rincian_output yang punya volume/progres.',
                'aturan' => 'Penanda berupa baris tabel tersendiri di dalam {{blok_ro}}.',
                'contoh' => '{{ro_row}} … {{/ro_row}}',
            ]),
            self::v('blok_formula', 'Blok rumus capaian', 'struktur', 'penyajian', 'blok', $docx, [
                'lingkup' => 'iku_blok',
                'penutup' => '/blok_formula',
                'deskripsi' => 'Paragraf rumus {{formula_capaian}} (rata tengah).',
                'sumber' => 'Lihat formula_capaian.',
                'aturan' => 'Dibuang seluruhnya bila rumus tidak tersedia.',
                'contoh' => '{{blok_formula}}{{formula_capaian}}{{/blok_formula}}',
            ]),
            self::v('blok_berakhlak', 'Blok BerAKHLAK (lama)', 'struktur', 'penyajian', 'blok', $docx, [
                'lingkup' => 'iku_blok',
                'penutup' => '/blok_berakhlak',
                'status' => 'usang', 'pengganti' => 'blok_proksi',
                'deskripsi' => 'Pertanyaan baku khusus IKU berteks "berakhlak".',
                'sumber' => 'Deteksi teks indikator IKU.',
                'aturan' => 'Masih diproses bila ada di template, tetapi tidak diperlukan lagi: indikator proksi berlaku untuk semua IKU.',
                'contoh' => '{{blok_berakhlak}} … {{/blok_berakhlak}}',
            ]),
            self::v('blok_sakip', 'Blok proksi SAKIP (lama)', 'struktur', 'penyajian', 'blok', $docx, [
                'lingkup' => 'iku_blok',
                'penutup' => '/blok_sakip',
                'status' => 'usang', 'pengganti' => 'blok_proksi', 'diproses' => false,
                'deskripsi' => 'Tabel proksi khusus SAKIP pada template lama.',
                'sumber' => 'Tidak lagi diisi.',
                'aturan' => 'Selalu dibuang saat notula disusun; gunakan {{blok_proksi}}.',
                'contoh' => '{{blok_sakip}} … {{/blok_sakip}}',
            ]),
        ];

        $hasil = [];

        foreach ($daftar as $v) {
            $hasil[$v['kode']] = $v;
        }

        return $hasil;
    }

    /**
     * @param  list<string>  $konteks
     * @param  array<string, mixed>  $info
     * @return array<string, mixed>
     */
    private static function v(string $kode, string $nama, string $kategori, string $jenis, string $tipe, array $konteks, array $info): array
    {
        return [
            'id' => 'sistem:'.$kode,
            'kode' => $kode,
            'nama' => $nama,
            'kategori' => $kategori,
            'jenis' => $jenis,
            'tipe' => $tipe,
            'konteks' => $konteks,
            'lingkup' => $info['lingkup'] ?? (in_array(self::KONTEKS_NOTULA_DOCX, $konteks, true) ? 'global' : null),
            'deskripsi' => $info['deskripsi'],
            'sumber' => $info['sumber'],
            'aturan' => $info['aturan'],
            'contoh' => $info['contoh'],
            'kosong' => $info['kosong'] ?? null,
            'catatan' => $info['catatan'] ?? null,
            'status' => $info['status'] ?? 'aktif',
            'pengganti' => $info['pengganti'] ?? null,
            // Diisi/diproses resolver saat ini? Variabel usang yang tidak lagi diisi = false.
            'diproses' => $info['diproses'] ?? true,
            'penutup' => $info['penutup'] ?? null,
            'bagian' => null,
            'pemakaian' => [],
        ];
    }
}
