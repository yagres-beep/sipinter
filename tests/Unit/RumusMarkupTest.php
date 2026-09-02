<?php

namespace Tests\Unit;

use App\Support\RumusMarkup;
use Tests\TestCase;

class RumusMarkupTest extends TestCase
{
    public function test_keHtml_mengubah_pecahan_jadi_span_bersusun(): void
    {
        $html = RumusMarkup::keHtml('y = [[n|N]] x 100%');

        $this->assertStringContainsString('y = <span', $html);
        $this->assertStringContainsString('border-bottom:1px solid #000', $html);
        $this->assertStringContainsString('>n<', $html);
        $this->assertStringContainsString('>N<', $html);
        $this->assertStringContainsString('x 100%', $html);
    }

    public function test_keHtml_mengescape_html_di_luar_pecahan(): void
    {
        $html = RumusMarkup::keHtml('<script>alert(1)</script> [[a|b]]');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_keHtml_string_kosong_atau_null(): void
    {
        $this->assertSame('', RumusMarkup::keHtml(null));
        $this->assertSame('', RumusMarkup::keHtml(''));
    }

    public function test_keTeksPolos_meratakan_pecahan_jadi_notasi_biasa(): void
    {
        $this->assertSame('y = n/N x 100%', RumusMarkup::keTeksPolos('y = [[n|N]] x 100%'));
    }

    public function test_keTeksPolos_beberapa_pecahan_sekaligus(): void
    {
        $this->assertSame('n/N dan 5/90', RumusMarkup::keTeksPolos('[[n|N]] dan [[5|90]]'));
    }

    public function test_keTeksPolos_null_dan_tanpa_pecahan_apa_adanya(): void
    {
        $this->assertNull(RumusMarkup::keTeksPolos(null));
        $this->assertSame('teks biasa', RumusMarkup::keTeksPolos('teks biasa'));
    }

    public function test_keOmml_merakit_pecahan_bersusun_ooxml_math(): void
    {
        $omml = RumusMarkup::keOmml('y = [[n|N]] x 100%');

        $this->assertStringStartsWith('<m:oMath>', $omml);
        $this->assertStringEndsWith('</m:oMath>', $omml);
        $this->assertStringContainsString('<m:r><m:t xml:space="preserve">y = </m:t></m:r>', $omml);
        $this->assertStringContainsString('<m:f><m:fPr><m:type m:val="bar"/></m:fPr>', $omml);
        $this->assertStringContainsString('<m:num><m:r><m:t xml:space="preserve">n</m:t></m:r></m:num>', $omml);
        $this->assertStringContainsString('<m:den><m:r><m:t xml:space="preserve">N</m:t></m:r></m:den>', $omml);
        $this->assertStringContainsString('<m:r><m:t xml:space="preserve"> x 100%</m:t></m:r>', $omml);
    }

    public function test_keOmml_beberapa_pecahan_sekaligus(): void
    {
        $omml = RumusMarkup::keOmml('[[n|N]] dan [[5|90]]');

        $this->assertSame(2, substr_count($omml, '<m:f>'));
        $this->assertStringContainsString('<m:num><m:r><m:t xml:space="preserve">5</m:t></m:r></m:num>', $omml);
        $this->assertStringContainsString('<m:den><m:r><m:t xml:space="preserve">90</m:t></m:r></m:den>', $omml);
    }

    public function test_keOmml_mengescape_xml_di_luar_pecahan(): void
    {
        $omml = RumusMarkup::keOmml('<b>y</b> & [[a|b]]');

        $this->assertStringNotContainsString('<b>y</b>', $omml);
        $this->assertStringContainsString('&lt;b&gt;y&lt;/b&gt; &amp; ', $omml);
    }

    public function test_keOmml_null_dan_kosong(): void
    {
        $this->assertNull(RumusMarkup::keOmml(null));
        $this->assertSame('', RumusMarkup::keOmml(''));
    }

    public function test_keHtml_sigma_tersusun_batas_bawah_atas(): void
    {
        $html = RumusMarkup::keHtml('IPP = [[SUM:i=1,n|xi]]');

        $this->assertStringContainsString('&Sigma;', $html);
        $this->assertStringContainsString('>n<', $html);
        $this->assertStringContainsString('>i=1<', $html);
        $this->assertStringContainsString('</span>xi', $html);
    }

    public function test_keTeksPolos_sigma_diratakan_jadi_notasi_biasa(): void
    {
        $this->assertSame('IPP = Σ(i=1..n) xi', RumusMarkup::keTeksPolos('IPP = [[SUM:i=1,n|xi]]'));
    }

    public function test_keTeksPolos_sigma_dan_pecahan_sekaligus(): void
    {
        $this->assertSame(
            'w1 x Σ(i=1..n) xi + w2 x n/N',
            RumusMarkup::keTeksPolos('w1 x [[SUM:i=1,n|xi]] + w2 x [[n|N]]')
        );
    }

    public function test_keOmml_sigma_merakit_nary_ooxml_math(): void
    {
        $omml = RumusMarkup::keOmml('IPP = [[SUM:i=1,n|xi]]');

        $this->assertStringContainsString('<m:nary><m:naryPr><m:chr m:val="∑"/><m:limLoc m:val="subSup"/></m:naryPr>', $omml);
        $this->assertStringContainsString('<m:sub><m:r><m:t xml:space="preserve">i=1</m:t></m:r></m:sub>', $omml);
        $this->assertStringContainsString('<m:sup><m:r><m:t xml:space="preserve">n</m:t></m:r></m:sup>', $omml);
        $this->assertStringContainsString('<m:e><m:r><m:t xml:space="preserve">xi</m:t></m:r></m:e>', $omml);
        $this->assertStringNotContainsString('<m:f>', $omml);
    }

    public function test_keOmml_sigma_dan_pecahan_sekaligus(): void
    {
        $omml = RumusMarkup::keOmml('[[SUM:i=1,n|xi]] dan [[a|b]]');

        $this->assertStringContainsString('<m:nary>', $omml);
        $this->assertStringContainsString('<m:f>', $omml);
    }

    public function test_keOmml_sigma_mengescape_xml_di_batas_dan_suku(): void
    {
        $omml = RumusMarkup::keOmml('[[SUM:i=1,<b>n</b>|x&i]]');

        $this->assertStringContainsString('<m:sup><m:r><m:t xml:space="preserve">&lt;b&gt;n&lt;/b&gt;</m:t></m:r></m:sup>', $omml);
        $this->assertStringContainsString('<m:e><m:r><m:t xml:space="preserve">x&amp;i</m:t></m:r></m:e>', $omml);
    }
}
