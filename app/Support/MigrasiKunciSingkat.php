<?php

namespace App\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Menjalankan langkah migrasi skema di PostgreSQL produksi tanpa menggantung.
 *
 * ALTER/CREATE dengan kunci asing butuh kunci tabel. Bila ada transaksi lain yang
 * sedang memegang tabel itu (mis. permintaan dari instance lama saat deploy),
 * PostgreSQL menunggu sampai statement_timeout (2 menit di Supabase) dan selama
 * menunggu semua query lain ke tabel itu ikut antre. Di sini setiap percobaan
 * hanya menunggu kunci sebentar (lock_timeout), lalu seluruh langkah di-rollback
 * dan dicoba lagi. Satu percobaan = satu transaksi, jadi tidak pernah setengah jadi.
 *
 * Migrasi pemakai harus memasang `public $withinTransaction = false;`.
 */
class MigrasiKunciSingkat
{
    /** SQLSTATE lock_not_available (lock_timeout terlampaui). */
    private const KUNCI_TIDAK_TERSEDIA = '55P03';

    public static function jalankan(Closure $langkah, int $percobaan = 12, string $tungguKunci = '5s'): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            DB::transaction($langkah);

            return;
        }

        for ($ke = 1; ; $ke++) {
            try {
                DB::transaction(function () use ($langkah, $tungguKunci) {
                    DB::statement("SET LOCAL lock_timeout = '{$tungguKunci}'");
                    $langkah();
                });

                return;
            } catch (QueryException $e) {
                if ($e->getCode() !== self::KUNCI_TIDAK_TERSEDIA || $ke >= $percobaan) {
                    throw $e;
                }

                fwrite(STDERR, "Tabel sedang dipakai, percobaan migrasi {$ke}/{$percobaan} diulang.\n");
                sleep(min(2 * $ke, 10));
            }
        }
    }
}
