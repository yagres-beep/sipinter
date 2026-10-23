<?php

namespace Tests;

use Illuminate\Support\Facades\DB;

/**
 * Pengaman: test yang memakai ini hanya boleh berjalan di SQLite in-memory atau di
 * klaster PostgreSQL uji lokal (127.0.0.1:55432, database sipinter_uji). Koneksi lain
 * — terutama database produksi dari .env — langsung menggagalkan test.
 */
trait PenjagaDatabaseUji
{
    protected function pastikanDatabaseUji(): void
    {
        $koneksi = DB::connection();
        $driver = $koneksi->getDriverName();

        if ($driver === 'sqlite') {
            $this->assertSame(':memory:', $koneksi->getConfig('database'), 'SQLite uji harus in-memory.');

            return;
        }

        $this->assertSame('pgsql', $driver, 'Driver database uji tidak dikenal.');
        $this->assertSame('127.0.0.1', $koneksi->getConfig('host'), 'PostgreSQL uji harus lokal.');
        $this->assertSame('55432', (string) $koneksi->getConfig('port'), 'PostgreSQL uji harus di port klaster uji.');
        $this->assertSame('sipinter_uji', $koneksi->getConfig('database'), 'PostgreSQL uji harus database sipinter_uji.');
    }
}
