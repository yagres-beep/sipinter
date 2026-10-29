<?php

namespace App\Support;

/**
 * Rumus kolom "Perhitungan otomatis" Bagian Kustom, mis. "{realisasi} / {target} * 100".
 *
 * Yang dikenali hanya: angka (titik atau koma desimal), variabel {kunci_kolom}, operator
 * + - * /, tanda kurung, dan minus di depan angka/variabel. Tidak ada eval: rumus diurai
 * ke notasi postfix lalu dihitung dengan bcmath (tanpa galat pembulatan float).
 *
 * Hasil tidak bisa dihitung (variabel kosong / bukan angka, pembagian dengan nol) = null,
 * dan disimpan sebagai isian kosong.
 */
final class RumusBagianKustom
{
    public const PANJANG_MAKS = 300;

    /** Skala pembagian internal; hasil akhir dipangkas ke HASIL_DESIMAL digit. */
    private const SKALA = 12;

    public const HASIL_DESIMAL = 6;

    private const PRIORITAS = ['+' => 1, '-' => 1, '*' => 2, '/' => 2, 'neg' => 3];

    /**
     * Kunci variabel yang dipakai rumus, urut kemunculan pertama.
     *
     * @return array<int, string>
     */
    public static function variabel(string $rumus): array
    {
        preg_match_all('/\{([a-z0-9_]+)\}/', $rumus, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Pesan galat rumus, null bila sah. $kunciAngka = kunci kolom angka/persentase yang
     * boleh dipakai sebagai variabel.
     *
     * @param  array<int, string>  $kunciAngka
     */
    public static function galat(string $rumus, array $kunciAngka): ?string
    {
        $rumus = trim($rumus);

        if ($rumus === '') {
            return 'Rumus wajib diisi untuk kolom perhitungan otomatis.';
        }

        if (mb_strlen($rumus) > self::PANJANG_MAKS) {
            return 'Rumus terlalu panjang (maksimal '.self::PANJANG_MAKS.' karakter).';
        }

        $token = self::token($rumus);

        if ($token === null) {
            return 'Rumus hanya boleh berisi angka, variabel {kode_kolom}, + - * /, dan tanda kurung.';
        }

        $tidakDikenal = array_values(array_diff(self::variabel($rumus), $kunciAngka));

        if ($tidakDikenal !== []) {
            return 'Variabel rumus harus kolom Angka atau Persentase di bagian ini: '.implode(', ', array_map(fn ($k) => '{'.$k.'}', $tidakDikenal)).' tidak dikenal.';
        }

        if (self::variabel($rumus) === []) {
            return 'Rumus harus memakai minimal satu variabel kolom, mis. {realisasi} / {target} * 100.';
        }

        return self::postfix($token) === null ? 'Susunan rumus tidak sah (periksa operator dan tanda kurung).' : null;
    }

    /**
     * Hitung rumus dengan nilai variabel kanonik ("12.5"). Null bila tidak bisa dihitung.
     *
     * @param  array<string, string|null>  $nilai
     */
    public static function hitung(string $rumus, array $nilai): ?string
    {
        $token = self::token(trim($rumus));
        $postfix = $token !== null ? self::postfix($token) : null;

        if ($postfix === null) {
            return null;
        }

        $tumpukan = [];

        foreach ($postfix as [$jenis, $isi]) {
            if ($jenis === 'angka') {
                $tumpukan[] = $isi;

                continue;
            }

            if ($jenis === 'var') {
                $v = $nilai[$isi] ?? null;

                if ($v === null || $v === '') {
                    return null;
                }

                $tumpukan[] = $v;

                continue;
            }

            if ($isi === 'neg') {
                $tumpukan[] = bcmul((string) array_pop($tumpukan), '-1', self::SKALA);

                continue;
            }

            $b = (string) array_pop($tumpukan);
            $a = (string) array_pop($tumpukan);

            if ($isi === '/' && bccomp($b, '0', self::SKALA) === 0) {
                return null;
            }

            $tumpukan[] = match ($isi) {
                '+' => bcadd($a, $b, self::SKALA),
                '-' => bcsub($a, $b, self::SKALA),
                '*' => bcmul($a, $b, self::SKALA),
                '/' => bcdiv($a, $b, self::SKALA),
            };
        }

        return count($tumpukan) === 1 ? self::rapikan((string) $tumpukan[0]) : null;
    }

    /** Pangkas ke HASIL_DESIMAL digit (dibulatkan setengah menjauhi nol) lalu kanonik. */
    private static function rapikan(string $angka): string
    {
        $geser = '0.'.str_repeat('0', self::HASIL_DESIMAL).'5';
        $dibulat = str_starts_with($angka, '-') ? bcsub($angka, $geser, self::HASIL_DESIMAL) : bcadd($angka, $geser, self::HASIL_DESIMAL);

        return FormatAngkaKolom::urai($dibulat, null) ?? $dibulat;
    }

    /**
     * Pecah rumus menjadi token [jenis, isi]: angka (kanonik), var, op, buka, tutup.
     * Minus di awal / setelah operator / setelah kurung buka = minus tunggal (neg).
     *
     * @return array<int, array{0: string, 1: string}>|null
     */
    private static function token(string $rumus): ?array
    {
        if (! preg_match_all('/\s*(?:(\d+(?:[.,]\d+)?)|\{([a-z0-9_]+)\}|([-+*\/()]))\s*/A', $rumus, $m, PREG_SET_ORDER)) {
            return null;
        }

        if (implode('', array_column($m, 0)) !== $rumus) {
            return null;
        }

        $hasil = [];

        foreach ($m as $bagian) {
            $sebelum = end($hasil) ?: null;
            $posisiTunggal = $sebelum === null || $sebelum[0] === 'op' || $sebelum[0] === 'buka';

            if (($bagian[1] ?? '') !== '') {
                $hasil[] = ['angka', FormatAngkaKolom::urai(str_replace(',', '.', $bagian[1]), null) ?? '0'];
            } elseif (($bagian[2] ?? '') !== '') {
                $hasil[] = ['var', $bagian[2]];
            } elseif ($bagian[3] === '(') {
                $hasil[] = ['buka', '('];
            } elseif ($bagian[3] === ')') {
                $hasil[] = ['tutup', ')'];
            } elseif ($bagian[3] === '-' && $posisiTunggal) {
                $hasil[] = ['op', 'neg'];
            } else {
                $hasil[] = ['op', $bagian[3]];
            }
        }

        return $hasil;
    }

    /**
     * Shunting-yard → postfix, sekaligus memeriksa susunan (operand/operator bergantian,
     * kurung seimbang). Null bila tidak sah.
     *
     * @param  array<int, array{0: string, 1: string}>  $token
     * @return array<int, array{0: string, 1: string}>|null
     */
    private static function postfix(array $token): ?array
    {
        $keluar = [];
        $op = [];
        $harapOperand = true;

        foreach ($token as $t) {
            [$jenis, $isi] = $t;

            if ($jenis === 'angka' || $jenis === 'var') {
                if (! $harapOperand) {
                    return null;
                }

                $keluar[] = $t;
                $harapOperand = false;
            } elseif ($jenis === 'buka') {
                if (! $harapOperand) {
                    return null;
                }

                $op[] = $t;
            } elseif ($jenis === 'tutup') {
                if ($harapOperand) {
                    return null;
                }

                while ($op !== [] && end($op)[0] !== 'buka') {
                    $keluar[] = array_pop($op);
                }

                if ($op === []) {
                    return null;
                }

                array_pop($op);
            } elseif ($isi === 'neg') {
                if (! $harapOperand) {
                    return null;
                }

                $op[] = $t;
            } else {
                if ($harapOperand) {
                    return null;
                }

                while ($op !== [] && end($op)[0] === 'op' && self::PRIORITAS[end($op)[1]] >= self::PRIORITAS[$isi]) {
                    $keluar[] = array_pop($op);
                }

                $op[] = $t;
                $harapOperand = true;
            }
        }

        if ($harapOperand) {
            return null;
        }

        while ($op !== []) {
            $t = array_pop($op);

            if ($t[0] === 'buka') {
                return null;
            }

            $keluar[] = $t;
        }

        return $keluar;
    }
}
