<?php

namespace Tests\Unit;

use App\Models\Capaian;
use App\Models\PengaturanCapaian;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rumus Penilaian Kinerja Organisasi (PKO) — dicocokkan dengan rumus MENTAH sheet
 * resmi "LK_Kabkot" (E5 Predikat SAKIP, AJ Normalisasi Capaian PK, AK Koreksi, AL
 * Nilai Akhir Capaian PK). RefreshDatabase dipakai karena normalisasiCapaianPk()
 * membaca PengaturanCapaian::ambil() dari DB.
 */
class CapaianPkoTest extends TestCase
{
    use RefreshDatabase;

    public function test_predikat_sakip_sesuai_ambang_sheet_resmi(): void
    {
        $this->assertSame('AA/Sangat Memuaskan', Capaian::predikatSakip(91));
        $this->assertSame('A/Memuaskan', Capaian::predikatSakip(85));
        $this->assertSame('BB/Sangat Baik', Capaian::predikatSakip(75));
        $this->assertSame('B/Baik', Capaian::predikatSakip(65));
        $this->assertSame('CC/Cukup (Memadai)', Capaian::predikatSakip(55));
        $this->assertSame('C/Kurang', Capaian::predikatSakip(35));
        $this->assertSame('D/Sangat Kurang', Capaian::predikatSakip(10));
    }

    public function test_koreksi_predikat_sesuai_mapping_sheet_resmi(): void
    {
        $this->assertSame(0.0, Capaian::koreksiPredikat('AA/Sangat Memuaskan'));
        $this->assertSame(0.0, Capaian::koreksiPredikat('A/Memuaskan'));
        $this->assertSame(10.0, Capaian::koreksiPredikat('BB/Sangat Baik'));
        $this->assertSame(15.0, Capaian::koreksiPredikat('B/Baik'));
        $this->assertSame(20.0, Capaian::koreksiPredikat('CC/Cukup (Memadai)'));
        $this->assertSame(30.0, Capaian::koreksiPredikat('C/Kurang'));
        $this->assertSame(30.0, Capaian::koreksiPredikat('D/Sangat Kurang'));
    }

    public function test_normalisasi_capaian_pk_null_bila_belum_ada_capaian_setahun(): void
    {
        $this->assertNull(Capaian::normalisasiCapaianPk(null));
    }

    public function test_normalisasi_capaian_pk_dibatasi_batas_normalisasi_pko(): void
    {
        PengaturanCapaian::ambil()->update(['batas_normalisasi_pko' => 110]);
        PengaturanCapaian::lupakanCache();

        // Sesuai sheet: AJ12 = IF(AB12>=110,"110",AB12).
        $this->assertSame(110.0, Capaian::normalisasiCapaianPk(120.0));
        $this->assertSame(95.5, Capaian::normalisasiCapaianPk(95.5));
    }

    public function test_nilai_akhir_capaian_pk_cocok_contoh_sheet(): void
    {
        // Contoh sheet: Normalisasi=110, Koreksi=30% -> Nilai Akhir=77.
        $this->assertSame(77.0, Capaian::nilaiAkhirCapaianPk(110.0, 30.0));
        $this->assertNull(Capaian::nilaiAkhirCapaianPk(null, 30.0));
    }
}
