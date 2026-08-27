<?php

namespace Tests;

use App\Services\LibreOfficeConversionService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use ZipArchive;

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

    /**
     * NotulaService::susunBagianSatu() sejak arsitektur "generate dari template resmi"
     * mengisi Bagian I lewat NotulaBagian1DocxService lalu mengonversinya ke HTML lewat
     * LibreOffice headless (lihat NotulaService) -- LibreOffice adalah aplikasi desktop
     * terpisah, TIDAK selalu terpasang di komputer/CI yang menjalankan test. Panggil ini
     * di test manapun yang memicu susunBagianSatu() (langsung, atau tidak langsung lewat
     * KompilasiNotula::muatBagian1EditText() saat notula baru pertama kali dibuka) supaya
     * convertToHtml() dipalsukan MENGEMBALIKAN XML MENTAH word/document.xml dari docx
     * yang sudah diisi NotulaBagian1DocxService, bukan memanggil soffice.exe sungguhan.
     *
     * Ini cukup untuk menguji bahwa DATA yang benar mengalir sampai ke docx (assertion
     * teks lewat assertStringContainsString tetap berarti) tanpa bergantung pada
     * LibreOffice terpasang -- kesetiaan hasil konversi HTML-nya sendiri BUKAN tanggung
     * jawab kode aplikasi (lihat catatan fidelitas di LibreOfficeConversionService).
     */
    protected function fakeKonversiBagian1KeXmlMentah(): void
    {
        $this->instance(
            LibreOfficeConversionService::class,
            Mockery::mock(LibreOfficeConversionService::class, function ($mock) {
                $mock->shouldReceive('convertToHtml')->andReturnUsing(function (string $docxPath) {
                    $zip = new ZipArchive;
                    $zip->open($docxPath);
                    $xml = $zip->getFromName('word/document.xml');
                    $zip->close();

                    return $xml === false ? '' : $xml;
                });
            })
        );
    }
}
