<?php

namespace App\Support;

use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use Illuminate\Support\Str;

/**
 * Aturan template notula & variabel Bagian Kustom — satu sumber untuk notula (DOCX),
 * pratinjau di Data Master, dan keterangan variabel di layar, supaya ketiganya
 * selalu sama.
 */
final class TemplateBagianKustom
{
    public const FORMAT_STANDAR = '{kode_iku}: {teks}';

    public const FORMAT_LENGKAP_SUFIKS = ' ({kolom})';

    /**
     * Variabel bawaan yang selalu tersedia; nama kolom buatan pengguna tidak boleh memakainya.
     * Dokumentasi lengkapnya (sumber, aturan, kategori) ada di RegistriVariabel — keduanya
     * dijaga tetap sama oleh tes.
     */
    public const VARIABEL_BAWAAN = [
        'kode_iku' => ['keterangan' => 'Kode IKU tempat poin diisi', 'contoh' => '1131'],
        'indikator_iku' => ['keterangan' => 'Teks indikator IKU lengkap', 'contoh' => 'Persentase rekomendasi yang ditindaklanjuti'],
        'sasaran_iku' => ['keterangan' => 'Sasaran strategis IKU tempat poin diisi', 'contoh' => 'Meningkatnya kualitas tata kelola'],
        'satuan_iku' => ['keterangan' => 'Satuan IKU (Persen/Poin)', 'contoh' => 'Persen'],
        'teks' => ['keterangan' => 'Isi uraian poin (kolom "Uraian" / label uraian)', 'contoh' => 'Contoh uraian poin bagian kustom'],
        'nama_bagian' => ['keterangan' => 'Nama bagian kustom', 'contoh' => 'Manajemen Risiko'],
        'triwulan' => ['keterangan' => 'Triwulan poin (angka romawi I–IV)', 'contoh' => 'I'],
        'tahun' => ['keterangan' => 'Tahun anggaran poin', 'contoh' => '2026'],
        'bulan' => ['keterangan' => 'Nama bulan poin diisi', 'contoh' => 'Maret'],
    ];

    private const NAMA_BULAN = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    /**
     * Ganti setiap {variabel} dengan nilainya. Variabel yang tidak dikenal dibiarkan apa
     * adanya (terlihat di notula sebagai tanda untuk diperbaiki) dan dicegah di layar.
     *
     * @param  array<string, string|int|float|null>  $nilai
     */
    public static function render(string $format, array $nilai): string
    {
        $hasil = preg_replace_callback('/\{([a-z0-9_]+)\}/', function (array $m) use ($nilai) {
            return array_key_exists($m[1], $nilai) ? (string) $nilai[$m[1]] : $m[0];
        }, $format);

        return trim(str_replace(' ()', '', (string) $hasil));
    }

    /**
     * Konteks penggantian variabel untuk satu poin: variabel bawaan + nilai tiap kolom.
     *
     * @param  array<string, string|null>  $nilaiKolom  kunci kolom => nilai (sudah dibersihkan)
     * @return array<string, string>
     */
    public static function konteks(?string $kodeIku, ?string $indikatorIku, ?string $teks, string $namaBagian, ?int $tahun, ?int $triwulan, ?int $bulan, array $nilaiKolom = [], ?string $sasaranIku = null, ?string $satuanIku = null): array
    {
        $konteks = [
            'kode_iku' => (string) $kodeIku,
            'indikator_iku' => (string) $indikatorIku,
            'sasaran_iku' => (string) $sasaranIku,
            'satuan_iku' => (string) $satuanIku,
            'teks' => (string) $teks,
            'nama_bagian' => $namaBagian,
            'triwulan' => $triwulan ? (['I', 'II', 'III', 'IV'][$triwulan - 1] ?? (string) $triwulan) : '',
            'tahun' => $tahun ? (string) $tahun : '',
            'bulan' => $bulan ? (self::NAMA_BULAN[$bulan] ?? (string) $bulan) : '',
        ];

        // Kolom menang atas variabel bawaan bernama sama (kolom lama yang kebetulan memakai
        // nama variabel bawaan baru tetap mencetak isiannya sendiri).
        foreach ($nilaiKolom as $kunci => $nilai) {
            $konteks[$kunci] = (string) $nilai;
        }

        return $konteks;
    }

    /**
     * Resolver satu poin: nilai semua variabel format notula untuk poin tersimpan.
     * Relasi masterIku & periode dipakai bila sudah dimuat (notula memuatnya sekaligus
     * untuk semua poin — tanpa query per poin).
     *
     * @return array<string, string>
     */
    public static function konteksPoin(BagianKustomPoin $poin, BagianKustom $bagian): array
    {
        $iku = $poin->masterIku;
        $periode = $poin->periode;

        return self::konteks(
            $iku?->kode,
            $iku?->indikator,
            $poin->teks,
            $bagian->nama,
            $periode?->tahun,
            $periode?->triwulan,
            $periode?->bulan,
            // Format angka (desimal, pemisah, satuan) diatur per kolom; kolom tanpa
            // pengaturan format tercetak apa adanya.
            $bagian->nilaiKolomTampil($poin->nilai_kolom),
            $iku?->sasaran,
            $iku?->satuan,
        );
    }

    /**
     * Daftar variabel untuk keterangan di layar: bawaan + satu variabel per kolom.
     *
     * @param  array<int, array{kunci: string, label: string, tipe: string, opsi: array<int, string>}>  $kolom
     * @return array<int, array{kunci: string, keterangan: string, contoh: string}>
     */
    public static function semuaVariabel(array $kolom): array
    {
        $daftar = [];

        foreach (self::VARIABEL_BAWAAN as $kunci => $info) {
            $registri = RegistriVariabel::cari($kunci);
            $daftar[] = [
                'kunci' => $kunci, 'keterangan' => $info['keterangan'], 'contoh' => $info['contoh'], 'kelompok' => 'sistem',
                'kategori' => RegistriVariabel::KATEGORI[$registri['kategori'] ?? ''] ?? 'Sistem',
                'sumber' => $registri['sumber'] ?? null,
            ];
        }

        foreach ($kolom as $k) {
            // Teks informasi tidak menyimpan nilai — tidak ditawarkan sebagai variabel.
            if (($k['tipe'] ?? '') === 'informasi') {
                continue;
            }

            $daftar[] = [
                'kelompok' => 'kolom',
                'kategori' => 'Kolom bagian ini',
                'sumber' => 'Isian kolom (bagian_kustom_poin.nilai_kolom)',
                'kunci' => $k['kunci'],
                'keterangan' => 'Isian kolom "'.$k['label'].'" ('.self::labelTipe($k['tipe']).')'.(($k['aktif'] ?? true) ? '' : ' — nonaktif, hanya berisi data lama'),
                'contoh' => self::contohKolom($k),
            ];
        }

        return $daftar;
    }

    /**
     * Nilai contoh untuk pratinjau, sesuai tipe kolomnya.
     *
     * @param  array{kunci: string, label: string, tipe: string, opsi: array<int, string>}  $kolom
     */
    public static function contohKolom(array $kolom): string
    {
        return match ($kolom['tipe']) {
            // Angka contoh mengikuti format kolom (desimal, pemisah, satuan) — sama
            // dengan yang tercetak di notula sungguhan.
            'angka' => FormatAngkaKolom::tampil(FormatAngkaKolom::diatur($kolom) ? '1234.5' : '12', $kolom),
            'persentase', 'hitung' => FormatAngkaKolom::tampil(FormatAngkaKolom::diatur(JenisKolomBagianKustom::sebagaiAngka($kolom)) ? '87.5' : '87,5', JenisKolomBagianKustom::sebagaiAngka($kolom)),
            'tautan' => 'https://contoh.go.id/dokumen',
            'tabel' => JenisKolomBagianKustom::tampilTabel((string) json_encode([
                collect($kolom['tabel_kolom'] ?? [])->mapWithKeys(fn ($s) => [$s['kunci'] => $s['tipe'] === 'angka' ? '1' : ($s['tipe'] === 'tanggal' ? '2026-03-31' : ($s['opsi'][0] ?? 'Contoh'))])->all(),
            ]), $kolom) ?: '1) Contoh baris tabel',
            'pilihan' => $kolom['opsi'][0] ?? 'Pilihan 1',
            'pilihan_ganda' => implode(', ', array_slice($kolom['opsi'] ?? [], 0, 2)) ?: 'Pilihan 1, Pilihan 2',
            'tanggal' => JenisKolomBagianKustom::tampilTanggal('2026-03-31', (string) ($kolom['format_tanggal'] ?? 'panjang')),
            'mata_uang' => JenisKolomBagianKustom::tampilUang('1250000', $kolom),
            'waktu' => '14.30',
            'tanggal_waktu' => JenisKolomBagianKustom::tampilTanggalWaktu('2026-03-31T14:30', (string) ($kolom['format_tanggal'] ?? 'panjang')),
            'ya_tidak' => 'Ya',
            'email' => 'nama@bps.go.id',
            'kode' => ($kolom['pola'] ?? 'bebas') === 'angka' ? '0012' : 'R-01',
            'referensi' => match ($kolom['sumber'] ?? 'iku') {
                'pengguna' => 'Nama Pengguna',
                'tim' => 'Nama Tim',
                default => 'IKU 1.1 — Indikator',
            },
            'informasi' => '',
            default => 'Contoh '.Str::lower($kolom['label']),
        };
    }

    public static function labelTipe(string $tipe): string
    {
        return match ($tipe) {
            'angka' => 'angka',
            'persentase' => 'persentase',
            'hitung' => 'perhitungan otomatis',
            'tautan' => 'tautan',
            'tabel' => 'tabel',
            'pilihan' => 'pilihan',
            'pilihan_ganda' => 'pilihan ganda',
            'tanggal' => 'tanggal',
            'teks_panjang' => 'teks panjang',
            'mata_uang' => 'mata uang',
            'waktu' => 'waktu',
            'tanggal_waktu' => 'tanggal & waktu',
            'ya_tidak' => 'ya/tidak',
            'email' => 'email',
            'kode' => 'nomor/kode',
            'referensi' => 'data SIPINTER',
            'informasi' => 'teks informasi',
            default => 'teks',
        };
    }

    /**
     * Variabel yang dipakai di format tetapi tidak ada di daftar yang dikenal.
     *
     * @param  array<int, string>  $kunciDikenal
     * @return array<int, string>
     */
    public static function variabelTidakDikenal(string $format, array $kunciDikenal): array
    {
        preg_match_all('/\{([a-z0-9_]+)\}/', $format, $m);

        $tidakDikenal = array_values(array_unique(array_diff($m[1], array_merge(array_keys(self::VARIABEL_BAWAAN), $kunciDikenal))));

        return array_map(fn (string $k) => '{'.$k.'}', $tidakDikenal);
    }

    /** Kunci yang tidak boleh dipakai sebagai nama kolom (bentrok dengan variabel bawaan). */
    public static function kunciTerlarang(): array
    {
        return array_keys(self::VARIABEL_BAWAAN);
    }

    /**
     * Bentuk kunci dari label kolom (huruf kecil, _ sebagai pemisah) yang unik terhadap
     * kunci yang sudah dipakai dan variabel bawaan.
     *
     * @param  array<int, string>  $sudahDipakai
     */
    public static function kunciBaru(string $label, array $sudahDipakai): string
    {
        $dasar = Str::of($label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
        $dasar = $dasar !== '' ? $dasar : 'kolom';

        $terpakai = array_merge($sudahDipakai, self::kunciTerlarang());
        $kunci = $dasar;
        $n = 2;

        while (in_array($kunci, $terpakai, true)) {
            $kunci = $dasar.'_'.$n++;
        }

        return $kunci;
    }
}
