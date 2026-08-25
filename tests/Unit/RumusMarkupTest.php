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
}
