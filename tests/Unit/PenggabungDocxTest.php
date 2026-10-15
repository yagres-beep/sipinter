<?php

namespace Tests\Unit;

use App\Support\PenggabungDocx;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class PenggabungDocxTest extends TestCase
{
    private const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

    /** @var list<string> */
    private array $berkas = [];

    protected function tearDown(): void
    {
        foreach ($this->berkas as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $part
     */
    private function buatDocx(array $part): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gabung_').'.docx';
        $this->berkas[] = $path;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($part as $nama => $isi) {
            $zip->addFromString($nama, $isi);
        }
        $zip->close();

        return $path;
    }

    private function contentTypes(string $tambahan = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            .$tambahan.'</Types>';
    }

    private function tujuan(): string
    {
        return $this->buatDocx([
            '[Content_Types].xml' => $this->contentTypes(),
            'word/_rels/document.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'word/styles.xml' => '<?xml version="1.0"?><w:styles '.self::W.'><w:docDefaults><w:rPrDefault><w:rPr><w:sz w:val="24"/></w:rPr></w:rPrDefault></w:docDefaults>'
                .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:rFonts w:ascii="Arial"/></w:rPr></w:style></w:styles>',
            'word/document.xml' => '<?xml version="1.0"?><w:document '.self::W.'><w:body>'
                .'<w:p><w:r><w:t>Bagian I</w:t></w:r></w:p>'
                .'<w:p><w:r><w:t>SIPINTERBAGIAN2X</w:t></w:r></w:p>'
                .'<w:p><w:r><w:t>Penutup</w:t></w:r></w:p>'
                .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="851" w:right="1440" w:bottom="1135" w:left="1440"/></w:sectPr>'
                .'</w:body></w:document>',
        ]);
    }

    private function sumber(): string
    {
        return $this->buatDocx([
            '[Content_Types].xml' => $this->contentTypes('<Default Extension="png" ContentType="image/png"/>'
                .'<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
                .'<Override PartName="/word/footnotes.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footnotes+xml"/>'),
            'word/_rels/document.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>'
                .'<Relationship Id="rId6" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://bps.go.id" TargetMode="External"/>'
                .'<Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>'
                .'</Relationships>',
            'word/media/image1.png' => 'PNG-PALSU',
            'word/styles.xml' => '<?xml version="1.0"?><w:styles '.self::W.'><w:docDefaults><w:rPrDefault><w:rPr><w:sz w:val="22"/></w:rPr></w:rPrDefault></w:docDefaults>'
                .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:rFonts w:ascii="Calibri"/></w:rPr></w:style>'
                .'<w:style w:type="paragraph" w:styleId="Judul"><w:name w:val="Judul"/><w:basedOn w:val="Normal"/><w:pPr><w:numPr><w:numId w:val="1"/></w:numPr></w:pPr></w:style>'
                .'<w:style w:type="table" w:default="1" w:styleId="TabelBiasa"><w:name w:val="Normal Table"/></w:style></w:styles>',
            'word/numbering.xml' => '<?xml version="1.0"?><w:numbering '.self::W.'><w:abstractNum w:abstractNumId="0"><w:nsid w:val="11111111"/><w:lvl w:ilvl="0"><w:numFmt w:val="decimal"/></w:lvl></w:abstractNum>'
                .'<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num></w:numbering>',
            'word/footnotes.xml' => '<?xml version="1.0"?><w:footnotes '.self::W.'><w:footnote w:type="separator" w:id="-1"><w:p/></w:footnote><w:footnote w:type="continuationSeparator" w:id="0"><w:p/></w:footnote>'
                .'<w:footnote w:id="1"><w:p><w:r><w:t>Catatan kaki sumber</w:t></w:r></w:p></w:footnote></w:footnotes>',
            'word/document.xml' => '<?xml version="1.0"?><w:document '.self::W.' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" mc:Ignorable="w14"><w:body>'
                .'<w:p w14:paraId="1A2B3C4D"><w:pPr><w:pStyle w:val="Judul"/></w:pPr><w:r><w:t>Peran BPS dalam Prioritas Nasional</w:t></w:r><w:r><w:footnoteReference w:id="1"/></w:r></w:p>'
                .'<w:p><w:r><w:t>Paragraf tanpa gaya</w:t></w:r><w:commentRangeStart w:id="0"/><w:hyperlink r:id="rId6"><w:r><w:t>tautan</w:t></w:r></w:hyperlink></w:p>'
                .'<w:p><w:r><w:drawing><wp:inline><wp:docPr id="1" name="Gambar"/><a:graphic><a:graphicData><a:blip r:embed="rId5"/></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>'
                .'<w:tbl><w:tblPr/><w:tr><w:tc><w:p><w:r><w:t>Sel tabel</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
                .'<w:sectPr><w:headerReference w:type="default" r:id="rId7"/><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="1000" w:right="1000" w:bottom="1000" w:left="1000"/></w:sectPr>'
                .'</w:body></w:document>',
        ]);
    }

    private function baca(string $docx, string $part): string
    {
        $zip = new ZipArchive;
        $zip->open($docx);
        $isi = $zip->getFromName($part);
        $zip->close();

        return $isi === false ? '' : $isi;
    }

    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml), 'Part hasil gabungan harus XML yang sah.');
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        return $xp;
    }

    public function test_isi_sumber_disisipkan_di_penanda_dengan_gaya_penomoran_relasi_dan_halaman_sumber(): void
    {
        $tujuan = $this->tujuan();

        (new PenggabungDocx)->sisipkan($tujuan, ['SIPINTERBAGIAN2X' => $this->sumber()]);

        $doc = $this->baca($tujuan, 'word/document.xml');
        $xp = $this->xpath($doc);
        $teks = implode('|', array_map(fn ($t) => $t->textContent, iterator_to_array($xp->query('//w:body/w:p//w:t | //w:body/w:tbl//w:t'))));

        // Urutan: Bagian I -> isi sumber (di posisi penanda) -> Penutup; penanda hilang.
        $this->assertStringNotContainsString('SIPINTERBAGIAN2X', $doc);
        $this->assertMatchesRegularExpression('/Bagian I\|Peran BPS.*Paragraf tanpa gaya\|tautan.*Sel tabel\|Penutup/', $teks);

        // Gaya sumber disalin dengan ID baru (tidak memakai "Normal" Bagian I).
        $this->assertSame('SipBag1Judul', $xp->query('//w:p[.//w:t="Peran BPS dalam Prioritas Nasional"]/w:pPr/w:pStyle/@w:val')->item(0)->value);
        $this->assertSame('SipBag1Normal', $xp->query('//w:p[.//w:t="Paragraf tanpa gaya"]/w:pPr/w:pStyle/@w:val')->item(0)->value);
        $this->assertSame('SipBag1TabelBiasa', $xp->query('//w:tbl/w:tblPr/w:tblStyle/@w:val')->item(0)->value);

        $styles = $this->baca($tujuan, 'word/styles.xml');
        $xpS = $this->xpath($styles);
        $this->assertSame(1, $xpS->query('//w:style[@w:default="1"][@w:type="paragraph"]')->length, 'Gaya bawaan tujuan tetap satu-satunya bawaan.');
        $this->assertSame('Arial', $xpS->query('//w:style[@w:styleId="Normal"]//w:rFonts/@w:ascii')->item(0)->value);
        $this->assertSame('Calibri', $xpS->query('//w:style[@w:styleId="SipBag1Normal"]//w:rFonts/@w:ascii')->item(0)->value);
        // docDefaults sumber (sz 22) dilebur ke gaya bawaan salinan.
        $this->assertSame('22', $xpS->query('//w:style[@w:styleId="SipBag1Normal"]/w:rPr/w:sz/@w:val')->item(0)->value);
        $this->assertSame('SipBag1Normal', $xpS->query('//w:style[@w:styleId="SipBag1Judul"]/w:basedOn/@w:val')->item(0)->value);

        // Penomoran dibuat (tujuan belum punya numbering.xml) & dirujuk gaya salinan.
        $xpN = $this->xpath($this->baca($tujuan, 'word/numbering.xml'));
        $numId = $xpS->query('//w:style[@w:styleId="SipBag1Judul"]//w:numId/@w:val')->item(0)->value;
        $this->assertSame(1, $xpN->query("//w:num[@w:numId='{$numId}']")->length);
        $this->assertStringContainsString('numbering.xml', $this->baca($tujuan, 'word/_rels/document.xml.rels'));
        $this->assertStringContainsString('/word/numbering.xml', $this->baca($tujuan, '[Content_Types].xml'));

        // Gambar & tautan ikut, dengan relasi baru; header sumber tidak ikut.
        $rels = $this->baca($tujuan, 'word/_rels/document.xml.rels');
        $this->assertSame('PNG-PALSU', $this->baca($tujuan, 'word/media/sipbag1_image1.png'));
        $this->assertStringContainsString('Target="media/sipbag1_image1.png"', $rels);
        $this->assertStringContainsString('Target="https://bps.go.id"', $rels);
        $this->assertStringNotContainsString('header1.xml', $rels);
        $this->assertStringNotContainsString('headerReference', $doc);
        $this->assertStringContainsString('Extension="png"', $this->baca($tujuan, '[Content_Types].xml'));
        preg_match('/r:embed="([^"]+)"/', $doc, $embed);
        $this->assertStringContainsString('Id="'.$embed[1].'"', $rels);

        // Catatan kaki ikut; komentar dibuang.
        $this->assertStringContainsString('Catatan kaki sumber', $this->baca($tujuan, 'word/footnotes.xml'));
        $this->assertStringNotContainsString('commentRangeStart', $doc);

        // Section: Bagian I tetap A4 tegak, isi sumber memakai halaman lanskap sumber.
        $sect = iterator_to_array($xp->query('//w:sectPr'));
        $this->assertCount(3, $sect);
        $this->assertSame('11906', $xp->query('w:pgSz/@w:w', $sect[0])->item(0)->value);
        $this->assertSame('landscape', $xp->query('w:pgSz/@w:orient', $sect[1])->item(0)->value);
        $this->assertSame('11906', $xp->query('w:pgSz/@w:w', $sect[2])->item(0)->value);

        // Namespace w14 milik sumber dideklarasikan di akar tujuan & ikut mc:Ignorable.
        $this->assertMatchesRegularExpression('/<w:document[^>]*xmlns:w14=/', $doc);
        $this->assertMatchesRegularExpression('/<w:document[^>]*mc:Ignorable="[^"]*w14/', $doc);
    }

    public function test_penanda_bersebelahan_tidak_menghasilkan_section_kosong(): void
    {
        $tujuan = $this->buatDocx([
            '[Content_Types].xml' => $this->contentTypes(),
            'word/_rels/document.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'word/styles.xml' => '<?xml version="1.0"?><w:styles '.self::W.'/>',
            'word/document.xml' => '<?xml version="1.0"?><w:document '.self::W.'><w:body>'
                .'<w:p><w:r><w:t>Bagian I</w:t></w:r></w:p>'
                .'<w:p><w:r><w:t>SIPINTERBAGIAN2X</w:t></w:r></w:p>'
                .'<w:p><w:r><w:t>SIPINTERBAGIAN3X</w:t></w:r></w:p>'
                .'<w:p><w:r><w:t>Penutup</w:t></w:r></w:p>'
                .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/></w:sectPr>'
                .'</w:body></w:document>',
        ]);

        (new PenggabungDocx)->sisipkan($tujuan, ['SIPINTERBAGIAN2X' => $this->sumber(), 'SIPINTERBAGIAN3X' => $this->sumber()]);

        $xp = $this->xpath($this->baca($tujuan, 'word/document.xml'));

        // Bagian I | Bagian II | Bagian III | Penutup = 4 section, tanpa section kosong
        // di antara Bagian II & III (Word mencetaknya sebagai halaman kosong).
        $this->assertCount(4, iterator_to_array($xp->query('//w:sectPr')));
        foreach ($xp->query('//w:body/w:p[w:pPr/w:sectPr]') as $penutupSection) {
            $sebelumnya = $penutupSection->previousSibling;
            $this->assertFalse(
                $sebelumnya !== null && $xp->query('w:pPr/w:sectPr', $sebelumnya)->length > 0,
                'Dua section break berturut-turut = section kosong.'
            );
        }
        $teks = implode('|', array_map(fn ($t) => $t->textContent, iterator_to_array($xp->query('//w:t'))));
        $this->assertMatchesRegularExpression('/^Bagian I\|Peran BPS.*\|Peran BPS.*\|Penutup$/', $teks);
    }

    public function test_penanda_tidak_ditemukan_isi_ditempel_di_akhir_dan_dua_sumber_tidak_bentrok(): void
    {
        $tujuan = $this->tujuan();

        (new PenggabungDocx)->sisipkan($tujuan, ['SIPINTERBAGIAN2X' => $this->sumber(), 'SIPINTERBAGIAN3X' => $this->sumber()]);

        $xp = $this->xpath($this->baca($tujuan, 'word/document.xml'));
        $this->assertSame(2, $xp->query('//w:t[.="Peran BPS dalam Prioritas Nasional"]')->length);
        // Penanda Bagian III tidak ada: isinya ditempel SETELAH "Penutup" (akhir dokumen).
        $teks = implode('|', array_map(fn ($t) => $t->textContent, iterator_to_array($xp->query('//w:t'))));
        $this->assertMatchesRegularExpression('/Bagian I\|Peran BPS.*Penutup\|Peran BPS/', $teks);

        $xpS = $this->xpath($this->baca($tujuan, 'word/styles.xml'));
        $this->assertSame(1, $xpS->query('//w:style[@w:styleId="SipBag1Judul"]')->length);
        $this->assertSame(1, $xpS->query('//w:style[@w:styleId="SipBag2Judul"]')->length);

        $xpN = $this->xpath($this->baca($tujuan, 'word/numbering.xml'));
        $this->assertSame(2, $xpN->query('//w:num')->length);
        $this->assertSame(2, count(array_unique(array_map(fn ($a) => $a->value, iterator_to_array($xpN->query('//w:num/@w:numId'))))));

        $xpF = $this->xpath($this->baca($tujuan, 'word/footnotes.xml'));
        $this->assertSame(2, $xpF->query('//w:footnote[.//w:t="Catatan kaki sumber"]')->length);
    }
}
