<?php

namespace App\Services;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/**
 * Evaluator rumus capaian KUSTOM untuk IKU metode_capaian 'langsung' (Non %) --
 * lihat App\Models\MasterIku::formula_capaian, dipakai App\Models\CapaianTahunan::
 * capaianTriwulanan()/capaianSetahun() sebagai pengganti rumus baku
 * CapaianCalculatorService::hitungCapaian() HANYA bila Tim SAKIP mengisi formula
 * ini untuk IKU tsb. Pakai Symfony ExpressionLanguage (bukan eval() PHP) --
 * sandboxed, hanya bisa mengevaluasi ekspresi matematis murni dari variabel yang
 * disediakan, tidak bisa memanggil fungsi/kelas PHP sembarang.
 *
 * Variabel yang tersedia di dalam rumus: alokasi, realisasi, batas (lihat
 * evaluasi()) -- sama seperti parameter App\Services\CapaianCalculatorService::
 * hitungCapaian(), supaya rumus kustom bisa meniru/menurunkan dari rumus baku.
 */
class FormulaCapaianService
{
    protected static ?ExpressionLanguage $bahasa = null;

    protected static function bahasa(): ExpressionLanguage
    {
        return self::$bahasa ??= new ExpressionLanguage;
    }

    /**
     * Evaluasi $formula dengan nilai $alokasi/$realisasi/$batas sesungguhnya --
     * dipanggil saat menghitung Capaian % yang benar-benar ditampilkan.
     *
     * @throws SyntaxError bila $formula tidak valid -- pemanggil (CapaianTahunan)
     *                      SEHARUSNYA tidak pernah sampai ke sini dengan formula
     *                      tidak valid, karena validasi() sudah menyaring saat
     *                      disimpan lewat form Master IKU.
     */
    public static function evaluasi(string $formula, float $alokasi, float $realisasi, float $batas): float
    {
        $hasil = self::bahasa()->evaluate($formula, [
            'alokasi' => $alokasi,
            'realisasi' => $realisasi,
            'batas' => $batas,
        ]);

        return (float) $hasil;
    }

    /**
     * Coba evaluasi $formula dengan angka contoh -- dipakai validasi form Master
     * IKU (rules 'formulaCapaian') supaya Tim SAKIP langsung tahu bila ada salah
     * ketik saat menyimpan, bukan baru gagal nanti saat Capaian % dihitung.
     */
    public static function valid(string $formula): bool
    {
        try {
            $hasil = self::evaluasi($formula, 100.0, 80.0, 120.0);
        } catch (\Throwable $e) {
            return false;
        }

        return is_numeric($hasil);
    }
}
