<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * Penyesuaian HTML notula KHUSUS sebelum dirender dompdf (lihat
 * NotulaService::dataNotulaUtuh()) -- HTML tersimpan (bagian{1,2,3}_html) maupun
 * pratinjau WYSIWYG di layar TIDAK diubah sama sekali, karena dua masalah di bawah
 * murni keterbatasan mesin dompdf, bukan kesalahan isi:
 *
 * 1. dompdf TIDAK PERNAH memecah satu baris tabel (<tr>) ke dua halaman. Baris yang
 *    lebih tinggi dari satu halaman (mis. "Analisis Capaian Kinerja" yang panjang)
 *    DIPOTONG begitu saja -- sisa teksnya hilang dari PDF tanpa pesan apa pun (sudah
 *    diukur: sel 3 halaman hanya tercetak ~25% isinya). Baris yang "cuma" hampir satu
 *    halaman dipindah utuh ke halaman berikut, menyisakan halaman nyaris kosong.
 *    pecahBarisTinggi() memecah baris seperti itu jadi beberapa baris berurutan
 *    (garis batas di antaranya dihilangkan) supaya isinya mengalir ke halaman
 *    berikutnya seperti di Word, tanpa ada teks yang hilang.
 *
 * 2. LibreOffice menulis font template apa adanya (mis. `"Arial", serif`) -- dompdf
 *    tidak punya Arial, jadi jatuh ke generik `serif` (Times) dan seluruh notula
 *    tercetak dengan font yang beda dari template. petakanFont() menyisipkan padanan
 *    metrik bawaan dompdf (Helvetica = metrik Arial, Times = Times New Roman,
 *    Courier = Courier New) tepat setelah nama font aslinya.
 *
 * Diparse lewat DOMDocument dengan pembungkus <div> sendiri di dalam <body> supaya
 * <style> milik tiap potongan (lihat LibreOfficeConversionService::ekstrakKontenInline())
 * tetap di posisinya, tidak dipindah libxml ke <head>.
 */
class HtmlNotulaDompdf
{
    /** Baris yang perkiraan tingginya melebihi porsi tinggi badan halaman ini dipecah. */
    private const BATAS_TINGGI_BARIS = 0.6;

    /**
     * Target tinggi tiap potongan baris hasil pemecahan (porsi tinggi badan halaman) --
     * potongan kecil = sisa ruang kosong di kaki halaman juga kecil, tanpa efek visual
     * lain karena garis batas antar-potongan dihilangkan.
     */
    private const TARGET_TINGGI_POTONGAN = 0.25;

    /**
     * Satu paragraf baru dipotong di DALAM teksnya (bukan di batas <br>/antar-paragraf)
     * bila lebih tinggi dari porsi ini -- potongan di tengah paragraf membuat baris
     * terakhir potongan berhenti lebih awal, jadi dibuat sejarang mungkin.
     */
    private const TARGET_TINGGI_PECAHAN_PARAGRAF = 0.5;

    private const ID_AKAR = 'notula-dompdf-akar';

    /** @var array<string, list<string>> padanan font bawaan dompdf => nama font sumber (huruf kecil) */
    private const PADANAN_FONT = [
        'Helvetica' => ['arial', 'arial narrow', 'helvetica', 'liberation sans', 'arimo', 'segoe ui', 'tahoma', 'verdana', 'trebuchet ms', 'century gothic', 'franklin gothic book', 'open sans', 'roboto', 'source sans pro', 'dejavu sans', 'noto sans'],
        'Times' => ['times new roman', 'times', 'liberation serif', 'tinos', 'georgia', 'garamond', 'book antiqua', 'bookman old style', 'palatino linotype', 'dejavu serif', 'noto serif'],
        'Courier' => ['courier new', 'courier', 'liberation mono', 'cousine', 'consolas', 'lucida console', 'dejavu sans mono'],
    ];

    /** Carlito/Caladea didaftarkan dengan nama Calibri/Cambria di pdf.notula-utuh. */
    private const ALIAS_FONT_TERDAFTAR = ['carlito' => 'Calibri', 'caladea' => 'Cambria'];

    private float $lebarBadanPt;

    private float $tinggiBadanPt;

    /**
     * @param  array{lebar: float, tinggi: float, atas: float, kanan: float, bawah: float, kiri: float}  $halaman  satuan pt
     */
    public static function siapkan(string $html, array $halaman): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $diri = new self;
        $diri->lebarBadanPt = max(100.0, $halaman['lebar'] - $halaman['kiri'] - $halaman['kanan']);
        $diri->tinggiBadanPt = max(100.0, $halaman['tinggi'] - $halaman['atas'] - $halaman['bawah']);

        return $diri->proses($html);
    }

    private function proses(string $html): string
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><html><body><div id="'.self::ID_AKAR.'">'.$html.'</div></body></html>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $akar = $dom->getElementById(self::ID_AKAR);
        if (! $akar) {
            return $html;
        }

        $this->petakanFont($dom);

        // Diambil sebagai array statis dulu -- pemecahan baris menyisipkan <tr> baru
        // ke tabel yang sama, jangan sampai ikut diiterasi lagi.
        foreach (iterator_to_array($dom->getElementsByTagName('table')) as $tabel) {
            $this->pecahBarisTinggi($tabel);
            $this->tetapkanLebarKolom($tabel);
        }

        $hasil = '';
        foreach ($akar->childNodes as $anak) {
            $hasil .= $dom->saveHTML($anak) ?: '';
        }

        return $hasil;
    }

    // ------------------------------------------------------------------
    // Font
    // ------------------------------------------------------------------

    private function petakanFont(DOMDocument $dom): void
    {
        foreach ($dom->getElementsByTagName('style') as $style) {
            $style->textContent = $this->petakanDeklarasiFont($style->textContent);
        }

        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//*[@style]') as $el) {
            /** @var DOMElement $el */
            $asli = $el->getAttribute('style');
            if (stripos($asli, 'font-family') !== false) {
                $el->setAttribute('style', $this->petakanDeklarasiFont($asli));
            }
        }

        foreach ($xpath->query('//font[@face]') as $el) {
            /** @var DOMElement $el */
            $el->setAttribute('face', $this->petakanDaftarFont($el->getAttribute('face')));
        }
    }

    private function petakanDeklarasiFont(string $css): string
    {
        return preg_replace_callback(
            '/(font-family\s*:\s*)([^;{}]+)/i',
            fn (array $m) => $m[1].$this->petakanDaftarFont($m[2]),
            $css
        ) ?? $css;
    }

    /**
     * `"Arial", serif` -> `"Arial", Helvetica, serif`. dompdf memakai font PERTAMA
     * dalam daftar yang ia kenal, jadi padanan disisipkan tepat setelah font aslinya
     * (dan sebelum generik apa pun yang ditulis LibreOffice).
     */
    private function petakanDaftarFont(string $daftar): string
    {
        $bagian = array_map('trim', explode(',', $daftar));
        $important = '';
        $terakhir = count($bagian) - 1;
        if (preg_match('/\s*!important\s*$/i', $bagian[$terakhir], $m)) {
            $important = ' !important';
            $bagian[$terakhir] = trim(substr($bagian[$terakhir], 0, -strlen($m[0])));
        }

        foreach ($bagian as $i => $nama) {
            $kunci = mb_strtolower(trim($nama, " \t\"'"));

            $padanan = self::ALIAS_FONT_TERDAFTAR[$kunci] ?? null;
            if ($padanan === null) {
                foreach (self::PADANAN_FONT as $bawaan => $sumber) {
                    if (in_array($kunci, $sumber, true)) {
                        $padanan = $bawaan;
                        break;
                    }
                }
            }

            if ($padanan === null) {
                continue;
            }

            $sudahAda = collect($bagian)->contains(fn ($n) => strcasecmp(trim($n, " \t\"'"), $padanan) === 0);
            if (! $sudahAda) {
                array_splice($bagian, $i + 1, 0, [$padanan]);
            }
            break;
        }

        return implode(', ', $bagian).$important;
    }

    // ------------------------------------------------------------------
    // Lebar kolom
    // ------------------------------------------------------------------

    /**
     * dompdf (table-layout: fixed, lihat pdf.notula-utuh) MENGABAIKAN lebar dari <col>
     * maupun atribut width <td> hasil LibreOffice dan membagi kolom SAMA RATA (sudah
     * diukur: tabel Agenda 95:476 di template tercetak 50:50, kolom "Indikator Kinerja"
     * jadi sempit sampai kata terpenggal). Lebar kolom fixed-layout diambil dari baris
     * PERTAMA -- di tabel per-IKU baris pertamanya baris "Sasaran" yang bersel gabungan
     * (colspan), jadi lebar per kolomnya tidak bisa disimpulkan sama sekali.
     *
     * Solusinya baris "pengukur" tak terlihat (tinggi 0, tanpa border/isi) di awal tabel,
     * SATU sel per kolom grid dengan lebar persen dari <col> template -- dompdf membaca
     * lebar kolom dari baris ini, baris lain mengikuti.
     */
    private function tetapkanLebarKolom(DOMElement $tabel): void
    {
        $baris = $this->barisLangsung($tabel);
        if ($baris === []) {
            return;
        }

        $jumlahKolomGrid = 0;
        foreach ($baris as $tr) {
            $jumlah = 0;
            foreach ($this->selLangsung($tr) as $s) {
                $jumlah += max(1, (int) ($s->getAttribute('colspan') ?: 1));
            }
            $jumlahKolomGrid = max($jumlahKolomGrid, $jumlah);
        }

        $lebar = $this->lebarDariCol($tabel) ?? $this->lebarDariBarisPolos($baris, $jumlahKolomGrid);
        if ($lebar === null || count($lebar) !== $jumlahKolomGrid || $jumlahKolomGrid < 2) {
            return;
        }

        $total = array_sum($lebar);
        if ($total <= 0) {
            return;
        }

        $dom = $tabel->ownerDocument;
        $pengukur = $dom->createElement('tr');
        $pengukur->setAttribute('style', 'height: 0');
        foreach ($lebar as $w) {
            $td = $dom->createElement('td');
            $td->setAttribute('style', 'width: '.round($w / $total * 100, 3).'%; height: 0; padding: 0; margin: 0; border: none; font-size: 0; line-height: 0');
            $pengukur->appendChild($td);
        }

        $pertama = $baris[0];
        $pertama->parentNode->insertBefore($pengukur, $pertama);
    }

    /**
     * @return list<float>|null lebar (satuan relatif) per kolom grid dari <col>
     */
    private function lebarDariCol(DOMElement $tabel): ?array
    {
        $col = [];
        foreach ($tabel->childNodes as $anak) {
            if (! $anak instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($anak->tagName);
            if ($tag === 'col') {
                $col[] = $anak;
            } elseif ($tag === 'colgroup') {
                foreach ($anak->childNodes as $c) {
                    if ($c instanceof DOMElement && strtolower($c->tagName) === 'col') {
                        $col[] = $c;
                    }
                }
            }
        }

        if ($col === []) {
            return null;
        }

        $lebar = [];
        foreach ($col as $c) {
            $w = $this->lebarPt($c);
            if ($w === null || $w <= 0) {
                return null;
            }
            $span = max(1, (int) ($c->getAttribute('span') ?: 1));
            for ($i = 0; $i < $span; $i++) {
                $lebar[] = $w / $span;
            }
        }

        return $lebar;
    }

    /**
     * Cadangan bila tabel tidak punya <col>: baris pertama yang SELURUH selnya tunggal
     * (tanpa colspan) dan berlebar eksplisit.
     *
     * @param  list<DOMElement>  $baris
     * @return list<float>|null
     */
    private function lebarDariBarisPolos(array $baris, int $jumlahKolomGrid): ?array
    {
        foreach ($baris as $tr) {
            $sel = $this->selLangsung($tr);
            if (count($sel) !== $jumlahKolomGrid) {
                continue;
            }

            $lebar = [];
            foreach ($sel as $s) {
                $w = $this->lebarPt($s);
                if ($w === null || $w <= 0 || (int) ($s->getAttribute('colspan') ?: 1) > 1) {
                    continue 2;
                }
                $lebar[] = $w;
            }

            return $lebar;
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Pemecahan baris tabel
    // ------------------------------------------------------------------

    private function pecahBarisTinggi(DOMElement $tabel): void
    {
        $lebarTabelPt = $this->lebarPt($tabel) ?? $this->lebarBadanPt;
        $batas = $this->tinggiBadanPt * self::BATAS_TINGGI_BARIS;

        // Baris yang tersentuh rowspan (sel gabungan vertikal) TIDAK dipecah --
        // menyisipkan baris baru di tengah cakupan rowspan akan menggeser sel.
        $sisaCakupanRowspan = 0;

        foreach ($this->barisLangsung($tabel) as $baris) {
            $sel = $this->selLangsung($baris);
            $rowspanMaks = 1;
            foreach ($sel as $s) {
                $rowspanMaks = max($rowspanMaks, (int) ($s->getAttribute('rowspan') ?: 1));
            }

            $terlindung = $sisaCakupanRowspan > 0 || $rowspanMaks > 1;
            $sisaCakupanRowspan = max($sisaCakupanRowspan - 1, $rowspanMaks - 1);

            if ($terlindung || $sel === [] || $baris->getElementsByTagName('table')->length > 0) {
                continue;
            }

            $lebarSel = array_map(fn (DOMElement $s) => $this->lebarIsiSelPt($s, $lebarTabelPt, count($sel)), $sel);

            $tertinggi = 0.0;
            foreach ($sel as $i => $s) {
                $tertinggi = max($tertinggi, $this->tinggiNode($s, $lebarSel[$i]));
            }

            if ($tertinggi > $batas) {
                $this->pecahBaris($baris, $sel, $lebarSel);
            }
        }
    }

    /**
     * @param  list<DOMElement>  $sel
     * @param  list<float>  $lebarSel
     */
    private function pecahBaris(DOMElement $baris, array $sel, array $lebarSel): void
    {
        $target = $this->tinggiBadanPt * self::TARGET_TINGGI_POTONGAN;

        $potonganPerSel = [];
        $jumlahPotongan = 1;
        foreach ($sel as $i => $s) {
            $potonganPerSel[$i] = $this->kelompokkanIsi($s, $lebarSel[$i], $target);
            $jumlahPotongan = max($jumlahPotongan, count($potonganPerSel[$i]));
        }

        if ($jumlahPotongan < 2) {
            return;
        }

        $sebelum = $baris->nextSibling;
        $induk = $baris->parentNode;

        for ($k = 0; $k < $jumlahPotongan; $k++) {
            /** @var DOMElement $barisBaru */
            $barisBaru = $baris->cloneNode(false);

            foreach ($sel as $i => $s) {
                /** @var DOMElement $selBaru */
                $selBaru = $s->cloneNode(false);
                foreach ($potonganPerSel[$i][$k] ?? [] as $node) {
                    $selBaru->appendChild($node);
                }

                $tambahan = [];
                if ($k > 0) {
                    $tambahan[] = 'border-top: none';
                }
                if ($k < $jumlahPotongan - 1) {
                    $tambahan[] = 'border-bottom: none';
                }
                $style = rtrim(trim($selBaru->getAttribute('style')), '; ');
                $selBaru->setAttribute('style', ltrim($style.'; '.implode('; ', $tambahan), '; '));

                $barisBaru->appendChild($selBaru);
            }

            $induk->insertBefore($barisBaru, $sebelum);
        }

        $induk->removeChild($baris);
    }

    /**
     * Kelompokkan isi satu sel jadi potongan-potongan yang masing-masing kira-kira
     * <= $target pt. Paragraf/daftar yang sendirinya lebih tinggi dari $target ikut
     * dipecah (paragraf di batas baris/kalimat, daftar per butir).
     *
     * @return list<list<DOMNode>>
     */
    private function kelompokkanIsi(DOMElement $sel, float $lebar, float $target): array
    {
        $unit = [];
        $inline = [];

        $tutupInline = function () use (&$unit, &$inline) {
            if ($inline !== []) {
                $unit[] = $inline;
                $inline = [];
            }
        };

        foreach (iterator_to_array($sel->childNodes) as $anak) {
            $sel->removeChild($anak);

            if ($anak instanceof DOMElement && $this->adalahBlok($anak)) {
                $tutupInline();
                foreach ($this->pecahBlok($anak, $lebar, $target) as $potong) {
                    $unit[] = [$potong];
                }
            } else {
                $inline[] = $anak;
            }
        }
        $tutupInline();

        $kelompok = [];
        $sekarang = [];
        $tinggiSekarang = 0.0;
        foreach ($unit as $nodes) {
            $tinggi = array_sum(array_map(fn (DOMNode $n) => $this->tinggiNode($n, $lebar), $nodes));
            $hanyaSpasi = collect($nodes)->every(fn (DOMNode $n) => $n instanceof DOMText && trim($n->textContent) === '');

            if ($sekarang !== [] && ! $hanyaSpasi && $tinggiSekarang + $tinggi > $target) {
                $kelompok[] = $sekarang;
                $sekarang = [];
                $tinggiSekarang = 0.0;
            }

            array_push($sekarang, ...$nodes);
            $tinggiSekarang += $tinggi;
        }
        if ($sekarang !== []) {
            $kelompok[] = $sekarang;
        }

        return $kelompok === [] ? [[]] : $kelompok;
    }

    /**
     * @return list<DOMElement>
     */
    private function pecahBlok(DOMElement $blok, float $lebar, float $target): array
    {
        if ($this->tinggiNode($blok, $lebar) <= $target) {
            return [$blok];
        }

        $tag = strtolower($blok->tagName);

        if ($tag === 'ol' || $tag === 'ul') {
            return $this->pecahDaftar($blok, $lebar, $target);
        }

        if (in_array($tag, ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote'], true)
            && $blok->getElementsByTagName('table')->length === 0) {
            return $this->pecahParagraf($blok, $lebar, $target, max($target, $this->tinggiBadanPt * self::TARGET_TINGGI_PECAHAN_PARAGRAF));
        }

        return [$blok];
    }

    /**
     * @return list<DOMElement>
     */
    private function pecahDaftar(DOMElement $daftar, float $lebar, float $target): array
    {
        $hasil = [];
        $sekarang = null;
        $tinggiSekarang = 0.0;
        $nomor = (int) ($daftar->getAttribute('start') ?: 1);

        foreach (iterator_to_array($daftar->childNodes) as $anak) {
            $daftar->removeChild($anak);
            $tinggi = $this->tinggiNode($anak, $lebar - 20);

            if ($sekarang === null || ($tinggiSekarang > 0 && $tinggiSekarang + $tinggi > $target)) {
                if ($sekarang !== null) {
                    $hasil[] = $sekarang;
                }
                /** @var DOMElement $sekarang */
                $sekarang = $daftar->cloneNode(false);
                if (strtolower($daftar->tagName) === 'ol') {
                    $sekarang->setAttribute('start', (string) $nomor);
                }
                $tinggiSekarang = 0.0;
            }

            $sekarang->appendChild($anak);
            $tinggiSekarang += $tinggi;
            if ($anak instanceof DOMElement && strtolower($anak->tagName) === 'li') {
                $nomor++;
            }
        }

        if ($sekarang !== null) {
            $hasil[] = $sekarang;
        }

        return $hasil === [] ? [$daftar] : $hasil;
    }

    /**
     * Pecah satu paragraf panjang jadi beberapa paragraf berurutan -- diutamakan di
     * batas baris (<br>), lalu batas kalimat, lalu spasi. Format inline (tebal/miring/
     * warna/ukuran) tetap ikut karena tiap potongan adalah salinan utuh elemen aslinya
     * yang teksnya dipangkas. Hanya potongan TERAKHIR yang mempertahankan jarak bawah
     * paragraf aslinya supaya sambungannya tidak tampak seperti paragraf baru.
     *
     * @return list<DOMElement>
     */
    private function pecahParagraf(DOMElement $p, float $lebar, float $target, float $targetDalamSegmen): array
    {
        $teks = $this->teksDenganBaris($p);
        $panjang = mb_strlen($teks);
        $fs = $this->ukuranFont($p);
        $karakterPerBaris = max(10, (int) floor($lebar / ($fs * 0.52)));
        $barisPerPotongan = max(3, (int) floor($target / ($fs * 1.5)));
        $barisPerPotonganDalamSegmen = max(3, (int) floor($targetDalamSegmen / ($fs * 1.5)));

        $titik = $this->titikPotongPerBaris($teks, $karakterPerBaris, $barisPerPotongan, $barisPerPotonganDalamSegmen);

        if ($titik === []) {
            return [$p];
        }

        $hasil = [];
        $batas = array_merge([0], $titik, [$panjang]);
        for ($i = 0; $i < count($batas) - 1; $i++) {
            $salinan = $this->pangkasTeks($p, $batas[$i], $batas[$i + 1]);
            if ($i < count($batas) - 2) {
                $style = rtrim(trim($salinan->getAttribute('style')), '; ');
                $salinan->setAttribute('style', ltrim($style.'; margin-bottom: 0', '; '));
            }
            if ($i > 0) {
                $style = rtrim(trim($salinan->getAttribute('style')), '; ');
                $salinan->setAttribute('style', ltrim($style.'; margin-top: 0; text-indent: 0', '; '));
            }
            $hasil[] = $salinan;
        }

        return $hasil;
    }

    /**
     * Posisi karakter tempat paragraf dipotong supaya tiap potongan <= $barisMaks
     * baris perkiraan. Tiap segmen antar-<br> dihitung minimal satu baris (bukan
     * cuma jumlah karakternya) -- daftar bernomor hasil daftarBernomor() berupa banyak
     * baris pendek yang dipisah <br>.
     *
     * @return list<int>
     */
    private function titikPotongPerBaris(string $teks, int $karakterPerBaris, int $barisMaks, int $barisMaksDalamSegmen): array
    {
        $titik = [];
        $barisTerkumpul = 0;
        $pos = 0;

        foreach (explode("\n", $teks) as $segmen) {
            $panjangSegmen = mb_strlen($segmen);
            $baris = max(1, (int) ceil($panjangSegmen / $karakterPerBaris));

            if ($barisTerkumpul > 0 && $barisTerkumpul + $baris > $barisMaks) {
                $titik[] = $pos;
                $barisTerkumpul = 0;
            }

            // Satu segmen tanpa <br> yang sendirinya lebih panjang dari satu potongan:
            // potong di batas kalimat/spasi di dalamnya.
            $mulai = $pos;
            $akhirSegmen = $pos + $panjangSegmen;
            while ($baris > $barisMaksDalamSegmen) {
                $potong = $this->cariTitikPotong($teks, $mulai, $karakterPerBaris * $barisMaksDalamSegmen);
                if ($potong <= $mulai || $potong >= $akhirSegmen) {
                    break;
                }
                $titik[] = $potong;
                $mulai = $potong;
                $baris = max(1, (int) ceil(($akhirSegmen - $mulai) / $karakterPerBaris));
            }

            $barisTerkumpul += $baris;
            $pos = $akhirSegmen + 1;
        }

        return array_values(array_filter($titik, fn ($t) => $t > 0 && $t < mb_strlen($teks)));
    }

    private function cariTitikPotong(string $teks, int $mulai, int $maks): int
    {
        $jendela = mb_substr($teks, $mulai, $maks);
        $minimal = (int) floor($maks * 0.5);

        $pos = mb_strrpos($jendela, "\n");
        if ($pos !== false && $pos >= $minimal) {
            return $mulai + $pos + 1;
        }

        if (preg_match_all('/[.!?;:]\s/u', $jendela, $m, PREG_OFFSET_CAPTURE)) {
            for ($i = count($m[0]) - 1; $i >= 0; $i--) {
                $posKarakter = mb_strlen(substr($jendela, 0, $m[0][$i][1])) + 2;
                if ($posKarakter >= $minimal) {
                    return $mulai + $posKarakter;
                }
            }
        }

        $pos = mb_strrpos($jendela, ' ');
        if ($pos !== false && $pos >= $minimal) {
            return $mulai + $pos + 1;
        }

        return $mulai + $maks;
    }

    /**
     * Teks elemen dengan <br> dihitung sebagai "\n" -- posisi karakternya dipakai
     * bersama oleh cariTitikPotong() dan pangkasTeks(). Baris-baru/tab DI DALAM simpul
     * teks (LibreOffice membungkus baris kode sumber HTML-nya tiap ~80 karakter) cuma
     * spasi biasa saat dirender, jadi diganti spasi -- panjangnya tetap 1:1 supaya
     * posisi karakter tetap cocok dengan pangkasTeks().
     */
    private function teksDenganBaris(DOMNode $node): string
    {
        $teks = '';
        foreach ($this->daun($node) as $daun) {
            $teks .= $daun instanceof DOMText ? str_replace(["\r", "\n", "\t"], ' ', $daun->data) : "\n";
        }

        return $teks;
    }

    /**
     * Salinan utuh $el yang hanya menyisakan teks pada rentang karakter [$dari, $sampai).
     */
    private function pangkasTeks(DOMElement $el, int $dari, int $sampai): DOMElement
    {
        /** @var DOMElement $salinan */
        $salinan = $el->cloneNode(true);
        $pos = 0;

        foreach ($this->daun($salinan) as $daun) {
            $panjang = $daun instanceof DOMText ? mb_strlen($daun->data) : 1;
            $awal = $pos;
            $akhir = $pos + $panjang;
            $pos = $akhir;

            if ($akhir <= $dari || $awal >= $sampai) {
                $daun->parentNode?->removeChild($daun);

                continue;
            }

            if ($daun instanceof DOMText) {
                $daun->data = mb_substr($daun->data, max(0, $dari - $awal), min($akhir, $sampai) - max($awal, $dari));
            }
        }

        // <br> di ujung awal/akhir potongan cuma menyisakan baris kosong.
        foreach (['first', 'last'] as $ujung) {
            $daun = $this->daun($salinan);
            $calon = $ujung === 'first' ? ($daun[0] ?? null) : ($daun[count($daun) - 1] ?? null);
            if ($calon instanceof DOMElement) {
                $calon->parentNode?->removeChild($calon);
            }
        }

        return $salinan;
    }

    /**
     * Simpul teks & <br> di dalam $node, urut dokumen.
     *
     * @return list<DOMNode>
     */
    private function daun(DOMNode $node): array
    {
        $hasil = [];
        foreach ($node->childNodes as $anak) {
            if ($anak instanceof DOMText) {
                $hasil[] = $anak;
            } elseif ($anak instanceof DOMElement) {
                if (strtolower($anak->tagName) === 'br') {
                    $hasil[] = $anak;
                } elseif (! in_array(strtolower($anak->tagName), ['style', 'script'], true)) {
                    array_push($hasil, ...$this->daun($anak));
                }
            }
        }

        return $hasil;
    }

    // ------------------------------------------------------------------
    // Perkiraan ukuran
    // ------------------------------------------------------------------

    /**
     * Perkiraan tinggi (pt) sengaja DILEBIHKAN sedikit (lebar huruf rata-rata 0,52em,
     * tinggi baris 1,5x -- sama dengan line-height bawaan LibreOffice) supaya potongan
     * hasil pemecahan pasti muat satu halaman walau perkiraannya meleset.
     */
    private function tinggiNode(DOMNode $node, float $lebar): float
    {
        if ($node instanceof DOMText) {
            $teks = trim(str_replace(["\r", "\n", "\t"], ' ', $node->data));

            return $teks === '' ? 0.0 : $this->tinggiTeks($teks, $lebar, 11.0);
        }

        if (! $node instanceof DOMElement) {
            return 0.0;
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, ['ol', 'ul'], true)) {
            $tinggi = 0.0;
            foreach ($node->childNodes as $li) {
                $tinggi += $this->tinggiNode($li, $lebar - 20);
            }

            return $tinggi;
        }

        if (in_array($tag, ['td', 'th', 'div', 'blockquote'], true) && $this->punyaAnakBlok($node)) {
            $tinggi = 0.0;
            foreach ($node->childNodes as $anak) {
                $tinggi += $this->tinggiNode($anak, $lebar);
            }

            return $tinggi;
        }

        if ($tag === 'table') {
            return $this->tinggiTeks(str_replace(["\r", "\n", "\t"], ' ', $node->textContent), $lebar * 0.6, $this->ukuranFont($node));
        }

        $fs = $this->ukuranFont($node);
        $tinggi = $this->tinggiTeks($this->teksDenganBaris($node), $lebar, $fs);

        return $this->adalahBlok($node) ? $tinggi + $fs * 0.6 : $tinggi;
    }

    private function tinggiTeks(string $teks, float $lebar, float $fs): float
    {
        $karakterPerBaris = max(1.0, $lebar / ($fs * 0.52));
        $baris = 0;
        foreach (explode("\n", $teks) as $segmen) {
            $segmen = trim(preg_replace('/\s+/u', ' ', $segmen) ?? $segmen);
            $baris += max(1, (int) ceil(mb_strlen($segmen) / $karakterPerBaris));
        }

        return $baris * $fs * 1.5;
    }

    private function ukuranFont(DOMElement $el): float
    {
        $html = $el->ownerDocument->saveHTML($el) ?: '';
        $maks = 0.0;
        if (preg_match_all('/font-size\s*:\s*([\d.]+)\s*(pt|px)/i', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $cocok) {
                $nilai = (float) $cocok[1] * (strtolower($cocok[2]) === 'px' ? 0.75 : 1);
                $maks = max($maks, $nilai);
            }
        }

        return $maks > 0 ? max(8.0, $maks) : 11.0;
    }

    private function lebarIsiSelPt(DOMElement $sel, float $lebarTabel, int $jumlahSel): float
    {
        $lebar = $this->lebarPt($sel) ?? ($lebarTabel / max(1, $jumlahSel));

        return max(30.0, min($lebar, $this->lebarBadanPt) - 12);
    }

    /**
     * Lebar dari atribut width / style width (px LibreOffice = 1/96 inci). Persen
     * dihitung terhadap lebar badan halaman.
     */
    private function lebarPt(DOMElement $el): ?float
    {
        $style = $el->getAttribute('style');
        if (preg_match('/(?:^|;)\s*width\s*:\s*([\d.]+)\s*(pt|px|in|cm|%)/i', $style, $m)) {
            return $this->kePt((float) $m[1], strtolower($m[2]));
        }

        $width = trim($el->getAttribute('width'));
        if ($width !== '' && preg_match('/^([\d.]+)\s*(%)?$/', $width, $m)) {
            return $this->kePt((float) $m[1], isset($m[2]) ? '%' : 'px');
        }

        return null;
    }

    private function kePt(float $nilai, string $satuan): float
    {
        return match ($satuan) {
            'px' => $nilai * 0.75,
            'in' => $nilai * 72,
            'cm' => $nilai * 72 / 2.54,
            '%' => $this->lebarBadanPt * $nilai / 100,
            default => $nilai,
        };
    }

    // ------------------------------------------------------------------
    // Struktur
    // ------------------------------------------------------------------

    /**
     * Baris milik $tabel sendiri (langsung, atau lewat thead/tbody/tfoot) -- BUKAN
     * baris tabel bersarang di dalam sel.
     *
     * @return list<DOMElement>
     */
    private function barisLangsung(DOMElement $tabel): array
    {
        $hasil = [];
        foreach ($tabel->childNodes as $anak) {
            if (! $anak instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($anak->tagName);
            if ($tag === 'tr') {
                $hasil[] = $anak;
            } elseif (in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                foreach ($anak->childNodes as $tr) {
                    if ($tr instanceof DOMElement && strtolower($tr->tagName) === 'tr') {
                        $hasil[] = $tr;
                    }
                }
            }
        }

        return $hasil;
    }

    /**
     * @return list<DOMElement>
     */
    private function selLangsung(DOMElement $baris): array
    {
        $hasil = [];
        foreach ($baris->childNodes as $anak) {
            if ($anak instanceof DOMElement && in_array(strtolower($anak->tagName), ['td', 'th'], true)) {
                $hasil[] = $anak;
            }
        }

        return $hasil;
    }

    private function adalahBlok(DOMElement $el): bool
    {
        return in_array(strtolower($el->tagName), ['p', 'div', 'ol', 'ul', 'table', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'li', 'dl', 'hr'], true);
    }

    private function punyaAnakBlok(DOMElement $el): bool
    {
        foreach ($el->childNodes as $anak) {
            if ($anak instanceof DOMElement && $this->adalahBlok($anak)) {
                return true;
            }
        }

        return false;
    }
}
