<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Role::semuaNama() di-cache PERMANEN (rememberForever) di kode produksi karena
        // daftar role nyaris tak pernah berubah di luar testing — tapi RefreshDatabase
        // mereset ID role (auto-increment) tiap test, sehingga cache lama bisa memetakan
        // ID yang sama ke NAMA role yang SALAH bila test lain sebelumnya sempat memakai
        // nama role berbeda untuk id yang sama. Bersihkan di sini supaya tiap test selalu
        // mulai dari cache kosong, bukan warisan test sebelumnya.
        Cache::forget('roles-nama-by-id');
    }
}
