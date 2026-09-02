<?php

namespace App\Support;

/**
 * DUA sintaks yang bisa diketik langsung oleh Tim SAKIP di field "Dasar Hitung"
 * Master IKU, supaya rumus tercetak sebagai notasi matematika bersusun (bukan
 * notasi mendatar biasa) di PDF & .docx, TANPA mesin LaTeX/MathML:
 *
 * - [[pembilang|penyebut]] -- pecahan bersusun (garis pembagi horizontal), mis.
 *   "y = [[n|N]] x 100%".
 * - [[SUM:batas_bawah,batas_atas|suku]] -- notasi sigma bersusun (batas bawah di
 *   bawah simbol Σ, batas atas di atasnya, suku dijumlah mengikuti di sebelah
 *   kanan), mis. "[[SUM:i=1,n|xi]]" untuk rumus IPP "w1 x (sigma i=1..n dari xi)".
 *
 * .docx TIDAK mendukung notasi bersusun lewat penggantian teks biasa
 * (TemplateProcessor::setValue hanya mengganti teks polos) -- di sana kedua
 * sintaks diratakan jadi notasi mendatar biasa lewat keTeksPolos().
 */
class RumusMarkup
{
    private const POLA = '/\[\[(.+?)\|(.+?)\]\]/';

    private const AWALAN_SUM = 'SUM:';

    /**
     * Pecah token SUM: "batas_bawah,batas_atas" (grup pertama, SETELAH awalan SUM:
     * dibuang) jadi [batas_bawah, batas_atas] -- dipakai ketiga renderer di bawah
     * supaya aturan parsingnya satu tempat saja.
     *
     * @return array{0: string, 1: string}
     */
    private static function pecahBatasSum(string $g1): array
    {
        [$bawah, $atas] = array_pad(explode(',', substr($g1, strlen(self::AWALAN_SUM)), 2), 2, '');

        return [trim($bawah), trim($atas)];
    }

    private static function isSum(string $g1): bool
    {
        return str_starts_with($g1, self::AWALAN_SUM);
    }

    /**
     * Untuk PDF/HTML: teks lain di-escape dulu (aman dari suntikan HTML oleh isian
     * bebas Tim SAKIP), baru [[a|b]] diganti span pecahan/sigma bersusun.
     */
    public static function keHtml(?string $teks): string
    {
        if (! $teks) {
            return '';
        }

        $aman = e($teks);

        return preg_replace_callback(self::POLA, function ($m) {
            if (self::isSum($m[1])) {
                [$bawah, $atas] = self::pecahBatasSum($m[1]);

                return '<span style="display:inline-flex;flex-direction:column;align-items:center;vertical-align:middle;margin:0 2px;line-height:1.1">'
                    .'<span style="font-size:.65em">'.$atas.'</span>'
                    .'<span style="font-size:1.3em;line-height:.8">&Sigma;</span>'
                    .'<span style="font-size:.65em">'.$bawah.'</span>'
                    .'</span>'.$m[2];
            }

            return '<span style="display:inline-block;vertical-align:middle;text-align:center;margin:0 2px">'
                .'<span style="display:block;border-bottom:1px solid #000;padding:0 3px;line-height:1.3">'.$m[1].'</span>'
                .'<span style="display:block;padding:0 3px;line-height:1.3">'.$m[2].'</span>'
                .'</span>';
        }, $aman) ?? $aman;
    }

    /**
     * Untuk .docx: pecahan diratakan jadi notasi miring biasa "pembilang/penyebut",
     * sigma diratakan jadi "Σ(batas_bawah..batas_atas) suku".
     */
    public static function keTeksPolos(?string $teks): ?string
    {
        if (! $teks) {
            return $teks;
        }

        return preg_replace_callback(self::POLA, function ($m) {
            if (self::isSum($m[1])) {
                [$bawah, $atas] = self::pecahBatasSum($m[1]);

                return "Σ({$bawah}..{$atas}) {$m[2]}";
            }

            return "{$m[1]}/{$m[2]}";
        }, $teks) ?? $teks;
    }

    /**
     * Untuk .docx: [[a|b]] dirakit jadi pecahan bersusun SUNGGUHAN (<m:f>, garis
     * pembagi horizontal asli) dan [[SUM:...|...]] jadi notasi sigma bersusun
     * SUNGGUHAN (<m:nary>, batas bawah/atas asli) lewat OOXML Math -- dirender Word
     * persis seperti rumus di Equation Editor, bukan notasi mendatar seperti
     * keTeksPolos(). Namespace "m" (http://schemas.openxmlformats.org/officeDocument/2006/math)
     * sudah otomatis dideklarasikan di root <w:document> berkas .docx manapun sejak
     * Word 2007, jadi tidak perlu suntikan namespace tambahan di sini.
     *
     * Hasilnya berupa fragmen XML MENTAH (bukan teks polos) -- pemanggil WAJIB
     * menyisipkannya lewat penggantian raw XML (lihat
     * NotulaBagian1DocxService::setFormula()), BUKAN lewat TemplateProcessor::setValue()
     * ke dalam <w:t> biasa (elemen <m:oMath> tidak sah sebagai isi <w:t>).
     */
    public static function keOmml(?string $teks): ?string
    {
        if (! $teks) {
            return $teks;
        }

        $potongan = preg_split(self::POLA, $teks, -1, PREG_SPLIT_DELIM_CAPTURE);
        $total = count($potongan);
        $xml = '';

        for ($i = 0; $i < $total; $i += 3) {
            if ($potongan[$i] !== '') {
                $xml .= '<m:r><m:t xml:space="preserve">'.self::amanXml($potongan[$i]).'</m:t></m:r>';
            }

            if ($i + 2 < $total) {
                $g1 = $potongan[$i + 1];
                $suku = $potongan[$i + 2];

                if (self::isSum($g1)) {
                    [$bawah, $atas] = self::pecahBatasSum($g1);
                    $xml .= '<m:nary><m:naryPr><m:chr m:val="∑"/><m:limLoc m:val="subSup"/></m:naryPr>'
                        .'<m:sub><m:r><m:t xml:space="preserve">'.self::amanXml($bawah).'</m:t></m:r></m:sub>'
                        .'<m:sup><m:r><m:t xml:space="preserve">'.self::amanXml($atas).'</m:t></m:r></m:sup>'
                        .'<m:e><m:r><m:t xml:space="preserve">'.self::amanXml($suku).'</m:t></m:r></m:e>'
                        .'</m:nary>';
                } else {
                    $xml .= '<m:f><m:fPr><m:type m:val="bar"/></m:fPr>'
                        .'<m:num><m:r><m:t xml:space="preserve">'.self::amanXml($g1).'</m:t></m:r></m:num>'
                        .'<m:den><m:r><m:t xml:space="preserve">'.self::amanXml($suku).'</m:t></m:r></m:den>'
                        .'</m:f>';
                }
            }
        }

        return '<m:oMath>'.$xml.'</m:oMath>';
    }

    private static function amanXml(string $teks): string
    {
        return htmlspecialchars($teks, ENT_QUOTES | ENT_XML1);
    }
}
