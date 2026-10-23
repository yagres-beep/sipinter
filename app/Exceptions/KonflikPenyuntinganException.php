<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Dilempar DI DALAM transaksi penyimpanan formulir bila ada baris yang sudah diubah
 * atau dihapus pengguna lain sejak formulir dimuat (optimistic locking). Karena
 * dilempar dari dalam DB::transaction(), seluruh penyimpanan formulir ikut batal —
 * tidak ada penyimpanan sebagian.
 */
class KonflikPenyuntinganException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $bentrok  jenis bagian => label baris yang bentrok
     */
    public function __construct(public readonly array $bentrok)
    {
        parent::__construct('Data sudah diubah pengguna lain sejak formulir dimuat.');
    }
}
