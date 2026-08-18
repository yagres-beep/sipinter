<?php

namespace Tests\Feature;

use App\Models\StorageAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorageAccountQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_sinkron_kuota_menimpa_terpakai_dan_total_dari_bytes_google(): void
    {
        $akun = StorageAccount::create([
            'email_gmail_institusi' => 'sinkron@example.test',
            'kuota_terpakai' => 999,
            'kuota_total' => 1,
            'status' => StorageAccount::STATUS_PENUH,
        ]);

        // 2 GiB terpakai dari total 15 GiB, dilaporkan Google dalam bytes.
        $akun->sinkronKuota([
            'usage_bytes' => 2 * 1024 * 1024 * 1024,
            'limit_bytes' => 15 * 1024 * 1024 * 1024,
        ]);

        $akun->refresh();

        $this->assertEqualsWithDelta(2.0, (float) $akun->kuota_terpakai, 0.001);
        $this->assertEqualsWithDelta(15.0, (float) $akun->kuota_total, 0.001);
    }

    public function test_sinkron_kuota_tidak_menimpa_total_bila_akun_tidak_berbatas(): void
    {
        // Regresi: akun Google Workspace tanpa batas kuota tetap tidak mengirim
        // "limit" sama sekali (limit_bytes null) — kuota_total manual yang sudah
        // diisi Tim SAKIP tidak boleh ikut ditimpa jadi 0/kosong.
        $akun = StorageAccount::create([
            'email_gmail_institusi' => 'unlimited@example.test',
            'kuota_terpakai' => 0,
            'kuota_total' => 15,
            'status' => StorageAccount::STATUS_PENUH,
        ]);

        $akun->sinkronKuota([
            'usage_bytes' => 5 * 1024 * 1024 * 1024,
            'limit_bytes' => null,
        ]);

        $akun->refresh();

        $this->assertEqualsWithDelta(5.0, (float) $akun->kuota_terpakai, 0.001);
        $this->assertEqualsWithDelta(15.0, (float) $akun->kuota_total, 0.001);
    }
}
