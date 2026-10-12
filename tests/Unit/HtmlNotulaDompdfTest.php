<?php

namespace Tests\Unit;

use App\Support\HtmlNotulaDompdf;
use DOMDocument;
use Dompdf\Dompdf;
use DOMXPath;
use Tests\TestCase;

class HtmlNotulaDompdfTest extends TestCase
{
    private const A4 = ['lebar' => 595.3, 'tinggi' => 841.9, 'atas' => 42.55, 'kanan' => 72.0, 'bawah' => 56.75, 'kiri' => 72.0];

    private function kalimat(int $n): string
    {
        return implode(' ', array_map(fn ($i) => "Kalimat-{$i} panjang untuk menguji pemenggalan sel tabel.", range(1, $n)));
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><html><body>'.$html.'</body></html>');
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    /** Teks dalam sel dengan spasi dirapatkan (pemecahan tidak boleh menghilangkan/menggandakan isi). */
    private function teksRapat(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', $this->xpath($html)->document->textContent) ?? '');
    }

    public function test_baris_lebih_tinggi_dari_satu_halaman_dipecah_tanpa_kehilangan_teks(): void
    {
        $html = '<table><tr><td style="border: 1px solid #000">Judul</td></tr>'
            .'<tr><td style="border: 1px solid #000"><p><b>Analisis</b> '.$this->kalimat(400).'</p><p>1. Kegiatan A<br>2. Kegiatan B</p></td></tr>'
            .'<tr><td style="border: 1px solid #000">AKHIR</td></tr></table>';

        $hasil = HtmlNotulaDompdf::siapkan($html, self::A4);

        $baris = $this->xpath($hasil)->query('//tr');
        $this->assertGreaterThan(4, $baris->length, 'Baris panjang harus dipecah jadi beberapa baris.');
        $this->assertSame($this->teksRapat($html), $this->teksRapat($hasil));
        // Format inline ikut terbawa dan garis antar-potongan dihilangkan.
        $this->assertStringContainsString('<b>Analisis</b>', $hasil);
        $this->assertStringContainsString('border-bottom: none', $hasil);
        $this->assertStringContainsString('border-top: none', $hasil);
    }

    public function test_pdf_dompdf_memuat_seluruh_isi_baris_panjang(): void
    {
        $html = '<table style="width:100%;border-collapse:collapse"><tr><td style="border:1px solid #000"><p>'
            .implode('<br>', array_map(fn ($i) => "Poin-{$i} uraian kegiatan.", range(1, 250)))
            .'</p></td></tr><tr><td>AKHIR-TABEL</td></tr></table>';

        $dompdf = new Dompdf;
        $dompdf->loadHtml('<html><body>'.HtmlNotulaDompdf::siapkan($html, self::A4).'</body></html>');
        $dompdf->render();

        // Tanpa pemecahan, dompdf memotong baris ini di halaman pertama (sisa poin hilang).
        $this->assertGreaterThanOrEqual(4, $dompdf->getCanvas()->get_page_count());
    }

    public function test_baris_pendek_dan_baris_dalam_cakupan_rowspan_tidak_diubah(): void
    {
        $panjang = '<p>'.$this->kalimat(400).'</p>';
        $html = '<table>'
            .'<tr><td rowspan="2">RS</td><td>A</td></tr>'
            .'<tr><td>'.$panjang.'</td></tr>'
            .'<tr><td colspan="2">Pendek</td></tr></table>';

        $hasil = HtmlNotulaDompdf::siapkan($html, self::A4);

        // 3 baris asli + 1 baris pengukur lebar kolom -- baris ber-rowspan tidak dipecah.
        $this->assertSame(3, $this->xpath($hasil)->query('//tr[not(@style="height: 0")]')->length);
        $this->assertSame($this->teksRapat($html), $this->teksRapat($hasil));
    }

    public function test_font_template_dipetakan_ke_padanan_metrik_dompdf(): void
    {
        $html = '<style>.x p.western { font-family: "Arial", serif; font-size: 11pt }</style>'
            .'<div class="x"><p class="western" style="font-family: \'Times New Roman\'">A</p><font face="Courier New">B</font><p style="font-family: Calibri">C</p></div>';

        $hasil = HtmlNotulaDompdf::siapkan($html, self::A4);

        $this->assertStringContainsString('font-family: "Arial", Helvetica, serif', $hasil);
        $this->assertStringContainsString("'Times New Roman', Times", $hasil);
        $this->assertStringContainsString('face="Courier New, Courier"', $hasil);
        // Calibri sudah didaftarkan lewat @font-face (Carlito) -- tidak diubah.
        $this->assertStringContainsString('font-family: Calibri"', $hasil);
        // <style> tetap di posisinya (tidak dipindah libxml ke <head>).
        $this->assertStringStartsWith('<style>', $hasil);
    }

    public function test_lebar_kolom_template_dipaksakan_lewat_baris_pengukur(): void
    {
        $html = '<table width="602"><col width="95"><col width="476">'
            .'<tr><td width="95">Agenda</td><td width="476">Isi</td></tr></table>';

        $hasil = HtmlNotulaDompdf::siapkan($html, self::A4);

        $pengukur = $this->xpath($hasil)->query('//tr[1]/td');
        $this->assertSame(2, $pengukur->length);
        $this->assertStringContainsString('width: 16.637%', $pengukur->item(0)->getAttribute('style'));
        $this->assertStringContainsString('width: 83.363%', $pengukur->item(1)->getAttribute('style'));
    }

    public function test_html_kosong_dikembalikan_apa_adanya(): void
    {
        $this->assertSame('', HtmlNotulaDompdf::siapkan('', self::A4));
    }
}
