<?php

namespace Tests\Unit;

use App\Services\CapaianCalculatorService;
use PHPUnit\Framework\TestCase;

/**
 * CapaianCalculatorService — rumus murni Capaian Kinerja Triwulanan, sesuai Kertas
 * Kerja Pengukuran Kinerja Triwulanan resmi. PHPUnit\Framework\TestCase biasa (BUKAN
 * Tests\TestCase/RefreshDatabase) karena service ini murni matematis, tanpa
 * ketergantungan DB sama sekali.
 *
 * Rumus Penilaian Kinerja Organisasi (PKO) TIDAK ADA di sini — dihapus dari cakupan
 * aplikasi ini seluruhnya.
 */
class CapaianCalculatorServiceTest extends TestCase
{
    // --- hitungPersentase (X÷Y×100, Rumus 2.2) -----------------------------

    public function test_hitung_persentase_pembagi_nol_atau_null_dikembalikan_nol(): void
    {
        $this->assertSame(0.0, CapaianCalculatorService::hitungPersentase(5, 0));
        $this->assertSame(0.0, CapaianCalculatorService::hitungPersentase(5, null));
        $this->assertSame(0.0, CapaianCalculatorService::hitungPersentase(null, null));
    }

    public function test_hitung_persentase_menghitung_rasio_x_dibagi_y(): void
    {
        $this->assertSame(75.0, CapaianCalculatorService::hitungPersentase(3, 4));
        $this->assertSame(0.0, CapaianCalculatorService::hitungPersentase(null, 4));
    }

    // --- hitungCapaian (Realisasi terhadap Alokasi, Rumus 2.3/2.4, plafon 120) --

    public function test_hitung_capaian_alokasi_nol_realisasi_positif_dianggap_capaian_penuh(): void
    {
        $this->assertSame(120.0, CapaianCalculatorService::hitungCapaian(0, 50));
    }

    public function test_hitung_capaian_alokasi_nol_realisasi_nol_tidak_dinilai(): void
    {
        $this->assertSame('-', CapaianCalculatorService::hitungCapaian(0, 0));
    }

    public function test_hitung_capaian_alokasi_positif_realisasi_nol_atau_negatif_tidak_dinilai(): void
    {
        $this->assertSame('-', CapaianCalculatorService::hitungCapaian(100, 0));
        $this->assertSame('-', CapaianCalculatorService::hitungCapaian(100, -5));
    }

    public function test_hitung_capaian_melebihi_120_dibatasi_plafon(): void
    {
        // 200/100*100 = 200%, dibatasi ke plafon 120.
        $this->assertSame(120.0, CapaianCalculatorService::hitungCapaian(100, 200));
    }

    public function test_hitung_capaian_normal_di_bawah_plafon(): void
    {
        $this->assertSame(80.0, CapaianCalculatorService::hitungCapaian(100, 80));
    }

    public function test_hitung_capaian_plafon_bisa_dikonfigurasi(): void
    {
        $this->assertSame(150.0, CapaianCalculatorService::hitungCapaian(100, 200, 150.0));
    }

    /**
     * Bagian 4 edge case 3 — contoh verifikasi LITERAL dari spek (Indeks Pelayanan
     * Publik, Tipe B, target 4,35): realisasi kumulatif TW II = 0,79045, alokasi
     * kumulatif TW II = 1,04 -> vs triwulanan 76,00, vs setahun 18,17. Diuji lewat
     * fungsi murni langsung (bukan lewat model CapaianTahunan) supaya presisi 5
     * desimal spek tidak terpotong oleh cast 'decimal:2' pada kolom DB.
     */
    public function test_contoh_literal_indeks_pelayanan_publik_dari_spek(): void
    {
        $vsTriwulanan = CapaianCalculatorService::hitungCapaian(1.04, 0.79045);
        $vsSetahun = CapaianCalculatorService::hitungCapaian(4.35, 0.79045);

        $this->assertEqualsWithDelta(76.00, $vsTriwulanan, 0.01);
        $this->assertEqualsWithDelta(18.17, $vsSetahun, 0.01);
    }

    // --- predikatSakip (Rumus 2.1, info saja) -------------------------------

    public function test_predikat_sakip_sesuai_ambang(): void
    {
        $this->assertSame('AA/Sangat Memuaskan', CapaianCalculatorService::predikatSakip(91));
        $this->assertSame('A/Memuaskan', CapaianCalculatorService::predikatSakip(85));
        $this->assertSame('BB/Sangat Baik', CapaianCalculatorService::predikatSakip(75));
        $this->assertSame('B/Baik', CapaianCalculatorService::predikatSakip(65));
        $this->assertSame('CC/Cukup (Memadai)', CapaianCalculatorService::predikatSakip(55));
        $this->assertSame('C/Kurang', CapaianCalculatorService::predikatSakip(35));
        $this->assertSame('D/Sangat Kurang', CapaianCalculatorService::predikatSakip(10));
    }

    // --- rataRataCapaianTriwulanan (Bagian 3 bullet 1, abaikan "-" DAN 0) ---

    public function test_rata_rata_capaian_triwulanan_mengabaikan_tidak_dinilai_dan_nol(): void
    {
        $this->assertSame(90.0, CapaianCalculatorService::rataRataCapaianTriwulanan([80.0, 100.0, '-', 0.0]));
    }

    public function test_rata_rata_capaian_triwulanan_tidak_dinilai_bila_tidak_ada_nilai_valid(): void
    {
        $this->assertSame('-', CapaianCalculatorService::rataRataCapaianTriwulanan(['-', 0.0, '-']));
        $this->assertSame('-', CapaianCalculatorService::rataRataCapaianTriwulanan([]));
    }

    // --- rataRataCapaianSetahun (Bagian 3 bullet 2, pembagi = total indikator) --

    public function test_rata_rata_capaian_setahun_tidak_dinilai_bila_semua_tidak_dinilai(): void
    {
        $this->assertSame('-', CapaianCalculatorService::rataRataCapaianSetahun(['-', '-']));
        $this->assertSame('-', CapaianCalculatorService::rataRataCapaianSetahun([]));
    }

    public function test_rata_rata_capaian_setahun_dibagi_jumlah_total_bukan_jumlah_valid(): void
    {
        // (80 + 0) / 2 = 40 -- "-" dihitung 0 dalam jumlah, TAPI pembagi tetap 2
        // (jumlah total elemen), BUKAN 1 (jumlah nilai valid seperti rataRataCapaianTriwulanan).
        $this->assertSame(40.0, CapaianCalculatorService::rataRataCapaianSetahun([80.0, '-']));
    }

    public function test_rata_rata_capaian_setahun_beda_hasil_dari_rata_rata_capaian_triwulanan(): void
    {
        $list = [80.0, 0.0, '-'];

        // rataRataCapaianTriwulanan: abaikan "-" DAN 0 -> hanya [80] -> 80.
        $this->assertSame(80.0, CapaianCalculatorService::rataRataCapaianTriwulanan($list));
        // rataRataCapaianSetahun: (80+0+0)/3 = 26.67.
        $this->assertEqualsWithDelta(26.6667, CapaianCalculatorService::rataRataCapaianSetahun($list), 0.001);
    }

    // --- subtotalPerSasaran (Bagian 3 bullet 3, abaikan "-" SAJA, 0 tetap ikut) --

    public function test_subtotal_per_sasaran_mengabaikan_tidak_dinilai_tapi_nol_tetap_ikut(): void
    {
        // (80 + 0) / 2 = 40 -- beda dari rataRataCapaianTriwulanan yang akan abaikan 0.
        $this->assertSame(40.0, CapaianCalculatorService::subtotalPerSasaran([80.0, 0.0, '-']));
    }

    public function test_subtotal_per_sasaran_tidak_dinilai_bila_semua_tidak_dinilai(): void
    {
        $this->assertSame('-', CapaianCalculatorService::subtotalPerSasaran(['-', '-']));
    }
}
