<?php

namespace Tests\Unit;

use App\Models\Notula;
use Tests\TestCase;

class NotulaTest extends TestCase
{
    /**
     * tanggalTtd() dipakai baris "Kulisusu, <tanggal>" di blok TTD (dokumen gabungan
     * maupun tabel TTD bawaan template .docx) -- HARUS tanggal saja, TANPA nama hari,
     * walau hari_tanggal-nya ditulis bebas oleh Tim SAKIP (pemisah "/" atau ",", atau
     * tanpa nama hari sama sekali).
     *
     * @dataProvider hariTanggalProvider
     */
    public function test_tanggal_ttd_membuang_nama_hari(?string $hariTanggal, ?string $diharapkan): void
    {
        $notula = new Notula(['hari_tanggal' => $hariTanggal]);

        $this->assertSame($diharapkan, $notula->tanggalTtd());
    }

    public static function hariTanggalProvider(): array
    {
        return [
            'pemisah koma' => ['Selasa, 1 September 2026', '1 September 2026'],
            'pemisah garis miring' => ['Jumat/17 Juli 2026', '17 Juli 2026'],
            'tanpa nama hari' => ['17 Juli 2026', '17 Juli 2026'],
            'null' => [null, null],
            'string kosong' => ['', null],
        ];
    }
}
