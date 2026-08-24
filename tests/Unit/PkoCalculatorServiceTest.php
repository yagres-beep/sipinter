<?php

namespace Tests\Unit;

use App\Services\PkoCalculatorService;
use PHPUnit\Framework\TestCase;

/**
 * PkoCalculatorService — rumus murni Capaian Kinerja & Penilaian Kinerja
 * Organisasi (PKO), sesuai Kertas Kerja Pengukuran Kinerja Triwulanan resmi.
 * PHPUnit\Framework\TestCase biasa (BUKAN Tests\TestCase/RefreshDatabase) karena
 * service ini murni matematis, tanpa ketergantungan DB sama sekali.
 */
class PkoCalculatorServiceTest extends TestCase
{
    // --- hitungPersentase (X÷Y×100) ---------------------------------------

    public function test_hitung_persentase_pembagi_nol_atau_null_dikembalikan_nol(): void
    {
        $this->assertSame(0.0, PkoCalculatorService::hitungPersentase(5, 0));
        $this->assertSame(0.0, PkoCalculatorService::hitungPersentase(5, null));
        $this->assertSame(0.0, PkoCalculatorService::hitungPersentase(null, null));
    }

    public function test_hitung_persentase_menghitung_rasio_x_dibagi_y(): void
    {
        $this->assertSame(75.0, PkoCalculatorService::hitungPersentase(3, 4));
        $this->assertSame(0.0, PkoCalculatorService::hitungPersentase(null, 4));
    }

    // --- hitungCapaian (Realisasi terhadap Alokasi, plafon 120) -----------

    public function test_hitung_capaian_alokasi_nol_realisasi_positif_dianggap_capaian_penuh(): void
    {
        $this->assertSame(120.0, PkoCalculatorService::hitungCapaian(0, 50));
    }

    public function test_hitung_capaian_alokasi_nol_realisasi_nol_tidak_dinilai(): void
    {
        $this->assertSame('-', PkoCalculatorService::hitungCapaian(0, 0));
    }

    public function test_hitung_capaian_alokasi_positif_realisasi_nol_atau_negatif_tidak_dinilai(): void
    {
        $this->assertSame('-', PkoCalculatorService::hitungCapaian(100, 0));
        $this->assertSame('-', PkoCalculatorService::hitungCapaian(100, -5));
    }

    public function test_hitung_capaian_melebihi_120_dibatasi_plafon(): void
    {
        // 200/100*100 = 200%, dibatasi ke plafon 120.
        $this->assertSame(120.0, PkoCalculatorService::hitungCapaian(100, 200));
    }

    public function test_hitung_capaian_normal_di_bawah_plafon(): void
    {
        $this->assertSame(80.0, PkoCalculatorService::hitungCapaian(100, 80));
    }

    public function test_hitung_capaian_plafon_bisa_dikonfigurasi(): void
    {
        $this->assertSame(150.0, PkoCalculatorService::hitungCapaian(100, 200, 150.0));
    }

    // --- predikatSakip ------------------------------------------------------

    public function test_predikat_sakip_sesuai_ambang(): void
    {
        $this->assertSame('AA/Sangat Memuaskan', PkoCalculatorService::predikatSakip(91));
        $this->assertSame('A/Memuaskan', PkoCalculatorService::predikatSakip(85));
        $this->assertSame('BB/Sangat Baik', PkoCalculatorService::predikatSakip(75));
        $this->assertSame('B/Baik', PkoCalculatorService::predikatSakip(65));
        $this->assertSame('CC/Cukup (Memadai)', PkoCalculatorService::predikatSakip(55));
        $this->assertSame('C/Kurang', PkoCalculatorService::predikatSakip(35));
        $this->assertSame('D/Sangat Kurang', PkoCalculatorService::predikatSakip(10));
    }

    // --- normalisasiCapaianPK (plafon 110) ----------------------------------

    public function test_normalisasi_capaian_pk_tidak_dinilai_tetap_tidak_dinilai(): void
    {
        $this->assertSame('-', PkoCalculatorService::normalisasiCapaianPK('-'));
    }

    public function test_normalisasi_capaian_pk_dibatasi_plafon_110(): void
    {
        $this->assertSame(110.0, PkoCalculatorService::normalisasiCapaianPK(120.0));
        $this->assertSame(110.0, PkoCalculatorService::normalisasiCapaianPK(110.0));
    }

    public function test_normalisasi_capaian_pk_di_bawah_plafon_apa_adanya(): void
    {
        $this->assertSame(95.5, PkoCalculatorService::normalisasiCapaianPK(95.5));
    }

    public function test_normalisasi_capaian_pk_plafon_bisa_dikonfigurasi(): void
    {
        $this->assertSame(100.0, PkoCalculatorService::normalisasiCapaianPK(120.0, 100.0));
    }

    // --- koreksiPredikat (desimal) -------------------------------------------

    public function test_koreksi_predikat_sesuai_mapping(): void
    {
        $this->assertSame(0.0, PkoCalculatorService::koreksiPredikat('AA/Sangat Memuaskan'));
        $this->assertSame(0.0, PkoCalculatorService::koreksiPredikat('A/Memuaskan'));
        $this->assertSame(0.10, PkoCalculatorService::koreksiPredikat('BB/Sangat Baik'));
        $this->assertSame(0.15, PkoCalculatorService::koreksiPredikat('B/Baik'));
        $this->assertSame(0.20, PkoCalculatorService::koreksiPredikat('CC/Cukup (Memadai)'));
        $this->assertSame(0.30, PkoCalculatorService::koreksiPredikat('C/Kurang'));
        $this->assertSame(0.30, PkoCalculatorService::koreksiPredikat('D/Sangat Kurang'));
    }

    // --- nilaiAkhirPK ---------------------------------------------------------

    public function test_nilai_akhir_pk_normalisasi_dikali_satu_dikurang_koreksi(): void
    {
        // Normalisasi=110, Koreksi=30% -> Nilai Akhir=77.
        $this->assertEqualsWithDelta(77.0, PkoCalculatorService::nilaiAkhirPK(110.0, 0.30), 0.0001);
    }

    public function test_nilai_akhir_pk_tanpa_koreksi(): void
    {
        $this->assertSame(100.0, PkoCalculatorService::nilaiAkhirPK(100.0, 0.0));
    }

    // --- capaianKinerjaIKU (abaikan "-" DAN 0) ------------------------------

    public function test_capaian_kinerja_iku_mengabaikan_tidak_dinilai_dan_nol(): void
    {
        $this->assertSame(90.0, PkoCalculatorService::capaianKinerjaIKU([80.0, 100.0, '-', 0.0]));
    }

    public function test_capaian_kinerja_iku_tidak_dinilai_bila_tidak_ada_nilai_valid(): void
    {
        $this->assertSame('-', PkoCalculatorService::capaianKinerjaIKU(['-', 0.0, '-']));
        $this->assertSame('-', PkoCalculatorService::capaianKinerjaIKU([]));
    }

    // --- capaianPerSasaran (abaikan "-" SAJA, 0 tetap ikut) ------------------

    public function test_capaian_per_sasaran_mengabaikan_tidak_dinilai_tapi_nol_tetap_ikut(): void
    {
        // (80 + 0) / 2 = 40 -- beda dari capaianKinerjaIKU yang akan abaikan 0.
        $this->assertSame(40.0, PkoCalculatorService::capaianPerSasaran([80.0, 0.0, '-']));
    }

    public function test_capaian_per_sasaran_tidak_dinilai_bila_semua_tidak_dinilai(): void
    {
        $this->assertSame('-', PkoCalculatorService::capaianPerSasaran(['-', '-']));
    }

    // --- totalCapaianPK / nkoRataRata / predikatPKO --------------------------

    public function test_total_capaian_pk_menjumlahkan_seluruh_nilai_akhir(): void
    {
        $this->assertSame(220.0, PkoCalculatorService::totalCapaianPK([100.0, 120.0]));
        $this->assertSame(0.0, PkoCalculatorService::totalCapaianPK([]));
    }

    public function test_nko_rata_rata_merata_ratakan_seluruh_nilai_akhir(): void
    {
        $this->assertSame(85.0, PkoCalculatorService::nkoRataRata([80.0, 90.0]));
        $this->assertSame(0.0, PkoCalculatorService::nkoRataRata([]));
    }

    public function test_predikat_pko_sesuai_ambang(): void
    {
        $this->assertSame('ISTIMEWA', PkoCalculatorService::predikatPKO(101));
        $this->assertSame('BAIK', PkoCalculatorService::predikatPKO(90));
        $this->assertSame('BUTUH PERBAIKAN', PkoCalculatorService::predikatPKO(70));
        $this->assertSame('BURUK', PkoCalculatorService::predikatPKO(50));
    }
}
