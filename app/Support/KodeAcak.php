<?php

namespace App\Support;

/**
 * Potongan acak untuk kode publik di URL (Berkas::kode, Capaian::kode) sebagai
 * pengganti id urut yang gampang ditebak. Tanpa karakter yang mudah tertukar
 * saat dibaca/diketik ulang (0/O, 1/I/L, U/V).
 */
class KodeAcak
{
    public const ALFABET = '23456789ABCDEFGHJKMNPQRSTWXYZ';

    public static function buat(int $panjang): string
    {
        $hasil = '';
        for ($i = 0; $i < $panjang; $i++) {
            $hasil .= self::ALFABET[random_int(0, strlen(self::ALFABET) - 1)];
        }

        return $hasil;
    }
}
