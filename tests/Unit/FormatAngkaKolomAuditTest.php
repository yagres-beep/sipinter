<?php

namespace Tests\Unit;

use App\Models\BagianKustom;
use App\Support\FormatAngkaKolom;
use Tests\TestCase;

/**
 * Audit format angka notula (nilaiKolomTampil / FormatAngkaKolom::tampil): kasus tepi
 * yang tidak dicakup FormatAngkaKolomTest. Format hanya mengatur TAMPILAN — nilai
 * tersimpan tidak pernah diubah.
 */
class FormatAngkaKolomAuditTest extends TestCase
{
    private function kolom(array $atur = []): array
    {
        return BagianKustom::normalkanKolom($atur + ['kunci' => 'nilai', 'label' => 'Nilai', 'tipe' => 'angka']);
    }

    public function test_kosong_tetap_kosong_dan_tanpa_satuan(): void
    {
        $kolom = $this->kolom(['desimal' => 2, 'satuan' => '%']);

        $this->assertSame('', FormatAngkaKolom::tampil(null, $kolom));
        $this->assertSame('', FormatAngkaKolom::tampil('', $kolom));
        $this->assertSame('', FormatAngkaKolom::tampil('   ', $kolom));
    }

    public function test_nol_dan_negatif(): void
    {
        $koma = $this->kolom(['desimal' => 2]);

        $this->assertSame('0,00', FormatAngkaKolom::tampil('0', $koma));
        $this->assertSame('0,00', FormatAngkaKolom::tampil('-0', $koma));
        $this->assertSame('0,00', FormatAngkaKolom::tampil('-0.004', $koma), 'pembulatan ke nol tidak bertanda minus');
        $this->assertSame('-0,01', FormatAngkaKolom::tampil('-0.005', $koma), 'setengah menjauhi nol, sama seperti round()');
        $this->assertSame('-1.234,50', FormatAngkaKolom::tampil('-1234.5', $koma));
    }

    public function test_pembulatan_eksak_tanpa_galat_float(): void
    {
        $dua = $this->kolom(['desimal' => 2]);

        $this->assertSame('1,01', FormatAngkaKolom::tampil('1.005', $dua));
        $this->assertSame('1,00', FormatAngkaKolom::tampil('1.0049', $dua));
        $this->assertSame('1.000,0', FormatAngkaKolom::tampil('999.95', $this->kolom(['desimal' => 1])));
        $this->assertSame('10', FormatAngkaKolom::tampil('9.5', $this->kolom(['desimal' => 0])));
        $this->assertSame('0,333333', FormatAngkaKolom::tampil('0.3333333', $this->kolom(['desimal' => 6])));
    }

    public function test_angka_besar_tampil_persis(): void
    {
        $kolom = $this->kolom(['desimal' => 2]);

        // Di atas presisi float (2^53) — dulu digit terakhirnya bisa bergeser.
        $this->assertSame('123.456.789.012.345.678,90', FormatAngkaKolom::tampil('123456789012345678.9', $kolom));
        $this->assertSame('9.007.199.254.740.993,00', FormatAngkaKolom::tampil('9007199254740993', $kolom));
    }

    public function test_pemisah_titik_dan_tanpa_ribuan(): void
    {
        $this->assertSame('1,234,567.80', FormatAngkaKolom::tampil('1234567.8', $this->kolom(['desimal' => 2, 'pemisah' => 'titik'])));
        $this->assertSame('1234567,8', FormatAngkaKolom::tampil('1234567.8', $this->kolom(['desimal' => 1, 'ribuan' => false])));
        $this->assertSame('123', FormatAngkaKolom::tampil('123', $this->kolom(['desimal' => 0])));
    }

    public function test_satuan_persen_menempel_satuan_lain_berspasi(): void
    {
        $this->assertSame('85,50%', FormatAngkaKolom::tampil('85.5', $this->kolom(['desimal' => 2, 'satuan' => '%'])));
        $this->assertSame('12 orang', FormatAngkaKolom::tampil('12', $this->kolom(['satuan' => 'orang'])));
    }

    public function test_nilai_gaya_lama_dengan_koma_tetap_dibaca(): void
    {
        // Nilai lama (sebelum format diatur) bisa tersimpan "12,5" — tetap diformat.
        $this->assertSame('12,50', FormatAngkaKolom::tampil('12,5', $this->kolom(['desimal' => 2])));
    }

    public function test_kolom_tanpa_format_tidak_dibulatkan_maupun_diubah(): void
    {
        $kolom = $this->kolom();

        $this->assertSame('1234.5678', FormatAngkaKolom::tampil('1234.5678', $kolom));
        $this->assertSame('12,5', FormatAngkaKolom::tampil('12,5', $kolom));
    }

    public function test_tampilan_tidak_mengubah_nilai_tersimpan(): void
    {
        $bagian = new BagianKustom(['kolom' => [
            ['kunci' => 'skor', 'label' => 'Skor', 'tipe' => 'angka', 'desimal' => 1],
            ['kunci' => 'ket', 'label' => 'Ket', 'tipe' => 'teks'],
        ]]);
        $tersimpan = ['skor' => '12.345', 'ket' => '12.345'];

        $this->assertSame(['skor' => '12,3', 'ket' => '12.345'], $bagian->nilaiKolomTampil($tersimpan));
        $this->assertSame(['skor' => '12.345', 'ket' => '12.345'], $tersimpan);
        // Formulir menampilkan nilai lengkap (tanpa pembulatan) supaya simpan ulang
        // tidak memotong presisi.
        $this->assertSame('12,345', $bagian->nilaiKolomUntukForm($tersimpan)['skor']);
    }
}
