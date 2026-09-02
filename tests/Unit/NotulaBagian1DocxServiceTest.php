<?php

namespace Tests\Unit;

use App\Services\NotulaBagian1DocxService;
use ReflectionMethod;
use Tests\TestCase;

class NotulaBagian1DocxServiceTest extends TestCase
{
    /**
     * Baris header tabel Sasaran/Triwulan pada template resmi punya <w:trPr> SENDIRI
     * (mis. <w:trPr><w:jc w:val="center"/></w:trPr>, dipakai supaya sel vMerge/gridSpan-nya
     * rata tengah) -- cegahBarisTerpotongAntarHalaman() TIDAK BOLEH menambah <w:trPr> KEDUA
     * di baris seperti ini (skema OOXML cuma mengizinkan satu <w:trPr> per <w:tr>; dua trPr
     * bersebelahan membuat Word/LibreOffice merusak susunan sel gabungan baris tsb saat
     * memulihkan XML yang tidak valid -- persis tabel header yang tampak berantakan di
     * pratinjau Kompilasi Notula walau template aslinya benar). <w:cantSplit/> harus
     * disisipkan sebagai ANAK di dalam <w:trPr> yang sudah ada, isi trPr lain (mis. <w:jc>)
     * tetap dipertahankan.
     */
    public function test_baris_dengan_trPr_sendiri_tidak_digandakan_trPr_nya(): void
    {
        $service = new NotulaBagian1DocxService;
        $templatePath = base_path('template_notula/SIPINTER_Template_Bagian_I_Mesin.docx');

        $newTemplateProcessor = new ReflectionMethod($service, 'newTemplateProcessor');
        $newTemplateProcessor->setAccessible(true);
        $processor = $newTemplateProcessor->invoke($service, $templatePath);

        $setMainPart = new ReflectionMethod($service, 'setMainPart');
        $setMainPart->setAccessible(true);
        $getMainPart = new ReflectionMethod($service, 'getMainPart');
        $getMainPart->setAccessible(true);

        $xmlAsli = '<w:tbl>'
            .'<w:tr w:rsidR="00486B9D"><w:trPr><w:jc w:val="center"/></w:trPr>'
            .'<w:tc><w:p><w:r><w:t>Sasaran</w:t></w:r></w:p></w:tc></w:tr>'
            .'<w:tr><w:tc><w:p><w:r><w:t>Baris tanpa trPr</w:t></w:r></w:p></w:tc></w:tr>'
            .'</w:tbl>';
        $setMainPart->invoke($service, $processor, $xmlAsli);

        $cegah = new ReflectionMethod($service, 'cegahBarisTerpotongAntarHalaman');
        $cegah->setAccessible(true);
        $cegah->invoke($service, $processor);

        $hasil = $getMainPart->invoke($service, $processor);

        $this->assertSame(2, substr_count($hasil, '<w:trPr>'), 'Setiap <w:tr> cuma boleh punya SATU <w:trPr>, walau sebagian sudah punya trPr sendiri sebelumnya.');
        $this->assertSame(2, substr_count($hasil, '<w:cantSplit/>'));
        $this->assertStringContainsString('<w:trPr><w:cantSplit/><w:jc w:val="center"/></w:trPr>', $hasil, 'cantSplit harus disisipkan KE DALAM trPr yang sudah ada, bukan menambah trPr baru.');
        $this->assertStringContainsString('<w:tr><w:trPr><w:cantSplit/></w:trPr>', $hasil, 'Baris yang belum punya trPr tetap ditandai seperti biasa.');
    }
}
