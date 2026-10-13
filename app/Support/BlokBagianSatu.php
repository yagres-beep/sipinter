<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Log;

/**
 * Blok-blok HTML Bagian I notula, supaya "Susun Ulang Otomatis" bisa MENAMBAHKAN &
 * MEMPERBARUI isi dari data terbaru tanpa menimpa suntingan manual Tim SAKIP.
 *
 * Saat disusun untuk pratinjau (NotulaService::susunBagianSatu()), tiap bagian
 * dokumen diawali paragraf penanda (NotulaBagian1DocxService::penandaBlok()) yang di
 * sini diubah jadi pembungkus <div data-blok="...">: "kepala" (judul & detail rapat),
 * "iku-{id}" (satu per IKU), "kustom" (bagian kustom) dan "ekor" (penutup).
 *
 * Editor pratinjau (kompilasi-notula.blade.php, tandaiDiedit()) memberi atribut
 * data-diedit pada blok & baris tabel (<tr>) yang disunting. gabungkan() lalu:
 * - blok yang tidak pernah disunting -> diganti versi terbaru dari data;
 * - blok yang disunting -> dipertahankan, tapi baris tabel di dalamnya yang TIDAK
 *   disunting tetap diperbarui dari data (bila jumlah barisnya masih sama);
 * - IKU baru -> ditambahkan di posisinya; blok lama yang datanya sudah tidak ada
 *   ikut hilang, KECUALI pernah disunting (dipertahankan di posisinya semula).
 */
class BlokBagianSatu
{
    private const POLA_PENANDA = '/<p\b[^>]*>(?:(?!<\/p>).)*?SIPINTERBLOK_([a-z0-9\-]+)(?:(?!<\/p>).)*?<\/p>/s';

    /**
     * Ubah paragraf penanda blok jadi pembungkus <div data-blok>. Bila strukturnya
     * tidak sah (mis. template kustom menaruh blok IKU di dalam sel tabel), penanda
     * cukup dibuang -- HTML tetap tampil, hanya tanpa pembungkus blok.
     */
    public static function bungkus(string $html): string
    {
        if (! str_contains($html, 'SIPINTERBLOK_')
            || ! preg_match('/<div class="notula-inline [^"]*">/', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $awalIsi = $m[0][1] + strlen($m[0][0]);
        $akhirIsi = strrpos($html, '</div>');
        if ($akhirIsi === false || $akhirIsi < $awalIsi) {
            return self::buangPenanda($html);
        }

        $isi = substr($html, $awalIsi, $akhirIsi - $awalIsi);
        $isiTerbungkus = '<div data-blok="kepala">'
            .preg_replace(self::POLA_PENANDA, '</div><div data-blok="$1">', $isi)
            .'</div>';

        $hasil = substr($html, 0, $awalIsi).$isiTerbungkus.substr($html, $akhirIsi);

        if (! self::strukturSah($hasil, substr_count($isi, 'SIPINTERBLOK_') + 1)) {
            Log::warning('Penanda blok Bagian I tidak berada di tingkat teratas dokumen -- pembungkus blok dilewati, Susun Ulang akan menimpa seluruh isi.');

            return self::buangPenanda($html);
        }

        return $hasil;
    }

    public static function buangPenanda(string $html): string
    {
        return preg_replace(self::POLA_PENANDA, '', $html) ?? $html;
    }

    public static function punyaBlok(?string $html): bool
    {
        return $html !== null && str_contains($html, 'data-blok="');
    }

    /**
     * @return array{html: string, dipertahankan: int}
     */
    public static function gabungkan(string $lama, string $baru): array
    {
        $dokLama = self::muat($lama);
        $dokBaru = self::muat($baru);
        $blokLama = self::daftarBlok($dokLama['dom']);
        $blokBaru = self::daftarBlok($dokBaru['dom']);

        if ($blokLama === [] || $blokBaru === []) {
            return ['html' => $baru, 'dipertahankan' => 0];
        }

        $dom = $dokBaru['dom'];
        $dipertahankan = 0;

        foreach ($blokBaru as $kunci => $elBaru) {
            $elLama = $blokLama[$kunci] ?? null;
            if (! $elLama || ! $elLama->hasAttribute('data-diedit')) {
                continue;
            }

            self::perbaruiBarisYangTidakDiedit($elLama, $elBaru, $dokLama['dom']);
            $impor = $dom->importNode($elLama, true);
            $elBaru->parentNode->replaceChild($impor, $elBaru);
            $blokBaru[$kunci] = $impor;
            $dipertahankan++;
        }

        // Blok tersunting yang datanya sudah tidak ada lagi (mis. IKU batal
        // diverifikasi) tetap disimpan, tepat setelah blok yang mendahuluinya dulu.
        $sebelumnya = null;
        foreach ($blokLama as $kunci => $elLama) {
            if (! isset($blokBaru[$kunci]) && $elLama->hasAttribute('data-diedit')) {
                $impor = $dom->importNode($elLama, true);
                $acuan = $sebelumnya !== null ? $blokBaru[$sebelumnya] : reset($blokBaru);
                if ($sebelumnya !== null) {
                    $acuan->parentNode->insertBefore($impor, $acuan->nextSibling);
                } else {
                    $acuan->parentNode->insertBefore($impor, $acuan);
                }
                $blokBaru = self::sisipkanSetelah($blokBaru, $sebelumnya, $kunci, $impor);
                $dipertahankan++;
            }
            if (isset($blokBaru[$kunci])) {
                $sebelumnya = $kunci;
            }
        }

        return ['html' => $dokBaru['style'].self::simpan($dom), 'dipertahankan' => $dipertahankan];
    }

    /**
     * Di dalam blok yang disunting, baris tabel yang TIDAK disunting tetap diambil
     * dari data terbaru -- hanya bila jumlah barisnya sama dan tidak ada tabel
     * bersarang, supaya baris ke-i lama dan baru memang baris yang sama.
     */
    private static function perbaruiBarisYangTidakDiedit(DOMElement $lama, DOMElement $baru, DOMDocument $domLama): void
    {
        $xLama = new DOMXPath($domLama);
        $xBaru = new DOMXPath($baru->ownerDocument);

        if ($xLama->query('.//table//table', $lama)->length > 0 || $xBaru->query('.//table//table', $baru)->length > 0) {
            return;
        }

        $barisLama = iterator_to_array($xLama->query('.//tr', $lama));
        $barisBaru = iterator_to_array($xBaru->query('.//tr', $baru));

        if ($barisLama === [] || count($barisLama) !== count($barisBaru)) {
            return;
        }

        foreach ($barisLama as $i => $tr) {
            if (! $tr->hasAttribute('data-diedit')) {
                $tr->parentNode->replaceChild($domLama->importNode($barisBaru[$i], true), $tr);
            }
        }
    }

    /**
     * @param  array<string, DOMElement>  $blok
     * @return array<string, DOMElement>
     */
    private static function sisipkanSetelah(array $blok, ?string $setelah, string $kunci, DOMElement $el): array
    {
        $hasil = [];
        if ($setelah === null) {
            $hasil[$kunci] = $el;
        }
        foreach ($blok as $k => $v) {
            $hasil[$k] = $v;
            if ($k === $setelah) {
                $hasil[$kunci] = $el;
            }
        }

        return $hasil;
    }

    /**
     * <style> pembuka (hasil LibreOfficeConversionService) dipisah dulu sebelum
     * di-parse supaya posisinya tidak dipindah libxml.
     *
     * @return array{style: string, dom: DOMDocument}
     */
    private static function muat(string $html): array
    {
        $style = '';
        if (preg_match('/^\s*<style\b.*?<\/style>/s', $html, $m)) {
            $style = $m[0];
            $html = substr($html, strlen($m[0]));
        }

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="sipinter-akar">'.$html.'</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        return ['style' => $style, 'dom' => $dom];
    }

    /**
     * @return array<string, DOMElement>
     */
    private static function daftarBlok(DOMDocument $dom): array
    {
        $hasil = [];
        foreach ((new DOMXPath($dom))->query('//div[@data-blok][not(ancestor::div[@data-blok])]') as $el) {
            $hasil[$el->getAttribute('data-blok')] ??= $el;
        }

        return $hasil;
    }

    private static function simpan(DOMDocument $dom): string
    {
        $akar = (new DOMXPath($dom))->query('//div[@id="sipinter-akar"]')->item(0);
        $html = '';
        foreach ($akar?->childNodes ?? [] as $anak) {
            $html .= $dom->saveHTML($anak) ?: '';
        }

        return $html;
    }

    private static function strukturSah(string $html, int $jumlahDiharapkan): bool
    {
        $dom = self::muat($html)['dom'];
        $x = new DOMXPath($dom);
        $blok = $x->query('//div[@data-blok]');

        if ($blok->length !== $jumlahDiharapkan) {
            return false;
        }

        foreach ($blok as $el) {
            $induk = $el->parentNode;
            if (! $induk instanceof DOMElement || ! str_contains(' '.$induk->getAttribute('class').' ', ' notula-inline ')) {
                return false;
            }
        }

        return true;
    }
}
