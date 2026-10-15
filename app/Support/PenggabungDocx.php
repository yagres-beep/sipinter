<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Sisipkan ISI .docx lain (Bagian II/III yang diunggah Tim SAKIP) APA ADANYA ke dalam
 * .docx notula, di posisi paragraf penanda -- penggabungan di level OOXML, BUKAN lewat
 * konversi HTML (yang selama ini merusak tabel, penomoran & tata letak unggahan).
 *
 * Supaya tampilan isi sisipan tidak berubah setelah masuk ke dokumen lain:
 * - Gaya (styles.xml) sumber disalin dengan ID & nama BARU berawalan unik, jadi tidak
 *   bentrok dengan gaya bernama sama milik dokumen tujuan (mis. "Normal" Bagian II
 *   tidak ikut memakai "Normal" Bagian I). docDefaults sumber dilebur ke gaya paragraf
 *   bawaannya, dan paragraf/tabel tanpa gaya eksplisit diarahkan ke gaya bawaan sumber.
 * - Penomoran (numbering.xml) disalin dengan abstractNumId/numId digeser.
 * - Relasi (gambar, tautan, grafik, dst.) disalin beserta part-nya dengan nama baru.
 * - Catatan kaki/akhir disalin dengan ID digeser; komentar dibuang.
 * - Pengaturan halaman (ukuran/orientasi/margin) sumber DIPERTAHANKAN lewat section
 *   break di sekitar isi sisipan; header/footer sumber tidak ikut (dokumen tujuan
 *   punya header/footer sendiri).
 */
class PenggabungDocx
{
    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_WP = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';

    private const NS_MC = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

    private const NS_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const NS_CT = 'http://schemas.openxmlformats.org/package/2006/content-types';

    private const NS_XMLNS = 'http://www.w3.org/2000/xmlns/';

    private const TIPE_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

    private const CT_NUMBERING = 'application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml';

    private const CT_FOOTNOTES = 'application/vnd.openxmlformats-officedocument.wordprocessingml.footnotes+xml';

    private const CT_ENDNOTES = 'application/vnd.openxmlformats-officedocument.wordprocessingml.endnotes+xml';

    private ZipArchive $tujuan;

    /** @var array<string, DOMDocument> part tujuan yang sudah dibuka (nama part => DOM) */
    private array $partTujuan = [];

    private int $urutanSumber = 0;

    /** @var list<string> teks penanda seluruh sisipan pada panggilan sisipkan() ini */
    private array $semuaPenanda = [];

    /**
     * @param  array<string, string>  $sisipan  teks penanda paragraf => path .docx sumber,
     *                                          diproses berurutan. Penanda yang tidak ditemukan
     *                                          ditempel di akhir dokumen.
     */
    public function sisipkan(string $pathTujuan, array $sisipan): void
    {
        $this->tujuan = new ZipArchive;
        if ($this->tujuan->open($pathTujuan) !== true) {
            throw new RuntimeException('Dokumen notula (.docx) tidak bisa dibuka untuk digabung.');
        }

        $this->partTujuan = [];
        $this->semuaPenanda = array_map('strval', array_keys($sisipan));

        try {
            foreach ($sisipan as $penanda => $pathSumber) {
                $this->sisipkanSatu($penanda, $pathSumber);
            }

            foreach ($this->partTujuan as $nama => $dom) {
                $this->tujuan->addFromString($nama, $dom->saveXML());
            }
        } finally {
            $this->tujuan->close();
        }
    }

    private function sisipkanSatu(string $penanda, string $pathSumber): void
    {
        $sumber = new ZipArchive;
        if ($sumber->open($pathSumber) !== true) {
            throw new RuntimeException('Berkas unggahan (.docx) tidak bisa dibuka untuk digabung: '.basename($pathSumber));
        }

        try {
            $this->urutanSumber++;
            $awalan = 'SipBag'.$this->urutanSumber;

            $docSumber = $this->muatXml($sumber->getFromName('word/document.xml'), 'word/document.xml sumber');
            $xpSumber = $this->xpath($docSumber);
            $bodySumber = $xpSumber->query('/w:document/w:body')->item(0);
            if (! $bodySumber instanceof DOMElement) {
                throw new RuntimeException('Berkas unggahan (.docx) tidak memiliki isi dokumen.');
            }

            $docTujuan = $this->part('word/document.xml');
            $xpTujuan = $this->xpath($docTujuan);
            $bodyTujuan = $xpTujuan->query('/w:document/w:body')->item(0);

            $this->salinDeklarasiNamespace($docSumber->documentElement, $docTujuan->documentElement);

            $peta = $this->gabungGaya($sumber, $awalan);
            $petaNum = $this->gabungPenomoran($sumber, $peta['gaya']);
            $relSumber = $this->bacaRelasi($sumber, 'word/_rels/document.xml.rels');
            $petaRel = [];
            $petaCatatan = [
                'footnote' => $this->gabungCatatan($sumber, $docSumber, 'footnote', $peta['gaya'], $petaNum),
                'endnote' => $this->gabungCatatan($sumber, $docSumber, 'endnote', $peta['gaya'], $petaNum),
            ];

            // Section terakhir sumber (pengaturan halaman unggahan) -- dipakai untuk
            // section break SETELAH isi sisipan, supaya ukuran/margin unggahan tetap.
            $sectAkhirSumber = null;
            $isi = [];
            foreach (iterator_to_array($bodySumber->childNodes) as $anak) {
                if ($anak instanceof DOMElement && $anak->namespaceURI === self::NS_W && $anak->localName === 'sectPr') {
                    $sectAkhirSumber = $anak;

                    continue;
                }
                $isi[] = $anak;
            }

            $offsetBookmark = $this->maksAtribut($xpTujuan, '//w:bookmarkStart/@w:id') + 1;
            $offsetDocPr = $this->maksAtribut($xpTujuan, '//wp:docPr/@id') + 1;

            $impor = [];
            foreach ($isi as $node) {
                $baru = $docTujuan->importNode($node, true);
                $this->rapikanNode($baru, $peta, $petaNum, $sumber, $relSumber, $petaRel, $petaCatatan, $offsetBookmark, $offsetDocPr);
                $impor[] = $baru;
            }

            $sectTujuan = $xpTujuan->query('w:sectPr', $bodyTujuan)->item(0);
            $titik = $this->cariParagrafPenanda($xpTujuan, $bodyTujuan, $penanda);

            // Section break penutup isi tujuan SEBELUM sisipan -- dilewati bila sebelum
            // titik sisip tidak ada isi lagi sejak section break terakhir (mis. tepat
            // setelah sisipan Bagian II). Section kosong seperti itu dicetak Word sebagai
            // halaman kosong bila ukuran kertasnya beda dengan section di sekitarnya.
            $sebelum = null;
            if ($sectTujuan instanceof DOMElement && $this->adaIsiSebelum($titik, $sectTujuan)) {
                $sebelum = $docTujuan->createElementNS(self::NS_W, 'w:p');
                $pPr = $sebelum->appendChild($docTujuan->createElementNS(self::NS_W, 'w:pPr'));
                $pPr->appendChild($sectTujuan->cloneNode(true));
            }

            $sesudah = null;
            if ($sectAkhirSumber !== null) {
                $sectBaru = $docTujuan->importNode($sectAkhirSumber, true);
                $this->rapikanSectPr($sectBaru);
                $sesudah = $docTujuan->createElementNS(self::NS_W, 'w:p');
                $pPr = $sesudah->appendChild($docTujuan->createElementNS(self::NS_W, 'w:pPr'));
                $pPr->appendChild($sectBaru);
            }

            $rangkaian = array_values(array_filter([$sebelum, ...$impor, $sesudah]));

            if ($titik !== null) {
                foreach ($rangkaian as $node) {
                    $titik->parentNode->insertBefore($node, $titik);
                }
                $titik->parentNode->removeChild($titik);
            } else {
                foreach ($rangkaian as $node) {
                    $bodyTujuan->insertBefore($node, $sectTujuan);
                }
            }

            // Bagian dokumen tujuan SETELAH sisipan mulai menyambung di halaman yang
            // sama (bukan dipaksa halaman baru) bila ukuran halamannya sama.
            if ($sectTujuan instanceof DOMElement && $sesudah !== null) {
                $this->setTipeSection($sectTujuan, 'continuous');
            }
        } finally {
            $sumber->close();
        }
    }

    /**
     * Salin gaya sumber dengan ID/nama baru, lalu kembalikan petanya.
     *
     * @return array{gaya: array<string, string>, paragrafBawaan: ?string, tabelBawaan: ?string}
     */
    private function gabungGaya(ZipArchive $sumber, string $awalan): array
    {
        $hasil = ['gaya' => [], 'paragrafBawaan' => null, 'tabelBawaan' => null];

        $xml = $sumber->getFromName('word/styles.xml');
        if ($xml === false) {
            return $hasil;
        }

        $stylesSumber = $this->muatXml($xml, 'styles.xml sumber');
        $xpS = $this->xpath($stylesSumber);
        $stylesTujuan = $this->part('word/styles.xml', fn () => $this->xmlKosong('w:styles'));
        $this->salinDeklarasiNamespace($stylesSumber->documentElement, $stylesTujuan->documentElement);

        $daftar = iterator_to_array($xpS->query('/w:styles/w:style'));
        foreach ($daftar as $style) {
            $id = $style->getAttributeNS(self::NS_W, 'styleId');
            if ($id !== '') {
                $hasil['gaya'][$id] = $awalan.$id;
            }
        }

        $docDefaults = $xpS->query('/w:styles/w:docDefaults')->item(0);

        foreach ($daftar as $style) {
            $id = $style->getAttributeNS(self::NS_W, 'styleId');
            if ($id === '') {
                continue;
            }

            $tipe = $style->getAttributeNS(self::NS_W, 'type');
            $bawaan = in_array($style->getAttributeNS(self::NS_W, 'default'), ['1', 'true', 'on'], true);

            $baru = $stylesTujuan->importNode($style, true);
            $baru->removeAttributeNS(self::NS_W, 'default');
            $baru->setAttributeNS(self::NS_W, 'w:styleId', $hasil['gaya'][$id]);

            $xpBaru = $this->xpath($stylesTujuan);
            foreach (['w:basedOn', 'w:next', 'w:link'] as $tag) {
                foreach ($xpBaru->query($tag, $baru) as $ref) {
                    $lama = $ref->getAttributeNS(self::NS_W, 'val');
                    if (isset($hasil['gaya'][$lama])) {
                        $ref->setAttributeNS(self::NS_W, 'w:val', $hasil['gaya'][$lama]);
                    } else {
                        $ref->parentNode->removeChild($ref);
                    }
                }
            }

            $nama = $xpBaru->query('w:name', $baru)->item(0);
            if ($nama instanceof DOMElement) {
                $nama->setAttributeNS(self::NS_W, 'w:val', $awalan.' '.$nama->getAttributeNS(self::NS_W, 'val'));
            }

            // Gaya salinan tidak boleh disembunyikan/dianggap bawaan Word.
            foreach (['w:semiHidden', 'w:unhideWhenUsed'] as $tag) {
                foreach (iterator_to_array($xpBaru->query($tag, $baru)) as $el) {
                    $el->parentNode->removeChild($el);
                }
            }

            if ($bawaan && $tipe === 'paragraph') {
                $hasil['paragrafBawaan'] = $hasil['gaya'][$id];
                if ($docDefaults instanceof DOMElement) {
                    $this->leburDocDefaults($stylesTujuan, $baru, $docDefaults);
                }
            }
            if ($bawaan && $tipe === 'table') {
                $hasil['tabelBawaan'] = $hasil['gaya'][$id];
            }

            // numId di dalam gaya salinan digeser di gabungPenomoran().
            $stylesTujuan->documentElement->appendChild($baru);
        }

        // Sumber tanpa gaya paragraf bawaan: buat satu dari docDefaults-nya saja.
        if ($hasil['paragrafBawaan'] === null && $docDefaults instanceof DOMElement) {
            $baru = $stylesTujuan->createElementNS(self::NS_W, 'w:style');
            $baru->setAttributeNS(self::NS_W, 'w:type', 'paragraph');
            $baru->setAttributeNS(self::NS_W, 'w:styleId', $awalan.'Bawaan');
            $nama = $baru->appendChild($stylesTujuan->createElementNS(self::NS_W, 'w:name'));
            $nama->setAttributeNS(self::NS_W, 'w:val', $awalan.' Bawaan');
            $this->leburDocDefaults($stylesTujuan, $baru, $docDefaults);
            $stylesTujuan->documentElement->appendChild($baru);
            $hasil['paragrafBawaan'] = $awalan.'Bawaan';
        }

        return $hasil;
    }

    /**
     * Masukkan rPr/pPr docDefaults sumber ke gaya paragraf bawaan salinan -- properti
     * yang sudah ditetapkan gaya itu sendiri tetap menang.
     */
    private function leburDocDefaults(DOMDocument $styles, DOMElement $style, DOMElement $docDefaults): void
    {
        $xp = $this->xpath($docDefaults->ownerDocument);

        foreach (['pPr' => 'w:pPrDefault/w:pPr', 'rPr' => 'w:rPrDefault/w:rPr'] as $tag => $jalur) {
            $bawaan = $xp->query($jalur, $docDefaults)->item(0);
            if (! $bawaan instanceof DOMElement) {
                continue;
            }

            $milik = null;
            foreach ($style->childNodes as $anak) {
                if ($anak instanceof DOMElement && $anak->localName === $tag && $anak->namespaceURI === self::NS_W) {
                    $milik = $anak;
                }
            }
            if ($milik === null) {
                $milik = $style->appendChild($styles->createElementNS(self::NS_W, 'w:'.$tag));
            }

            $ada = [];
            foreach ($milik->childNodes as $anak) {
                if ($anak instanceof DOMElement) {
                    $ada[$anak->localName] = true;
                }
            }

            foreach ($bawaan->childNodes as $anak) {
                if ($anak instanceof DOMElement && ! isset($ada[$anak->localName])) {
                    $milik->appendChild($styles->importNode($anak, true));
                }
            }

            $this->urutkanAnak($milik, $tag === 'rPr' ? self::URUTAN_RPR : self::URUTAN_PPR);
        }
    }

    /**
     * Salin abstractNum/num sumber dengan ID digeser. Mengembalikan peta numId lama => baru.
     *
     * @param  array<string, string>  $petaGaya
     * @return array<string, string>
     */
    private function gabungPenomoran(ZipArchive $sumber, array $petaGaya): array
    {
        $xml = $sumber->getFromName('word/numbering.xml');
        if ($xml === false) {
            return [];
        }

        $numSumber = $this->muatXml($xml, 'numbering.xml sumber');
        $xpS = $this->xpath($numSumber);

        $numTujuan = $this->part('word/numbering.xml', function () {
            $this->tambahRelasiDokumen('numbering', 'numbering.xml');
            $this->tambahOverride('/word/numbering.xml', self::CT_NUMBERING);

            return $this->xmlKosong('w:numbering');
        });
        $this->salinDeklarasiNamespace($numSumber->documentElement, $numTujuan->documentElement);
        $xpT = $this->xpath($numTujuan);

        $offsetAbs = $this->maksAtribut($xpT, '/w:numbering/w:abstractNum/@w:abstractNumId') + 1;
        $offsetNum = $this->maksAtribut($xpT, '/w:numbering/w:num/@w:numId') + 1;

        $petaNum = [];
        $akarT = $numTujuan->documentElement;
        $numPertama = $xpT->query('/w:numbering/w:num | /w:numbering/w:numIdMacAtCleanup')->item(0);
        $cleanup = $xpT->query('/w:numbering/w:numIdMacAtCleanup')->item(0);

        foreach ($xpS->query('/w:numbering/w:abstractNum') as $abs) {
            $baru = $numTujuan->importNode($abs, true);
            $baru->setAttributeNS(self::NS_W, 'w:abstractNumId', (string) ((int) $abs->getAttributeNS(self::NS_W, 'abstractNumId') + $offsetAbs));
            $xpB = $this->xpath($numTujuan);
            foreach ($xpB->query('w:nsid', $baru) as $nsid) {
                $nsid->setAttributeNS(self::NS_W, 'w:val', strtoupper(bin2hex(random_bytes(4))));
            }
            foreach (iterator_to_array($xpB->query('.//w:lvlPicBulletId', $baru)) as $el) {
                $el->parentNode->removeChild($el);
            }
            foreach ($xpB->query('w:styleLink | w:numStyleLink | .//w:pStyle', $baru) as $ref) {
                $lama = $ref->getAttributeNS(self::NS_W, 'val');
                if (isset($petaGaya[$lama])) {
                    $ref->setAttributeNS(self::NS_W, 'w:val', $petaGaya[$lama]);
                }
            }
            $numPertama !== null ? $akarT->insertBefore($baru, $numPertama) : $akarT->appendChild($baru);
        }

        foreach ($xpS->query('/w:numbering/w:num') as $num) {
            $lama = $num->getAttributeNS(self::NS_W, 'numId');
            $petaNum[$lama] = (string) ((int) $lama + $offsetNum);

            $baru = $numTujuan->importNode($num, true);
            $baru->setAttributeNS(self::NS_W, 'w:numId', $petaNum[$lama]);
            foreach ($this->xpath($numTujuan)->query('w:abstractNumId', $baru) as $ref) {
                $ref->setAttributeNS(self::NS_W, 'w:val', (string) ((int) $ref->getAttributeNS(self::NS_W, 'val') + $offsetAbs));
            }
            $cleanup !== null ? $akarT->insertBefore($baru, $cleanup) : $akarT->appendChild($baru);
        }

        // numId yang dirujuk gaya salinan ikut digeser.
        $styles = $this->part('word/styles.xml');
        $xpSt = $this->xpath($styles);
        $idSalinan = array_flip($petaGaya);
        foreach ($xpSt->query('/w:styles/w:style') as $style) {
            if (! isset($idSalinan[$style->getAttributeNS(self::NS_W, 'styleId')])) {
                continue;
            }
            foreach ($xpSt->query('.//w:numPr/w:numId', $style) as $numId) {
                $lama = $numId->getAttributeNS(self::NS_W, 'val');
                if (isset($petaNum[$lama])) {
                    $numId->setAttributeNS(self::NS_W, 'w:val', $petaNum[$lama]);
                }
            }
        }

        return $petaNum;
    }

    /**
     * Salin catatan kaki/akhir yang DIRUJUK isi sumber. Mengembalikan peta id lama => baru.
     *
     * @param  array{gaya: array<string, string>, paragrafBawaan: ?string, tabelBawaan: ?string}  $petaGaya
     * @param  array<string, string>  $petaNum
     * @return array<string, string>
     */
    private function gabungCatatan(ZipArchive $sumber, DOMDocument $docSumber, string $jenis, array $petaGaya, array $petaNum): array
    {
        $xpDoc = $this->xpath($docSumber);
        $dirujuk = [];
        foreach ($xpDoc->query("//w:{$jenis}Reference/@w:id") as $id) {
            $dirujuk[$id->value] = true;
        }
        if ($dirujuk === []) {
            return [];
        }

        $namaPart = "word/{$jenis}s.xml";
        $xml = $sumber->getFromName($namaPart);
        if ($xml === false) {
            return [];
        }

        $catSumber = $this->muatXml($xml, "{$jenis}s.xml sumber");
        $xpS = $this->xpath($catSumber);

        $catTujuan = $this->part($namaPart, function () use ($jenis, $catSumber, $xpS) {
            $this->tambahRelasiDokumen("{$jenis}s", "{$jenis}s.xml");
            $this->tambahOverride("/word/{$jenis}s.xml", $jenis === 'footnote' ? self::CT_FOOTNOTES : self::CT_ENDNOTES);

            // Part baru: pemisah bawaan (id -1/0) sumber ikut disalin.
            $dom = $this->xmlKosong("w:{$jenis}s");
            $this->salinDeklarasiNamespace($catSumber->documentElement, $dom->documentElement);
            foreach ($xpS->query("/w:{$jenis}s/w:{$jenis}[@w:type]") as $pemisah) {
                $dom->documentElement->appendChild($dom->importNode($pemisah, true));
            }

            return $dom;
        });
        $this->salinDeklarasiNamespace($catSumber->documentElement, $catTujuan->documentElement);

        $offset = max(0, $this->maksAtribut($this->xpath($catTujuan), "/w:{$jenis}s/w:{$jenis}/@w:id")) + 1;
        $peta = [];

        foreach ($xpS->query("/w:{$jenis}s/w:{$jenis}") as $catatan) {
            $id = $catatan->getAttributeNS(self::NS_W, 'id');
            if (! isset($dirujuk[$id])) {
                continue;
            }

            $peta[$id] = (string) ((int) $id + $offset);
            $baru = $catTujuan->importNode($catatan, true);
            $baru->setAttributeNS(self::NS_W, 'w:id', $peta[$id]);

            $this->gantiRujukanGaya($baru, $petaGaya);
            $this->gantiNumId($baru, $petaNum);
            // Relasi milik part catatan sumber tidak disalin -- tautan dibuka jadi teks biasa.
            $this->bukaRelasi($baru);

            $catTujuan->documentElement->appendChild($baru);
        }

        return $peta;
    }

    /**
     * Ganti rujukan gaya/numId/relasi/ID di satu node hasil impor isi sumber.
     *
     * @param  array{gaya: array<string, string>, paragrafBawaan: ?string, tabelBawaan: ?string}  $peta
     * @param  array<string, string>  $petaNum
     * @param  array<string, array{tipe: string, target: string, eksternal: bool}>  $relSumber
     * @param  array<string, string>  $petaRel
     * @param  array<string, array<string, string>>  $petaCatatan
     */
    private function rapikanNode(DOMNode $node, array $peta, array $petaNum, ZipArchive $sumber, array $relSumber, array &$petaRel, array $petaCatatan, int $offsetBookmark, int $offsetDocPr): void
    {
        if (! $node instanceof DOMElement) {
            return;
        }

        $doc = $node->ownerDocument;
        $xp = $this->xpath($doc);

        // Komentar dibuang (part comments tidak ikut disalin).
        foreach (iterator_to_array($xp->query('descendant-or-self::w:commentRangeStart | descendant-or-self::w:commentRangeEnd', $node)) as $el) {
            $el->parentNode->removeChild($el);
        }
        foreach (iterator_to_array($xp->query('descendant-or-self::w:r[w:commentReference]', $node)) as $el) {
            $el->parentNode->removeChild($el);
        }

        $this->gantiRujukanGaya($node, $peta);
        $this->gantiNumId($node, $petaNum);

        // Paragraf/tabel tanpa gaya eksplisit memakai gaya bawaan SUMBER, bukan tujuan.
        if ($peta['paragrafBawaan'] !== null) {
            foreach ($xp->query('descendant-or-self::w:p', $node) as $p) {
                $pPr = $this->anakPertama($p, 'pPr');
                if ($pPr === null) {
                    $pPr = $p->insertBefore($doc->createElementNS(self::NS_W, 'w:pPr'), $p->firstChild);
                }
                if ($this->anakPertama($pPr, 'pStyle') === null) {
                    $pStyle = $doc->createElementNS(self::NS_W, 'w:pStyle');
                    $pStyle->setAttributeNS(self::NS_W, 'w:val', $peta['paragrafBawaan']);
                    $pPr->insertBefore($pStyle, $pPr->firstChild);
                }
            }
        }
        if ($peta['tabelBawaan'] !== null) {
            foreach ($xp->query('descendant-or-self::w:tbl', $node) as $tbl) {
                $tblPr = $this->anakPertama($tbl, 'tblPr');
                if ($tblPr !== null && $this->anakPertama($tblPr, 'tblStyle') === null) {
                    $tblStyle = $doc->createElementNS(self::NS_W, 'w:tblStyle');
                    $tblStyle->setAttributeNS(self::NS_W, 'w:val', $peta['tabelBawaan']);
                    $tblPr->insertBefore($tblStyle, $tblPr->firstChild);
                }
            }
        }

        foreach ($xp->query('descendant-or-self::w:bookmarkStart/@w:id | descendant-or-self::w:bookmarkEnd/@w:id', $node) as $atr) {
            $atr->value = (string) ((int) $atr->value + $offsetBookmark);
        }
        foreach ($xp->query('descendant-or-self::wp:docPr/@id', $node) as $atr) {
            $atr->value = (string) ((int) $atr->value + $offsetDocPr);
        }

        foreach (['footnote', 'endnote'] as $jenis) {
            foreach (iterator_to_array($xp->query("descendant-or-self::w:{$jenis}Reference", $node)) as $ref) {
                $lama = $ref->getAttributeNS(self::NS_W, 'id');
                if (isset($petaCatatan[$jenis][$lama])) {
                    $ref->setAttributeNS(self::NS_W, 'w:id', $petaCatatan[$jenis][$lama]);
                } else {
                    $ref->parentNode->removeChild($ref);
                }
            }
        }

        foreach ($xp->query('descendant-or-self::w:sectPr', $node) as $sect) {
            $this->rapikanSectPr($sect);
        }

        // Semua atribut berawalan r: (r:id, r:embed, r:link, r:dm, ...) diarahkan ke relasi baru.
        foreach (iterator_to_array($xp->query('descendant-or-self::*/@*', $node)) as $atr) {
            if ($atr->namespaceURI !== self::NS_R) {
                continue;
            }
            $lama = $atr->value;
            if (! isset($petaRel[$lama])) {
                $baru = isset($relSumber[$lama]) ? $this->salinRelasi($sumber, $relSumber[$lama]) : null;
                if ($baru === null) {
                    continue;
                }
                $petaRel[$lama] = $baru;
            }
            $atr->value = $petaRel[$lama];
        }
    }

    /** @param array{gaya: array<string, string>, paragrafBawaan: ?string, tabelBawaan: ?string} $peta */
    private function gantiRujukanGaya(DOMNode $node, array $peta): void
    {
        $xp = $this->xpath($node->ownerDocument);
        foreach ($xp->query('descendant-or-self::w:pStyle | descendant-or-self::w:rStyle | descendant-or-self::w:tblStyle', $node) as $ref) {
            $lama = $ref->getAttributeNS(self::NS_W, 'val');
            if (isset($peta['gaya'][$lama])) {
                $ref->setAttributeNS(self::NS_W, 'w:val', $peta['gaya'][$lama]);
            } else {
                $ref->parentNode->removeChild($ref);
            }
        }
    }

    /** @param array<string, string> $petaNum */
    private function gantiNumId(DOMNode $node, array $petaNum): void
    {
        $xp = $this->xpath($node->ownerDocument);
        foreach ($xp->query('descendant-or-self::w:numPr/w:numId', $node) as $numId) {
            $lama = $numId->getAttributeNS(self::NS_W, 'val');
            if ($lama === '0') {
                continue;
            }
            $numId->setAttributeNS(self::NS_W, 'w:val', $petaNum[$lama] ?? '0');
        }
    }

    /** Buka w:hyperlink & buang atribut r: (untuk part yang relasinya tidak disalin). */
    private function bukaRelasi(DOMElement $node): void
    {
        $xp = $this->xpath($node->ownerDocument);
        foreach (iterator_to_array($xp->query('descendant::w:hyperlink', $node)) as $link) {
            while ($link->firstChild) {
                $link->parentNode->insertBefore($link->firstChild, $link);
            }
            $link->parentNode->removeChild($link);
        }
        foreach (iterator_to_array($xp->query('descendant-or-self::*/@*', $node)) as $atr) {
            if ($atr->namespaceURI === self::NS_R) {
                $atr->ownerElement->removeAttributeNode($atr);
            }
        }
    }

    /** Header/footer sumber tidak ikut -- rujukannya dibuang dari sectPr. */
    private function rapikanSectPr(DOMElement $sect): void
    {
        $xp = $this->xpath($sect->ownerDocument);
        foreach (iterator_to_array($xp->query('w:headerReference | w:footerReference | w:printerSettings', $sect)) as $el) {
            $sect->removeChild($el);
        }
        $this->setTipeSection($sect, 'continuous');
    }

    private function setTipeSection(DOMElement $sect, string $tipe): void
    {
        $doc = $sect->ownerDocument;
        $el = $this->anakPertama($sect, 'type');
        if ($el === null) {
            $el = $doc->createElementNS(self::NS_W, 'w:type');
            // w:type berada setelah footnotePr/endnotePr dan sebelum pgSz.
            $acuan = null;
            foreach ($sect->childNodes as $anak) {
                if ($anak instanceof DOMElement && ! in_array($anak->localName, ['headerReference', 'footerReference', 'footnotePr', 'endnotePr'], true)) {
                    $acuan = $anak;
                    break;
                }
            }
            $sect->insertBefore($el, $acuan);
        }
        $el->setAttributeNS(self::NS_W, 'w:val', $tipe);
    }

    /**
     * @return array<string, array{tipe: string, target: string, eksternal: bool}>
     */
    private function bacaRelasi(ZipArchive $zip, string $namaRels): array
    {
        $xml = $zip->getFromName($namaRels);
        if ($xml === false) {
            return [];
        }

        $dom = $this->muatXml($xml, $namaRels);
        $hasil = [];
        foreach ($dom->getElementsByTagNameNS(self::NS_REL, 'Relationship') as $rel) {
            $hasil[$rel->getAttribute('Id')] = [
                'tipe' => $rel->getAttribute('Type'),
                'target' => $rel->getAttribute('Target'),
                'eksternal' => strcasecmp($rel->getAttribute('TargetMode'), 'External') === 0,
            ];
        }

        return $hasil;
    }

    /**
     * Salin satu relasi dokumen sumber (dan part-nya, bila internal) ke dokumen tujuan.
     *
     * @param  array{tipe: string, target: string, eksternal: bool}  $rel
     * @return string|null Id relasi baru di dokumen tujuan
     */
    private function salinRelasi(ZipArchive $sumber, array $rel): ?string
    {
        if ($rel['eksternal']) {
            return $this->tambahRelasi('word/_rels/document.xml.rels', $rel['tipe'], $rel['target'], true);
        }

        $partSumber = $this->resolusiPath('word', $rel['target']);
        $partBaru = $this->salinPart($sumber, $partSumber);
        if ($partBaru === null) {
            return null;
        }

        return $this->tambahRelasi('word/_rels/document.xml.rels', $rel['tipe'], $this->relatif('word', $partBaru), false);
    }

    /** @var array<string, string> part sumber => part baru di tujuan (per sumber) */
    private array $partTersalin = [];

    /**
     * Salin part internal sumber (gambar, grafik, dst.) ke nama baru di tujuan, beserta
     * relasi milik part itu sendiri (rekursif). Null bila part tidak ada di sumber.
     */
    private function salinPart(ZipArchive $sumber, string $partSumber): ?string
    {
        $kunci = $this->urutanSumber.':'.$partSumber;
        if (isset($this->partTersalin[$kunci])) {
            return $this->partTersalin[$kunci];
        }

        $isi = $sumber->getFromName($partSumber);
        if ($isi === false) {
            return null;
        }

        $dir = dirname($partSumber);
        $partBaru = $dir.'/sipbag'.$this->urutanSumber.'_'.basename($partSumber);
        $this->partTersalin[$kunci] = $partBaru;

        $namaRels = $dir.'/_rels/'.basename($partSumber).'.rels';
        $relsPart = $this->bacaRelasi($sumber, $namaRels);
        if ($relsPart !== []) {
            $relsBaru = $this->muatXml('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="'.self::NS_REL.'"/>', 'rels baru');
            foreach ($relsPart as $id => $r) {
                $target = $r['target'];
                if (! $r['eksternal']) {
                    $anak = $this->salinPart($sumber, $this->resolusiPath($dir, $target));
                    if ($anak === null) {
                        continue;
                    }
                    $target = $this->relatif($dir, $anak);
                }
                $el = $relsBaru->documentElement->appendChild($relsBaru->createElementNS(self::NS_REL, 'Relationship'));
                $el->setAttribute('Id', $id);
                $el->setAttribute('Type', $r['tipe']);
                $el->setAttribute('Target', $target);
                if ($r['eksternal']) {
                    $el->setAttribute('TargetMode', 'External');
                }
            }
            $this->tujuan->addFromString(dirname($partBaru).'/_rels/'.basename($partBaru).'.rels', $relsBaru->saveXML());
        }

        $this->tujuan->addFromString($partBaru, $isi);
        $this->salinTipeKonten($sumber, $partSumber, $partBaru);

        return $partBaru;
    }

    private function salinTipeKonten(ZipArchive $sumber, string $partSumber, string $partBaru): void
    {
        $ct = $this->muatXml((string) $sumber->getFromName('[Content_Types].xml'), '[Content_Types].xml sumber');

        foreach ($ct->getElementsByTagNameNS(self::NS_CT, 'Override') as $o) {
            if (ltrim($o->getAttribute('PartName'), '/') === $partSumber) {
                $this->tambahOverride('/'.$partBaru, $o->getAttribute('ContentType'));

                return;
            }
        }

        $ekstensi = strtolower(pathinfo($partSumber, PATHINFO_EXTENSION));
        foreach ($ct->getElementsByTagNameNS(self::NS_CT, 'Default') as $d) {
            if (strtolower($d->getAttribute('Extension')) === $ekstensi) {
                $this->tambahDefault($ekstensi, $d->getAttribute('ContentType'));

                return;
            }
        }
    }

    private function tambahOverride(string $partName, string $contentType): void
    {
        $ct = $this->part('[Content_Types].xml');
        foreach ($ct->getElementsByTagNameNS(self::NS_CT, 'Override') as $o) {
            if ($o->getAttribute('PartName') === $partName) {
                return;
            }
        }
        $el = $ct->documentElement->appendChild($ct->createElementNS(self::NS_CT, 'Override'));
        $el->setAttribute('PartName', $partName);
        $el->setAttribute('ContentType', $contentType);
    }

    private function tambahDefault(string $ekstensi, string $contentType): void
    {
        $ct = $this->part('[Content_Types].xml');
        foreach ($ct->getElementsByTagNameNS(self::NS_CT, 'Default') as $d) {
            if (strtolower($d->getAttribute('Extension')) === $ekstensi) {
                return;
            }
        }
        $el = $ct->createElementNS(self::NS_CT, 'Default');
        $el->setAttribute('Extension', $ekstensi);
        $el->setAttribute('ContentType', $contentType);
        $ct->documentElement->insertBefore($el, $ct->documentElement->firstChild);
    }

    private function tambahRelasiDokumen(string $tipe, string $target): void
    {
        $this->tambahRelasi('word/_rels/document.xml.rels', self::TIPE_REL.$tipe, $target, false);
    }

    private function tambahRelasi(string $namaRels, string $tipe, string $target, bool $eksternal): string
    {
        $rels = $this->part($namaRels, fn () => $this->muatXml('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="'.self::NS_REL.'"/>', $namaRels));

        $ids = [];
        foreach ($rels->getElementsByTagNameNS(self::NS_REL, 'Relationship') as $r) {
            $ids[$r->getAttribute('Id')] = true;
        }
        $n = count($ids) + 1;
        while (isset($ids['rIdSip'.$n])) {
            $n++;
        }
        $id = 'rIdSip'.$n;

        $el = $rels->documentElement->appendChild($rels->createElementNS(self::NS_REL, 'Relationship'));
        $el->setAttribute('Id', $id);
        $el->setAttribute('Type', $tipe);
        $el->setAttribute('Target', $target);
        if ($eksternal) {
            $el->setAttribute('TargetMode', 'External');
        }

        return $id;
    }

    /**
     * Apakah ada isi (paragraf/tabel) di antara section break terakhir dan titik sisip?
     * $titik null = sisipan ditempel di akhir body (sebelum $sectBody). Paragraf penanda
     * sisipan lain yang belum diproses tidak dihitung sebagai isi.
     */
    private function adaIsiSebelum(?DOMElement $titik, DOMElement $sectBody): bool
    {
        $node = ($titik ?? $sectBody)->previousSibling;

        while ($node !== null) {
            if ($node instanceof DOMElement && $node->namespaceURI === self::NS_W) {
                if ($node->localName === 'p') {
                    $pPr = $this->anakPertama($node, 'pPr');
                    if ($pPr !== null && $this->anakPertama($pPr, 'sectPr') !== null) {
                        return false;
                    }
                    if (! in_array(trim($node->textContent), $this->semuaPenanda, true)) {
                        return true;
                    }
                } elseif ($node->localName === 'tbl' || $node->localName === 'sdt') {
                    return true;
                }
            }
            $node = $node->previousSibling;
        }

        return false;
    }

    private function cariParagrafPenanda(DOMXPath $xp, DOMElement $body, string $penanda): ?DOMElement
    {
        foreach ($xp->query('.//w:p', $body) as $p) {
            $teks = '';
            foreach ($xp->query('.//w:t', $p) as $t) {
                $teks .= $t->textContent;
            }
            if (str_contains($teks, $penanda)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Deklarasi namespace akar sumber (w14, wp14, mc, ...) yang belum ada di akar tujuan
     * ditambahkan, berikut daftar mc:Ignorable-nya -- supaya elemen hasil impor tidak
     * memakai awalan yang hanya dideklarasikan lokal (Word bisa menolak dokumennya).
     */
    private function salinDeklarasiNamespace(DOMElement $akarSumber, DOMElement $akarTujuan): void
    {
        $xp = new DOMXPath($akarSumber->ownerDocument);
        foreach ($xp->query('namespace::*', $akarSumber) as $ns) {
            $awalan = $ns->localName;
            if ($awalan === 'xml' || $awalan === '' || $ns->nodeName === 'xmlns') {
                continue;
            }
            if ($akarTujuan->lookupNamespaceURI($awalan) === null) {
                $akarTujuan->setAttributeNS(self::NS_XMLNS, 'xmlns:'.$awalan, $ns->nodeValue);
            }
        }

        $abaikanSumber = preg_split('/\s+/', trim($akarSumber->getAttributeNS(self::NS_MC, 'Ignorable'))) ?: [];
        $abaikanSumber = array_filter($abaikanSumber, fn ($a) => $a !== '' && $akarTujuan->lookupNamespaceURI($a) !== null);
        if ($abaikanSumber === []) {
            return;
        }

        if ($akarTujuan->lookupNamespaceURI('mc') === null) {
            $akarTujuan->setAttributeNS(self::NS_XMLNS, 'xmlns:mc', self::NS_MC);
        }
        $abaikanTujuan = preg_split('/\s+/', trim($akarTujuan->getAttributeNS(self::NS_MC, 'Ignorable'))) ?: [];
        $gabung = array_values(array_unique(array_filter([...$abaikanTujuan, ...$abaikanSumber])));
        $akarTujuan->setAttributeNS(self::NS_MC, 'mc:Ignorable', implode(' ', $gabung));
    }

    /**
     * Part tujuan sebagai DOM (dimuat sekali, ditulis balik di akhir sisipkan()).
     * $buat dipanggil bila part belum ada di dokumen tujuan.
     */
    private function part(string $nama, ?callable $buat = null): DOMDocument
    {
        if (isset($this->partTujuan[$nama])) {
            return $this->partTujuan[$nama];
        }

        $xml = $this->tujuan->getFromName($nama);
        if ($xml !== false) {
            return $this->partTujuan[$nama] = $this->muatXml($xml, $nama);
        }

        if ($buat === null) {
            throw new RuntimeException("Dokumen notula (.docx) tidak memiliki {$nama}.");
        }

        // Daftarkan dulu (mencegah rekursi bila $buat memanggil part() lain).
        return $this->partTujuan[$nama] = $buat();
    }

    private function xmlKosong(string $akar): DOMDocument
    {
        return $this->muatXml('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><'.$akar.' xmlns:w="'.self::NS_W.'" xmlns:r="'.self::NS_R.'"/>', $akar);
    }

    private function muatXml(string|false $xml, string $label): DOMDocument
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        if ($xml === false || $xml === '' || ! @$dom->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)) {
            throw new RuntimeException("Isi {$label} tidak bisa dibaca (bukan XML yang sah).");
        }

        return $dom;
    }

    private function xpath(DOMDocument $dom): DOMXPath
    {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::NS_W);
        $xp->registerNamespace('r', self::NS_R);
        $xp->registerNamespace('wp', self::NS_WP);

        return $xp;
    }

    private function maksAtribut(DOMXPath $xp, string $query): int
    {
        $maks = -1;
        foreach ($xp->query($query) as $atr) {
            if (is_numeric($atr->value)) {
                $maks = max($maks, (int) $atr->value);
            }
        }

        return $maks;
    }

    private function anakPertama(DOMElement $induk, string $localName): ?DOMElement
    {
        foreach ($induk->childNodes as $anak) {
            if ($anak instanceof DOMElement && $anak->namespaceURI === self::NS_W && $anak->localName === $localName) {
                return $anak;
            }
        }

        return null;
    }

    private function resolusiPath(string $dasar, string $target): string
    {
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }

        $bagian = [];
        foreach (explode('/', $dasar.'/'.$target) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($bagian);

                continue;
            }
            $bagian[] = $seg;
        }

        return implode('/', $bagian);
    }

    private function relatif(string $dasar, string $part): string
    {
        return str_starts_with($part, $dasar.'/') ? substr($part, strlen($dasar) + 1) : '/'.$part;
    }

    /**
     * Urutan anak rPr/pPr menurut skema OOXML -- Word menolak dokumen bila urutannya
     * salah, jadi properti hasil leburan docDefaults harus disusun ulang.
     */
    private const URUTAN_RPR = ['rStyle', 'rFonts', 'b', 'bCs', 'i', 'iCs', 'caps', 'smallCaps', 'strike', 'dstrike', 'outline', 'shadow', 'emboss', 'imprint', 'noProof', 'snapToGrid', 'vanish', 'webHidden', 'color', 'spacing', 'w', 'kern', 'position', 'sz', 'szCs', 'highlight', 'u', 'effect', 'bdr', 'shd', 'fitText', 'vertAlign', 'rtl', 'cs', 'em', 'lang', 'eastAsianLayout', 'specVanish', 'oMath'];

    private const URUTAN_PPR = ['pStyle', 'keepNext', 'keepLines', 'pageBreakBefore', 'framePr', 'widowControl', 'numPr', 'suppressLineNumbers', 'pBdr', 'shd', 'tabs', 'suppressAutoHyphens', 'kinsoku', 'wordWrap', 'overflowPunct', 'topLinePunct', 'autoSpaceDE', 'autoSpaceDN', 'bidi', 'adjustRightInd', 'snapToGrid', 'spacing', 'ind', 'contextualSpacing', 'mirrorIndents', 'suppressOverlap', 'jc', 'textDirection', 'textAlignment', 'textboxTightWrap', 'outlineLvl', 'divId', 'cnfStyle', 'rPr', 'sectPr', 'pPrChange'];

    /** @param list<string> $urutan */
    private function urutkanAnak(DOMElement $induk, array $urutan): void
    {
        $posisi = array_flip($urutan);
        $anak = [];
        foreach (iterator_to_array($induk->childNodes) as $i => $node) {
            if ($node instanceof DOMElement) {
                $anak[] = [$posisi[$node->localName] ?? (1000 + $i), $i, $node];
                $induk->removeChild($node);
            }
        }
        usort($anak, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        foreach ($anak as [, , $node]) {
            $induk->appendChild($node);
        }
    }
}
