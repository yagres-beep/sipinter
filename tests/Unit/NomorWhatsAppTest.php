<?php

namespace Tests\Unit;

use App\Support\NomorWhatsApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NomorWhatsAppTest extends TestCase
{
    public static function nomorValid(): array
    {
        return [
            'lokal 08' => ['081234567890', '6281234567890'],
            'bertanda hubung' => ['0812-3456-7890', '6281234567890'],
            'plus dan spasi' => ['+62 812 3456 7890', '6281234567890'],
            'tanpa 0' => ['81234567890', '6281234567890'],
            'sudah 62' => ['6281234567890', '6281234567890'],
            'pendek 10 digit' => ['0812345678', '62812345678'],
            'luar negeri' => ['+6591234567', '6591234567'],
        ];
    }

    #[DataProvider('nomorValid')]
    public function test_normalisasi_nomor_valid(string $masukan, string $harapan): void
    {
        $this->assertSame($harapan, NomorWhatsApp::normalisasi($masukan));
    }

    public static function nomorTidakValid(): array
    {
        return [
            'kosong' => [''],
            'spasi' => ['   '],
            'huruf' => ['bukan nomor'],
            'terlalu pendek' => ['0812345'],
            'telepon rumah' => ['0215551234'],
            'terlalu panjang' => ['08123456789012345'],
        ];
    }

    #[DataProvider('nomorTidakValid')]
    public function test_normalisasi_nomor_tidak_valid(string $masukan): void
    {
        $this->assertNull(NomorWhatsApp::normalisasi($masukan));
    }

    public function test_null_aman(): void
    {
        $this->assertNull(NomorWhatsApp::normalisasi(null));
    }

    public function test_samarkan(): void
    {
        $this->assertSame('6281****890', NomorWhatsApp::samarkan('6281234567890'));
        $this->assertSame('***', NomorWhatsApp::samarkan('123'));
    }
}
