<?php

namespace Tests\Unit;

use App\Services\LibreOfficeConversionService;
use ReflectionMethod;
use Tests\TestCase;

class LibreOfficeConversionServiceTest extends TestCase
{
    /**
     * Reproduksi bug: tabel "Capaian Kinerja" Bagian I punya baris header "Sasaran"
     * (kolom digabung colspan, cuma 3 sel fisik) sebagai baris PERTAMA, lalu baris
     * "No./Indikator Kinerja/.../Capaian Terhadap Target PK" dengan 7 sel penuh --
     * lihat tblGrid 7 kolom di template_notula/SIPINTER_Template_Bagian_I_Mesin.docx.
     * LibreOffice mengekspor tiap baris sebagai <tbody>+<colgroup> TERPISAH (artefak
     * yang sama dipecahkan gabungkanTbodyBersebelahan() untuk kasus tbody) -- kalau
     * <colgroup> baris "Sasaran" (cuma 3 <col>, lebar kasar) yang menang alih-alih
     * <colgroup> baris berikutnya (7 <col>, lebar asli), Chrome/Blink cuma tahu 3
     * lebar kolom untuk SELURUH tabel, bikin 7 sel baris kedua diperas jadi sangat
     * sempit (teks terpotong huruf per huruf) walau baris Sasaran sendiri tampil
     * normal -- persis laporan pengguna: Bagian I rusak, Bagian II/III (tabel lebih
     * sederhana, satu colgroup saja) tetap rapi.
     */
    public function test_colgroup_tersebar_di_banyak_tbody_disatukan_jadi_paling_rinci(): void
    {
        $html = '<html><head><style>table{table-layout:fixed}</style></head><body>'
            .'<table>'
            .'<colgroup><col style="width:76px"><col style="width:473px"><col style="width:76px"></colgroup>'
            .'<tbody><tr><td>Sasaran</td><td colspan="5">: Terwujudnya ...</td><td></td></tr></tbody>'
            .'<colgroup><col style="width:76px"><col style="width:144px"><col style="width:67px"><col style="width:67px"><col style="width:88px"><col style="width:106px"><col style="width:76px"></colgroup>'
            .'<tbody><tr><td>No.</td><td>Indikator Kinerja</td><td>Target PK 2026</td><td colspan="3">Triwulan III</td><td>Capaian Terhadap Target PK</td></tr></tbody>'
            .'</table>'
            .'</body></html>';

        $service = new LibreOfficeConversionService;
        $method = new ReflectionMethod($service, 'ekstrakKontenInline');
        $method->setAccessible(true);
        $hasil = $method->invoke($service, $html, sys_get_temp_dir());

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$hasil, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $table = $dom->getElementsByTagName('table')->item(0);
        $this->assertNotNull($table);

        $colgroups = $table->getElementsByTagName('colgroup');
        $this->assertSame(1, $colgroups->length, 'Harus tersisa cuma SATU colgroup per tabel.');
        $this->assertSame(7, $colgroups->item(0)->getElementsByTagName('col')->length, 'Colgroup yang dipertahankan harus yang paling rinci (7 kolom asli), bukan versi baris Sasaran yang cuma 3.');

        // colgroup harus jadi anak PERTAMA <table>, sebelum <tbody> mana pun --
        // posisi ini yang membuat parser HTML5 (Chrome) mengenalinya sebagai
        // definisi kolom tabel yang sah.
        $anakPertama = $table->firstChild;
        while ($anakPertama !== null && ! ($anakPertama instanceof \DOMElement)) {
            $anakPertama = $anakPertama->nextSibling;
        }
        $this->assertSame('colgroup', strtolower($anakPertama->tagName));
    }
}
