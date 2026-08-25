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
}
