<?php

namespace App\Support;

/**
 * Sintaks pecahan sederhana [[pembilang|penyebut]] yang bisa diketik langsung oleh
 * Tim SAKIP di field "Dasar Hitung" Master IKU -- supaya rumus seperti
 * "y = [[n|N]] x 100%" tercetak sebagai pecahan bersusun (garis pembagi horizontal)
 * di PDF, persis gaya rumus pada dokumen resmi, TANPA mesin LaTeX/MathML (dompdf
 * cukup dengan trik display:inline-block + border-bottom, tidak butuh library baru).
 *
 * .docx TIDAK mendukung pecahan bersusun lewat penggantian teks biasa
 * (TemplateProcessor::setValue hanya mengganti teks polos) -- di sana sintaksnya
 * diratakan jadi notasi miring biasa "pembilang/penyebut".
 */
class RumusMarkup
{
    private const POLA = '/\[\[(.+?)\|(.+?)\]\]/';

    /**
     * Untuk PDF/HTML: teks lain di-escape dulu (aman dari suntikan HTML oleh isian
     * bebas Tim SAKIP), baru [[a|b]] diganti span pecahan bersusun.
     */
    public static function keHtml(?string $teks): string
    {
        if (! $teks) {
            return '';
        }

        $aman = e($teks);

        return preg_replace_callback(self::POLA, function ($m) {
            return '<span style="display:inline-block;vertical-align:middle;text-align:center;margin:0 2px">'
                .'<span style="display:block;border-bottom:1px solid #000;padding:0 3px;line-height:1.3">'.$m[1].'</span>'
                .'<span style="display:block;padding:0 3px;line-height:1.3">'.$m[2].'</span>'
                .'</span>';
        }, $aman) ?? $aman;
    }

    /**
     * Untuk .docx: pecahan bersusun diratakan jadi notasi miring biasa "pembilang/penyebut".
     */
    public static function keTeksPolos(?string $teks): ?string
    {
        if (! $teks) {
            return $teks;
        }

        return preg_replace(self::POLA, '$1/$2', $teks) ?? $teks;
    }

    /**
     * Untuk .docx: [[a|b]] dirakit jadi pecahan bersusun SUNGGUHAN lewat OOXML Math
     * (<m:oMath>/<m:f>, garis pembagi horizontal asli -- dirender Word persis seperti
     * rumus di Equation Editor), bukan notasi miring "a/b" seperti keTeksPolos().
     * Namespace "m" (http://schemas.openxmlformats.org/officeDocument/2006/math) sudah
     * otomatis dideklarasikan di root <w:document> berkas .docx manapun sejak Word 2007,
     * jadi tidak perlu suntikan namespace tambahan di sini.
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
                $xml .= '<m:f><m:fPr><m:type m:val="bar"/></m:fPr>'
                    .'<m:num><m:r><m:t xml:space="preserve">'.self::amanXml($potongan[$i + 1]).'</m:t></m:r></m:num>'
                    .'<m:den><m:r><m:t xml:space="preserve">'.self::amanXml($potongan[$i + 2]).'</m:t></m:r></m:den>'
                    .'</m:f>';
            }
        }

        return '<m:oMath>'.$xml.'</m:oMath>';
    }

    private static function amanXml(string $teks): string
    {
        return htmlspecialchars($teks, ENT_QUOTES | ENT_XML1);
    }
}
