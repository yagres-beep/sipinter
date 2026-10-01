<?php

namespace Tests\Unit;

use App\Models\Notula;
use App\Models\Periode;
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

    /**
     * Nama berkas notula SELALU berakhiran "-v{n}" (lihat Notula::namaUnduhan()) —
     * dipakai jalur unduhan, nama berkas fisik di disk, maupun arsip Drive, supaya
     * PDF tiap versi tersimpan terpisah dan pembacanya tahu ini versi ke berapa.
     */
    public function test_nama_unduhan_memuat_nomor_versi(): void
    {
        $notula = new Notula(['versi' => 3]);
        $notula->setRelation('periode', new Periode(['tahun' => 2026, 'triwulan' => 3]));

        $this->assertSame('notula-final-tw3-2026-v3.pdf', $notula->namaUnduhan('final'));
        $this->assertSame('notula-draf-tw3-2026-v3.pdf', $notula->namaUnduhan('draf'));
        $this->assertSame('notula-bagian1-tw3-2026-v3.docx', $notula->namaUnduhan('bagian1', 'docx'));
    }

    /**
     * Baris notula lama (dibuat sebelum kolom `versi` ada) tetap terbaca sebagai
     * versi 1, bukan 0/null yang akan bocor ke nama berkas.
     */
    public function test_versi_saat_ini_jatuh_ke_satu_bila_kolomnya_kosong(): void
    {
        $notula = new Notula;
        $notula->versi = null;

        $this->assertSame(1, $notula->versiSaatIni());
    }
}
