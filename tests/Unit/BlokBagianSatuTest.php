<?php

namespace Tests\Unit;

use App\Services\NotulaService;
use App\Support\BlokBagianSatu;
use Tests\TestCase;

class BlokBagianSatuTest extends TestCase
{
    private function dokumen(string $isi): string
    {
        return '<style>.notula-inline-x p { margin: 0 }</style><div class="notula-inline notula-inline-x">'.$isi.'</div>';
    }

    private function penanda(string $kunci): string
    {
        return '<p class="western"><font>SIPINTERBLOK_'.$kunci.'</font></p>';
    }

    public function test_bungkus_mengubah_paragraf_penanda_jadi_div_blok(): void
    {
        $html = BlokBagianSatu::bungkus($this->dokumen(
            '<p>Judul</p>'.$this->penanda('iku-1').'<table><tr><td>A</td></tr></table>'.$this->penanda('ekor').'<p>Penutup</p>'
        ));

        $this->assertStringContainsString('<div data-blok="kepala"><p>Judul</p></div>', $html);
        $this->assertStringContainsString('<div data-blok="iku-1"><table>', $html);
        $this->assertStringContainsString('<div data-blok="ekor"><p>Penutup</p></div>', $html);
        $this->assertStringNotContainsString('SIPINTERBLOK_', $html);
    }

    public function test_bungkus_membuang_penanda_bila_tidak_di_tingkat_teratas(): void
    {
        $html = BlokBagianSatu::bungkus($this->dokumen(
            '<table><tr><td>'.$this->penanda('iku-1').'<p>A</p></td></tr></table>'
        ));

        $this->assertStringNotContainsString('data-blok', $html);
        $this->assertStringNotContainsString('SIPINTERBLOK_', $html);
    }

    public function test_gabungkan_memperbarui_yang_tidak_disunting_dan_mempertahankan_suntingan(): void
    {
        // Versi tersimpan dari editor: browser menyisipkan <tbody>; IKU 1 disunting di
        // baris pertama saja, IKU 2 tidak disunting, IKU 3 (disunting) & IKU 4 (tidak)
        // sudah tidak ada di data terbaru.
        $lama = $this->dokumen(
            '<div data-blok="kepala"><p>Rapat lama</p></div>'
            .'<div data-blok="iku-1" data-diedit="1"><table><tbody>'
            .'<tr data-diedit="1"><td>Analisis SUNTINGAN</td></tr>'
            .'<tr><td>Kendala lama</td></tr>'
            .'</tbody></table></div>'
            .'<div data-blok="iku-3" data-diedit="1"><p>IKU 3 SUNTINGAN</p></div>'
            .'<div data-blok="iku-2"><p>IKU 2 lama</p></div>'
            .'<div data-blok="iku-4"><p>IKU 4 lama</p></div>'
            .'<div data-blok="ekor"><p>Penutup</p></div>'
        );

        $baru = $this->dokumen(
            '<div data-blok="kepala"><p>Rapat baru</p></div>'
            .'<div data-blok="iku-1"><table>'
            .'<tr><td>Analisis otomatis</td></tr>'
            .'<tr><td>Kendala baru</td></tr>'
            .'</table></div>'
            .'<div data-blok="iku-2"><p>IKU 2 baru</p></div>'
            .'<div data-blok="iku-5"><p>IKU 5 baru</p></div>'
            .'<div data-blok="ekor"><p>Penutup</p></div>'
        );

        $hasil = BlokBagianSatu::gabungkan($lama, $baru);
        $html = $hasil['html'];

        $this->assertSame(2, $hasil['dipertahankan']);
        $this->assertStringStartsWith('<style>', $html);
        $this->assertStringContainsString('Rapat baru', $html);
        $this->assertStringContainsString('Analisis SUNTINGAN', $html);
        $this->assertStringNotContainsString('Analisis otomatis', $html);
        $this->assertStringContainsString('Kendala baru', $html);
        $this->assertStringNotContainsString('Kendala lama', $html);
        $this->assertStringContainsString('IKU 2 baru', $html);
        $this->assertStringContainsString('IKU 5 baru', $html);
        $this->assertStringContainsString('IKU 3 SUNTINGAN', $html);
        $this->assertStringNotContainsString('IKU 4 lama', $html);

        preg_match_all('/data-blok="([^"]+)"/', $html, $m);
        $this->assertSame(['kepala', 'iku-1', 'iku-3', 'iku-2', 'iku-5', 'ekor'], $m[1]);
    }

    public function test_bagian_dua_tiga_di_editor_dikembalikan_jadi_penanda_saat_disimpan(): void
    {
        $svc = app(NotulaService::class);
        $bagian1 = '<p>Isi</p><p class="western"><font><i>{{bagian_2}}</i></font></p><p><i>{{bagian_3}}</i></p>';

        $editor = $svc->sisipkanBagianDuaTiga($bagian1, '<div>ISI B2</div>', '<div>ISI B3</div>', untukEditor: true);
        $this->assertStringContainsString('contenteditable="false"><div>ISI B2</div>', $editor);
        $this->assertStringNotContainsString('<i><!--', $editor);

        $kembali = $svc->kembalikanPenandaBagianDuaTiga($editor);
        $this->assertSame('<p>Isi</p><p>{{bagian_2}}</p><p>{{bagian_3}}</p>', $kembali);
    }
}
