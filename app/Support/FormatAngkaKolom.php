<?php

namespace App\Support;

/**
 * Satu aturan untuk kolom angka Bagian Kustom: cara membaca input, cara menyimpan, dan
 * cara menampilkan — dipakai formulir isian, Verifikasi, pratinjau, dan notula supaya
 * angka yang sama selalu tampil sama.
 *
 * Format hanya mengatur TAMPILAN. Nilai tersimpan tidak pernah dibulatkan atau diubah
 * karena jumlah desimal/pemisah diganti.
 *
 * Kolom angka yang belum diatur formatnya (desimal = null) berperilaku persis seperti
 * sebelum fitur ini: input disimpan apa adanya dan ditampilkan apa adanya.
 */
final class FormatAngkaKolom
{
    public const PEMISAH_KOMA = 'koma';

    public const PEMISAH_TITIK = 'titik';

    /** Pilihan pemisah desimal, dengan contoh tampilannya. */
    public const PEMISAH = [
        self::PEMISAH_KOMA => 'Koma desimal (1.234,56)',
        self::PEMISAH_TITIK => 'Titik desimal (1,234.56)',
    ];

    public const DESIMAL_MAKS = 6;

    /**
     * Kolom angka yang formatnya diatur Tim SAKIP (jumlah desimal dipilih).
     *
     * @param  array<string, mixed>  $kolom
     */
    public static function diatur(array $kolom): bool
    {
        return ($kolom['tipe'] ?? '') === 'angka'
            && isset($kolom['desimal'])
            && $kolom['desimal'] !== ''
            && $kolom['desimal'] !== null;
    }

    /**
     * Baca input menjadi angka kanonik ("-1234.5": titik desimal, tanpa pemisah ribuan),
     * atau null bila bukan angka yang sah.
     *
     * $pemisah null = aturan lama: satu koma ATAU titik sebagai desimal, tanpa ribuan.
     * Aturan lama juga dipakai untuk membaca nilai TERSIMPAN (kanonik maupun gaya lama),
     * karena nilai lama memang divalidasi dengan aturan itu.
     */
    public static function urai(string $input, ?string $pemisah): ?string
    {
        $s = str_replace([' ', "\u{00A0}"], '', trim($input));

        if ($s === '') {
            return null;
        }

        if ($pemisah === null) {
            $s = str_replace(',', '.', $s);

            return preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $s) ? self::kanonik($s) : null;
        }

        [$desimal, $ribuan] = $pemisah === self::PEMISAH_TITIK ? ['.', ','] : [',', '.'];
        $d = preg_quote($desimal, '/');
        $r = preg_quote($ribuan, '/');

        // Bagian bulat: tanpa pemisah ("1234") atau dikelompokkan tiga digit ("1.234").
        if (! preg_match('/^([+-]?)(\d{1,3}(?:'.$r.'\d{3})+|\d+)(?:'.$d.'(\d+))?$/', $s, $m)) {
            return null;
        }

        $bulat = str_replace($ribuan, '', $m[2]);
        $pecahan = $m[3] ?? '';

        return self::kanonik($m[1].$bulat.($pecahan !== '' ? '.'.$pecahan : ''));
    }

    /**
     * Pesan galat bila input kolom angka tidak sah, null bila sah.
     *
     * @param  array<string, mixed>  $kolom
     */
    public static function pesanGalat(string $input, array $kolom): ?string
    {
        if (self::urai($input, self::pemisahInput($kolom)) !== null) {
            return null;
        }

        $label = (string) ($kolom['label'] ?? 'Kolom');

        if (! self::diatur($kolom)) {
            return "\"{$label}\" harus berupa angka.";
        }

        return self::pemisahDari($kolom) === self::PEMISAH_TITIK
            ? "\"{$label}\" harus berupa angka dengan titik sebagai pemisah desimal, mis. 1234.5 atau 1,234.5."
            : "\"{$label}\" harus berupa angka dengan koma sebagai pemisah desimal, mis. 1234,5 atau 1.234,5.";
    }

    /**
     * Nilai yang disimpan ke database dari input pengguna yang sudah lolos validasi.
     * Kolom berformat disimpan kanonik; kolom tanpa format disimpan apa adanya (lama).
     *
     * @param  array<string, mixed>  $kolom
     */
    public static function untukDisimpan(string $input, array $kolom): string
    {
        $input = trim($input);

        if (! self::diatur($kolom)) {
            return $input;
        }

        return self::urai($input, self::pemisahDari($kolom)) ?? $input;
    }

    /**
     * Nilai tersimpan dalam bentuk yang diketik ulang di formulir (pemisah desimal sesuai
     * pengaturan, tanpa pembulatan, tanpa pemisah ribuan).
     *
     * @param  array<string, mixed>  $kolom
     */
    public static function untukInput(?string $tersimpan, array $kolom): string
    {
        $nilai = trim((string) $tersimpan);

        if ($nilai === '' || ! self::diatur($kolom)) {
            return $nilai;
        }

        $kanonik = self::urai($nilai, null);

        if ($kanonik === null) {
            return $nilai;
        }

        return self::pemisahDari($kolom) === self::PEMISAH_TITIK ? $kanonik : str_replace('.', ',', $kanonik);
    }

    /**
     * Teks tampilan satu nilai kolom (formulir riwayat, Verifikasi, notula, pratinjau).
     * Kosong tetap kosong. Satuan ditambahkan di belakang angka bila diisi.
     *
     * @param  array<string, mixed>  $kolom
     */
    public static function tampil(?string $tersimpan, array $kolom): string
    {
        $nilai = trim((string) $tersimpan);

        if ($nilai === '' || ($kolom['tipe'] ?? '') !== 'angka') {
            return $nilai;
        }

        $teks = $nilai;

        if (self::diatur($kolom) && ($kanonik = self::urai($nilai, null)) !== null) {
            $desimal = max(0, min(self::DESIMAL_MAKS, (int) $kolom['desimal']));
            [$pDesimal, $pRibuan] = self::pemisahDari($kolom) === self::PEMISAH_TITIK ? ['.', ','] : [',', '.'];

            // Dibulatkan sebagai teks desimal (bukan float) supaya angka besar dan
            // pecahan seperti 1,005 tampil persis; aturan pembulatan sama dengan
            // round(): setengah menjauhi nol. Nilai tersimpan tidak berubah.
            [$negatif, $bulat, $pecahan] = self::bulatkan($kanonik, $desimal);

            if (($kolom['ribuan'] ?? true) && strlen($bulat) > 3) {
                $bulat = strrev(implode($pRibuan, str_split(strrev($bulat), 3)));
            }

            $teks = ($negatif ? '-' : '').$bulat.($desimal > 0 ? $pDesimal.$pecahan : '');
        }

        $satuan = trim((string) ($kolom['satuan'] ?? ''));

        if ($satuan === '') {
            return $teks;
        }

        return $satuan === '%' ? $teks.'%' : $teks.' '.$satuan;
    }

    /**
     * @param  array<string, mixed>  $kolom
     */
    public static function pemisahDari(array $kolom): string
    {
        return ($kolom['pemisah'] ?? null) === self::PEMISAH_TITIK ? self::PEMISAH_TITIK : self::PEMISAH_KOMA;
    }

    /**
     * Aturan baca input: pemisah yang diatur, atau aturan lama untuk kolom tanpa format.
     *
     * @param  array<string, mixed>  $kolom
     */
    private static function pemisahInput(array $kolom): ?string
    {
        return self::diatur($kolom) ? self::pemisahDari($kolom) : null;
    }

    /**
     * Bulatkan angka kanonik ("-1234.5") ke $desimal angka di belakang koma, tanpa float.
     * Hasil nol tidak pernah bertanda negatif.
     *
     * @return array{0: bool, 1: string, 2: string} [negatif, bagian bulat, pecahan (tepat $desimal digit)]
     */
    private static function bulatkan(string $kanonik, int $desimal): array
    {
        $negatif = str_starts_with($kanonik, '-');
        [$bulat, $pecahan] = array_pad(explode('.', ltrim($kanonik, '+-'), 2), 2, '');
        $pecahan = str_pad($pecahan, $desimal + 1, '0');

        $digit = $bulat.substr($pecahan, 0, $desimal);

        if ($pecahan[$desimal] >= '5') {
            for ($i = strlen($digit) - 1; $i >= 0; $i--) {
                if ($digit[$i] !== '9') {
                    $digit[$i] = (string) ((int) $digit[$i] + 1);

                    break;
                }

                $digit[$i] = '0';
            }

            if ($i < 0) {
                $digit = '1'.$digit;
            }
        }

        $bulat = ltrim(substr($digit, 0, strlen($digit) - $desimal), '0') ?: '0';
        $pecahan = $desimal > 0 ? substr($digit, -$desimal) : '';

        if (trim($bulat.$pecahan, '0') === '') {
            $negatif = false;
        }

        return [$negatif, $bulat, $pecahan];
    }

    private static function kanonik(string $s): string
    {
        $negatif = str_starts_with($s, '-');
        $s = ltrim($s, '+-');

        [$bulat, $pecahan] = array_pad(explode('.', $s, 2), 2, '');
        $bulat = ltrim($bulat, '0') ?: '0';
        $pecahan = rtrim($pecahan, '0');

        $hasil = $pecahan !== '' ? $bulat.'.'.$pecahan : $bulat;

        return $negatif && $hasil !== '0' ? '-'.$hasil : $hasil;
    }
}
