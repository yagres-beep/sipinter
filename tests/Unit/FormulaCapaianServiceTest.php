<?php

namespace Tests\Unit;

use App\Services\FormulaCapaianService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/**
 * Evaluator rumus capaian kustom (Symfony ExpressionLanguage, bukan eval() PHP) --
 * murni/tanpa DB, sama seperti CapaianCalculatorServiceTest.
 */
class FormulaCapaianServiceTest extends TestCase
{
    public function test_evaluasi_rumus_sederhana(): void
    {
        $hasil = FormulaCapaianService::evaluasi('realisasi / alokasi * 100', 50.0, 25.0, 120.0);

        $this->assertEqualsWithDelta(50.0, $hasil, 0.001);
    }

    public function test_evaluasi_bisa_memakai_variabel_batas(): void
    {
        $hasil = FormulaCapaianService::evaluasi('min(realisasi / alokasi * 100, batas)', 100.0, 200.0, 120.0);

        $this->assertEqualsWithDelta(120.0, $hasil, 0.001);
    }

    public function test_evaluasi_rumus_tidak_valid_melempar_syntax_error(): void
    {
        $this->expectException(SyntaxError::class);

        FormulaCapaianService::evaluasi('realisasi / / alokasi', 1.0, 1.0, 120.0);
    }

    public function test_valid_true_untuk_rumus_benar(): void
    {
        $this->assertTrue(FormulaCapaianService::valid('min(realisasi / alokasi * 100, batas)'));
    }

    public function test_valid_false_untuk_rumus_salah_ketik(): void
    {
        $this->assertFalse(FormulaCapaianService::valid('realisasi / / alokasi'));
    }

    public function test_valid_false_untuk_variabel_tidak_dikenal(): void
    {
        $this->assertFalse(FormulaCapaianService::valid('realisasi + variabelTidakAda'));
    }

    public function test_valid_false_untuk_pemanggilan_fungsi_tidak_diizinkan(): void
    {
        // ExpressionLanguage TANPA fungsi kustom terdaftar hanya mengenal fungsi
        // bawaan (min/max/dst.) -- memanggil sesuatu yang bukan fungsi terdaftar
        // (mis. mencoba akses PHP langsung) harus gagal, bukan tereksekusi.
        $this->assertFalse(FormulaCapaianService::valid('phpinfo()'));
    }
}
