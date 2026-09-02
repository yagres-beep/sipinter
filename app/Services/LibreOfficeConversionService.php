<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Mengonversi berkas Bagian II & III notula (.docx/.xlsx/dll.) lewat LibreOffice
 * headless (RF-42a, RF-42b, SRS §5.1) — baik menjadi PDF (dipakai untuk pratinjau
 * iframe di layar Kompilasi Notula) maupun menjadi HTML INLINE (dipakai NotulaService
 * untuk menempelkan Bagian II/III ke dalam dokumen notula menyatu, lihat
 * pdf.notula-utuh, supaya kontennya bisa reflow/menyambung alih-alih selalu mulai
 * di halaman baru seperti PDF yang digabung halaman-demi-halaman).
 *
 * PENTING: LibreOffice BUKAN paket Composer — ini aplikasi desktop terpisah yang
 * harus dipasang manual di server/komputer yang menjalankan SIPINTER.
 *
 * === Cara pasang di Windows ===
 * 1. Unduh installer dari https://www.libreoffice.org/download/download/ (pilih versi
 *    "masih didukung"/terbaru), lalu jalankan seperti instalasi aplikasi Windows biasa
 *    (Next → Next → Install). Tidak perlu opsi khusus.
 * 2. Setelah terpasang, executable command-line-nya ada di:
 *      C:\Program Files\LibreOffice\program\soffice.exe
 *    Path ini SUDAH menjadi nilai default LIBREOFFICE_PATH di .env. Sesuaikan bila
 *    lokasi instalasi berbeda (mis. "Program Files (x86)" pada Windows 32-bit, atau
 *    versi Portable).
 * 3. TIDAK perlu menambahkan LibreOffice ke PATH sistem Windows — service ini
 *    memanggil executable langsung lewat path lengkap dari
 *    config('services.libreoffice.binary_path'), bukan lewat nama perintah pendek.
 * 4. Uji manual dari terminal (opsional, untuk memastikan instalasi benar):
 *      & "C:\Program Files\LibreOffice\program\soffice.exe" --headless --convert-to pdf --outdir . contoh.docx
 *    Bila menghasilkan contoh.pdf di folder yang sama, instalasi sudah benar.
 */
class LibreOfficeConversionService
{
    /**
     * Konversi satu berkas (docx/xlsx/dll.) menjadi PDF, disimpan di $outputDir.
     * Nama berkas hasil SAMA dengan nama asli, hanya ekstensinya berubah jadi .pdf.
     *
     * @return string path lengkap ke PDF hasil konversi.
     */
    public function convertToPdf(string $inputPath, string $outputDir): string
    {
        return $this->jalankanSoffice($inputPath, $outputDir, 'pdf');
    }

    /**
     * Konversi satu berkas dokumen (docx/xlsx/doc/xls/odt/ods) menjadi HTML INLINE
     * siap tempel — gambar di dalamnya disematkan sebagai data: URI (RF terkait
     * notula menyatu), dan CSS-nya dilingkupi supaya tidak bentrok dengan gaya
     * dokumen pembungkus. Berkas HTML/gambar sementara hasil LibreOffice DIHAPUS
     * sebelum method ini kembali — hanya string HTML yang dikembalikan.
     */
    public function convertToHtml(string $inputPath, string $outputDir): string
    {
        $htmlPath = $this->jalankanSoffice($inputPath, $outputDir, 'html');

        $html = file_get_contents($htmlPath);
        $kontenInline = $this->ekstrakKontenInline($html === false ? '' : $html, $outputDir);

        $basename = pathinfo($htmlPath, PATHINFO_FILENAME);
        foreach (glob(rtrim($outputDir, '/\\').DIRECTORY_SEPARATOR.$basename.'*') ?: [] as $sisa) {
            if (is_file($sisa)) {
                @unlink($sisa);
            }
        }

        return $kontenInline;
    }

    private function jalankanSoffice(string $inputPath, string $outputDir, string $format): string
    {
        if (! is_file($inputPath)) {
            throw new RuntimeException("Berkas sumber tidak ditemukan: {$inputPath}");
        }

        $binary = config('services.libreoffice.binary_path');

        if (! is_string($binary) || ! is_file($binary)) {
            throw new RuntimeException(
                "LibreOffice tidak ditemukan di: {$binary}. Pasang LibreOffice dan/atau sesuaikan ".
                'LIBREOFFICE_PATH di .env — lihat petunjuk instalasi di LibreOfficeConversionService.php.'
            );
        }

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // LibreOffice headless WAJIB punya direktori profil pengguna yang bisa ditulis
        // (default-nya $HOME/.config/libreoffice) -- proses web server (mis. www-data
        // di container) biasanya tidak punya $HOME yang valid/writable, bikin
        // LibreOffice gagal start total ("User installation could not be completed",
        // dconf-CRITICAL: unable to create directory '$HOME/.cache/dconf': Permission
        // denied). -env:UserInstallation memaksa LibreOffice pakai folder SEMENTARA
        // milik sendiri (unik per panggilan, supaya konversi yang jalan bersamaan
        // tidak rebutan lock profil yang sama) alih-alih $HOME, dibersihkan lagi
        // setelah proses selesai supaya tidak menumpuk di /tmp.
        $profileDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'libreoffice-profile-'.Str::random(16);

        // --headless    : jalan tanpa membuka jendela GUI (cocok dipanggil dari server).
        // --norestore   : jangan coba memulihkan sesi macet sebelumnya (bisa membuka
        //                 dialog yang menggantung tanpa GUI).
        // --convert-to  : format tujuan konversi.
        // --outdir      : folder tempat berkas hasil konversi disimpan.
        $process = new Process([
            $binary,
            '--headless',
            '--norestore',
            '-env:UserInstallation=file://'.$profileDir,
            '--convert-to', $format,
            '--outdir', $outputDir,
            $inputPath,
        ]);

        // Konversi dokumen besar (mis. xlsx banyak sheet, gambar resolusi tinggi)
        // bisa agak lama — beri waktu lebih longgar dari request HTTP biasa.
        $process->setTimeout(120);
        $process->run();

        File::deleteDirectory($profileDir);

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $namaHasil = pathinfo($inputPath, PATHINFO_FILENAME).'.'.$format;
        $outputPath = rtrim($outputDir, '/\\').DIRECTORY_SEPARATOR.$namaHasil;

        if (! is_file($outputPath)) {
            throw new RuntimeException("Konversi LibreOffice tidak menghasilkan berkas yang diharapkan di: {$outputPath}");
        }

        return $outputPath;
    }

    /**
     * Sanitasi HTML hasil ekspor LibreOffice lalu ekstrak jadi potongan siap-tempel:
     * buang tag berbahaya (script/iframe/dll.) & atribut event-handler, sematkan
     * gambar sebagai data: URI, dan lingkupi CSS-nya (bukan dibuang) supaya format
     * asli (font, batas tabel, spasi) tetap terjaga tanpa membocorkan gaya ke luar.
     *
     * CATATAN FIDELITAS: rendering dompdf tidak 100% identik dengan Word — dompdf
     * memakai mesin CSS sendiri (bukan mesin render browser), jadi properti CSS
     * yang jarang/kompleks dari LibreOffice bisa tampil sedikit berbeda.
     */
    private function ekstrakKontenInline(string $html, string $outputDir): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        foreach (['script', 'iframe', 'object', 'embed', 'link', 'meta'] as $tag) {
            $elems = $dom->getElementsByTagName($tag);
            for ($i = $elems->length - 1; $i >= 0; $i--) {
                $el = $elems->item($i);
                $el?->parentNode?->removeChild($el);
            }
        }

        if ($dom->documentElement) {
            $this->buangAtributBerbahaya($dom->documentElement);
            $this->pindahkanAlignKeStyle($dom->documentElement);
            $this->gabungkanTbodyBersebelahan($dom);
        }

        // LibreOffice menaruh definisi kelas paragraf/tabel (P1, T1, dst.) di
        // <style> pada <head> — tanpa ini format asli (font, spasi, batas tabel)
        // hilang total saat body-nya ditempel ke dokumen lain.
        $css = '';
        foreach ($dom->getElementsByTagName('style') as $styleEl) {
            $css .= $styleEl->textContent."\n";
        }

        foreach ($dom->getElementsByTagName('img') as $img) {
            $src = $img->getAttribute('src');
            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }

            $namaFile = basename(parse_url($src, PHP_URL_PATH) ?: $src);
            $pathGambar = rtrim($outputDir, '/\\').DIRECTORY_SEPARATOR.$namaFile;

            if (is_file($pathGambar)) {
                $mime = match (strtolower(pathinfo($pathGambar, PATHINFO_EXTENSION))) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'gif' => 'image/gif',
                    'svg' => 'image/svg+xml',
                    default => 'image/png',
                };
                $img->setAttribute('src', 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($pathGambar)));
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $bodyHtml = '';
        if ($body) {
            foreach ($body->childNodes as $child) {
                $bodyHtml .= $dom->saveHTML($child) ?: '';
            }
        }

        $cssTerlingkup = $this->lingkupkanCss($css, '.notula-inline');

        return '<style>'.$cssTerlingkup.'</style><div class="notula-inline">'.$bodyHtml.'</div>';
    }

    /**
     * LibreOffice mengekspor perataan paragraf lewat atribut HTML usang
     * `align="center"` dsb. -- atribut presentational ini punya prioritas CSS
     * PALING RENDAH (setara gaya bawaan user-agent), jadi KALAH oleh aturan
     * stylesheet berbasis kelas yang disimpan di ekstrakKontenInline() (mis.
     * `.notula-inline p { text-align: left }`, gaya paragraf default LibreOffice)
     * walau elemennya sendiri punya align="center" eksplisit -- berakibat judul &
     * blok TTD yang seharusnya rata tengah malah tampil rata kiri saat ditempel ke
     * dokumen notula menyatu. Pindahkan ke inline style supaya SELALU menang di
     * atas aturan <style> apa pun (inline style tidak bisa kalah kecuali lawannya
     * pakai !important).
     */
    private function pindahkanAlignKeStyle(DOMNode $node): void
    {
        if ($node instanceof DOMElement && $node->hasAttribute('align')) {
            $align = strtolower(trim($node->getAttribute('align')));
            if (in_array($align, ['left', 'right', 'center', 'justify'], true)) {
                $style = rtrim(trim($node->getAttribute('style')), '; ');
                $style = ($style !== '' ? $style.'; ' : '').'text-align: '.$align.';';
                $node->setAttribute('style', $style);
            }
            $node->removeAttribute('align');
        }

        foreach (iterator_to_array($node->childNodes) as $anak) {
            $this->pindahkanAlignKeStyle($anak);
        }
    }

    /**
     * LibreOffice kadang mengekspor SATU tabel visual (baris-barisnya menyambung
     * tanpa jeda, border rapat) sebagai BANYAK <tbody> terpisah -- satu per baris
     * atau per kelompok baris, artefak dari struktur <w:tbl>/<w:trPr> asli di
     * .docx sumber. Spesifikasi HTML5 sebenarnya membolehkan rowspan menembus
     * batas <tbody>, tapi mesin tata-letak tabel Chrome/Blink (dipakai pratinjau
     * WYSIWYG Kompilasi Notula) TIDAK konsisten menanganinya -- sel rowspan/colspan
     * di baris header (mis. "Triwulan III" menaungi Target/Realisasi/Capaian) jadi
     * salah tempat begitu barisnya ada di <tbody> lain, walau dompdf (jalur PDF)
     * tetap merender benar. Gabungkan semua <tbody> yang bersebelahan langsung di
     * bawah <table> yang sama jadi SATU supaya kedua jalur (pratinjau layar & PDF)
     * selalu tampil identik, persis tata letak template aslinya.
     */
    private function gabungkanTbodyBersebelahan(DOMDocument $dom): void
    {
        foreach ($dom->getElementsByTagName('table') as $table) {
            $tbodies = [];
            foreach (iterator_to_array($table->childNodes) as $anak) {
                if ($anak instanceof DOMElement && strtolower($anak->tagName) === 'tbody') {
                    $tbodies[] = $anak;
                }
            }

            if (count($tbodies) < 2) {
                continue;
            }

            $pertama = $tbodies[0];
            for ($i = 1; $i < count($tbodies); $i++) {
                foreach (iterator_to_array($tbodies[$i]->childNodes) as $baris) {
                    $pertama->appendChild($baris);
                }
                $tbodies[$i]->parentNode?->removeChild($tbodies[$i]);
            }
        }
    }

    private function buangAtributBerbahaya(DOMNode $node): void
    {
        if ($node instanceof DOMElement && $node->hasAttributes()) {
            $atributHapus = [];
            foreach ($node->attributes as $atribut) {
                $nama = strtolower($atribut->name);
                $nilai = trim(strtolower($atribut->value));
                if (str_starts_with($nama, 'on') || (in_array($nama, ['href', 'src'], true) && str_starts_with($nilai, 'javascript:'))) {
                    $atributHapus[] = $atribut->name;
                }
            }
            foreach ($atributHapus as $nama) {
                $node->removeAttribute($nama);
            }
        }

        foreach (iterator_to_array($node->childNodes) as $anak) {
            $this->buangAtributBerbahaya($anak);
        }
    }

    /**
     * Prefiks tiap selektor CSS dengan kelas pembungkus supaya definisi gaya
     * LibreOffice (mis. `.P1 { ... }`) hanya berlaku di dalam potongan yang
     * ditempel, bukan membocor ke seluruh dokumen notula menyatu. At-rule seperti
     *
     * @font-face dibiarkan apa adanya; @page (pengaturan ukuran halaman) dibuang
     * karena halaman notula gabungan sudah diatur sendiri oleh dokumen pembungkus.
     */
    private function lingkupkanCss(string $css, string $lingkup): string
    {
        $css = preg_replace('/@page[^{]*\{[^{}]*\}/i', '', $css) ?? $css;

        return preg_replace_callback('/([^{}@]+)\{([^{}]*)\}/', function (array $m) use ($lingkup) {
            $selektor = array_map(
                fn ($s) => trim($s) === '' ? '' : $lingkup.' '.trim($s),
                explode(',', $m[1])
            );

            return implode(', ', array_filter($selektor)).' {'.$m[2].'}';
        }, $css) ?? $css;
    }
}
