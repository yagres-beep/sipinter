<?php

namespace App\Support;

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

    /** Variabel bawaan yang selalu tersedia; nama kolom buatan pengguna tidak boleh memakainya. */
    public const VARIABEL_BAWAAN = [
        'kode_iku' => ['keterangan' => 'Kode IKU tempat poin diisi', 'contoh' => '1131'],
        'indikator_iku' => ['keterangan' => 'Teks indikator IKU lengkap', 'contoh' => 'Persentase rekomendasi yang ditindaklanjuti'],
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
    public static function konteks(?string $kodeIku, ?string $indikatorIku, ?string $teks, string $namaBagian, ?int $tahun, ?int $triwulan, ?int $bulan, array $nilaiKolom = []): array
    {
        $konteks = [
            'kode_iku' => (string) $kodeIku,
            'indikator_iku' => (string) $indikatorIku,
            'teks' => (string) $teks,
            'nama_bagian' => $namaBagian,
            'triwulan' => $triwulan ? (['I', 'II', 'III', 'IV'][$triwulan - 1] ?? (string) $triwulan) : '',
            'tahun' => $tahun ? (string) $tahun : '',
            'bulan' => $bulan ? (self::NAMA_BULAN[$bulan] ?? (string) $bulan) : '',
        ];

        foreach ($nilaiKolom as $kunci => $nilai) {
            $konteks[$kunci] = (string) $nilai;
        }

        return $konteks;
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
            $daftar[] = ['kunci' => $kunci, 'keterangan' => $info['keterangan'], 'contoh' => $info['contoh']];
        }

        foreach ($kolom as $k) {
            $daftar[] = [
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
            'pilihan' => $kolom['opsi'][0] ?? 'Pilihan 1',
            'pilihan_ganda' => implode(', ', array_slice($kolom['opsi'] ?? [], 0, 2)) ?: 'Pilihan 1, Pilihan 2',
            'tanggal' => JenisKolomBagianKustom::tampilTanggal('2026-03-31', (string) ($kolom['format_tanggal'] ?? 'panjang')),
            default => 'Contoh '.Str::lower($kolom['label']),
        };
    }

    public static function labelTipe(string $tipe): string
    {
        return match ($tipe) {
            'angka' => 'angka',
            'pilihan' => 'pilihan',
            'pilihan_ganda' => 'pilihan ganda',
            'tanggal' => 'tanggal',
            'teks_panjang' => 'teks panjang',
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
