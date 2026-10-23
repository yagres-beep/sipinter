<?php

namespace Tests\Unit;

use App\Support\FormatAngkaKolom;
use PHPUnit\Framework\TestCase;

class FormatAngkaKolomTest extends TestCase
{
    /** @return array<string, mixed> */
    private function kolom(array $atur = []): array
    {
        return $atur + ['kunci' => 'nilai', 'label' => 'Nilai', 'tipe' => 'angka', 'opsi' => [], 'wajib' => false];
    }

    public function test_urai_koma_desimal_menerima_ribuan_titik(): void
    {
        $this->assertSame('1234.56', FormatAngkaKolom::urai('1.234,56', 'koma'));
        $this->assertSame('1234.5', FormatAngkaKolom::urai('1234,5', 'koma'));
        $this->assertSame('1234567', FormatAngkaKolom::urai('1.234.567', 'koma'));
        $this->assertSame('-12.3', FormatAngkaKolom::urai('-12,30', 'koma'));
        $this->assertSame('0.5', FormatAngkaKolom::urai('0,5', 'koma'));
    }

    public function test_urai_koma_desimal_menolak_format_lain(): void
    {
        // Titik sebagai desimal tidak diterima bila diatur koma: "1.5" bisa disalahbaca.
        $this->assertNull(FormatAngkaKolom::urai('1.5', 'koma'));
        $this->assertNull(FormatAngkaKolom::urai('1,234.56', 'koma'));
        $this->assertNull(FormatAngkaKolom::urai('12.34', 'koma'));
        $this->assertNull(FormatAngkaKolom::urai('abc', 'koma'));
        $this->assertNull(FormatAngkaKolom::urai('1e3', 'koma'));
        $this->assertNull(FormatAngkaKolom::urai('', 'koma'));
    }

    public function test_urai_titik_desimal(): void
    {
        $this->assertSame('1234.56', FormatAngkaKolom::urai('1,234.56', 'titik'));
        $this->assertSame('12.5', FormatAngkaKolom::urai('12.5', 'titik'));
        $this->assertNull(FormatAngkaKolom::urai('12,5', 'titik'));
        $this->assertNull(FormatAngkaKolom::urai('1.234,56', 'titik'));
    }

    public function test_urai_aturan_lama_satu_pemisah_desimal(): void
    {
        $this->assertSame('12.5', FormatAngkaKolom::urai('12,5', null));
        $this->assertSame('12.5', FormatAngkaKolom::urai('12.5', null));
        $this->assertSame('12', FormatAngkaKolom::urai('12', null));
        $this->assertNull(FormatAngkaKolom::urai('1.234,5', null));
        $this->assertNull(FormatAngkaKolom::urai('dua', null));
    }

    public function test_tampil_mengikuti_jumlah_desimal_pemisah_dan_ribuan(): void
    {
        $this->assertSame('1.234,50', FormatAngkaKolom::tampil('1234.5', $this->kolom(['desimal' => 2])));
        $this->assertSame('1,234.50', FormatAngkaKolom::tampil('1234.5', $this->kolom(['desimal' => 2, 'pemisah' => 'titik'])));
        $this->assertSame('1234,50', FormatAngkaKolom::tampil('1234.5', $this->kolom(['desimal' => 2, 'ribuan' => false])));
        $this->assertSame('12,3', FormatAngkaKolom::tampil('12.34', $this->kolom(['desimal' => 1])));
        $this->assertSame('12,34', FormatAngkaKolom::tampil('12.34', $this->kolom(['desimal' => 2])));
        $this->assertSame('1.235', FormatAngkaKolom::tampil('1234.5', $this->kolom(['desimal' => 0])));
    }

    public function test_tampil_dengan_satuan(): void
    {
        $this->assertSame('85,00 persen', FormatAngkaKolom::tampil('85', $this->kolom(['desimal' => 2, 'satuan' => 'persen'])));
        $this->assertSame('85,0%', FormatAngkaKolom::tampil('85', $this->kolom(['desimal' => 1, 'satuan' => '%'])));
        // Satuan tanpa format desimal: angka tetap apa adanya.
        $this->assertSame('12,5 orang', FormatAngkaKolom::tampil('12,5', $this->kolom(['satuan' => 'orang'])));
    }

    public function test_kolom_tanpa_format_tampil_apa_adanya(): void
    {
        $this->assertSame('12,5', FormatAngkaKolom::tampil('12,5', $this->kolom()));
        $this->assertSame('12.5', FormatAngkaKolom::tampil('12.5', $this->kolom()));
        $this->assertSame('', FormatAngkaKolom::tampil('', $this->kolom(['desimal' => 2, 'satuan' => 'persen'])));
        $this->assertSame('', FormatAngkaKolom::tampil(null, $this->kolom(['desimal' => 2])));
    }

    public function test_nilai_gaya_lama_tetap_terbaca_saat_kolom_diberi_format(): void
    {
        // Nilai "12,5" tersimpan sebelum format diatur tetap tampil benar setelahnya.
        $this->assertSame('12,50', FormatAngkaKolom::tampil('12,5', $this->kolom(['desimal' => 2])));
    }

    public function test_kolom_non_angka_tidak_diformat(): void
    {
        $this->assertSame('1234.5', FormatAngkaKolom::tampil('1234.5', $this->kolom(['tipe' => 'teks', 'desimal' => 2, 'satuan' => 'persen'])));
    }

    public function test_tidak_ada_nol_negatif(): void
    {
        $this->assertSame('0,00', FormatAngkaKolom::tampil('-0.001', $this->kolom(['desimal' => 2])));
        $this->assertSame('-1,50', FormatAngkaKolom::tampil('-1.5', $this->kolom(['desimal' => 2])));
    }

    public function test_simpan_kanonik_hanya_untuk_kolom_berformat(): void
    {
        $this->assertSame('1234.5', FormatAngkaKolom::untukDisimpan('1.234,50', $this->kolom(['desimal' => 2])));
        $this->assertSame('1234.5', FormatAngkaKolom::untukDisimpan('1,234.5', $this->kolom(['desimal' => 2, 'pemisah' => 'titik'])));
        // Kolom lama tanpa format: disimpan persis seperti diketik (perilaku lama).
        $this->assertSame('12,5', FormatAngkaKolom::untukDisimpan(' 12,5 ', $this->kolom()));
    }

    public function test_nilai_untuk_input_tidak_dibulatkan(): void
    {
        $kolom = $this->kolom(['desimal' => 1]);

        $this->assertSame('1234,567', FormatAngkaKolom::untukInput('1234.567', $kolom));
        $this->assertSame('1234.567', FormatAngkaKolom::untukInput('1234.567', $this->kolom(['desimal' => 1, 'pemisah' => 'titik'])));
        $this->assertSame('12,5', FormatAngkaKolom::untukInput('12,5', $this->kolom()));
    }

    public function test_pesan_galat_menyebut_pemisah_yang_diharapkan(): void
    {
        $this->assertNull(FormatAngkaKolom::pesanGalat('1.234,5', $this->kolom(['desimal' => 2])));
        $this->assertStringContainsString('koma sebagai pemisah desimal', FormatAngkaKolom::pesanGalat('12.5', $this->kolom(['desimal' => 2])));
        $this->assertStringContainsString('titik sebagai pemisah desimal', FormatAngkaKolom::pesanGalat('12,5', $this->kolom(['desimal' => 2, 'pemisah' => 'titik'])));
        $this->assertSame('"Nilai" harus berupa angka.', FormatAngkaKolom::pesanGalat('dua', $this->kolom()));
    }
}
