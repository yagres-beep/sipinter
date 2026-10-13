<?php

namespace App\Support;

/**
 * Normalisasi nomor dari kolom users.nomor_telepon (format bebas, mis.
 * "0812-3456-7890" atau "+62 812 3456 7890") ke format internasional tanpa
 * plus yang diterima gateway, mis. "6281234567890".
 */
class NomorWhatsApp
{
    public static function normalisasi(?string $nomor): ?string
    {
        if ($nomor === null || trim($nomor) === '') {
            return null;
        }

        $digit = preg_replace('/\D/', '', $nomor);

        if (str_starts_with($digit, '0')) {
            $digit = '62'.substr($digit, 1);
        } elseif (str_starts_with($digit, '8')) {
            $digit = '62'.$digit;
        }

        if (str_starts_with($digit, '62')) {
            // Nomor seluler Indonesia: 08xx dengan 10-13 digit.
            return preg_match('/^628\d{8,11}$/', $digit) ? $digit : null;
        }

        return preg_match('/^[1-9]\d{9,14}$/', $digit) ? $digit : null;
    }

    public static function samarkan(?string $nomor): string
    {
        $digit = preg_replace('/\D/', '', (string) $nomor);

        if (strlen($digit) < 7) {
            return '***';
        }

        return substr($digit, 0, 4).'****'.substr($digit, -3);
    }
}
