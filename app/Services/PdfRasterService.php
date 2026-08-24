<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Merasterisasi tiap halaman PDF menjadi gambar PNG lewat Poppler `pdftoppm`,
 * dipakai NotulaService untuk menempelkan Bagian II/III berformat PDF (mis. hasil
 * pindai/tanda tangan basah) sebagai blok gambar berurutan pada dokumen notula
 * menyatu (lihat pdf.notula-utuh).
 *
 * CATATAN FIDELITAS: konten hasil rasterisasi TIDAK bisa reflow seperti teks —
 * ia mengalir sebagai blok gambar utuh, sehingga bila lebih besar dari sisa ruang
 * halaman akan pindah ke halaman berikutnya (perilaku wajar, bukan bug). Bila
 * berkas sumbernya berupa dokumen teks (docx/xlsx), pakai jalur
 * LibreOfficeConversionService::convertToHtml() supaya bisa reflow/menyambung.
 *
 * PENTING: Poppler BUKAN paket Composer — ini utilitas command-line terpisah yang
 * harus dipasang manual di server/komputer yang menjalankan SIPINTER.
 *
 * === Cara pasang di Windows ===
 * 1. Unduh build Windows dari https://github.com/oschwartz10612/poppler-windows/releases
 *    (rilis "masih didukung"/terbaru), ekstrak ke folder mis. C:\Program Files\poppler.
 * 2. Setelah diekstrak, executable command-line-nya ada di:
 *      C:\Program Files\poppler\Library\bin\pdftoppm.exe
 *    Path ini SUDAH menjadi nilai default POPPLER_PDFTOPPM_PATH di .env. Sesuaikan
 *    bila lokasi ekstraksi berbeda.
 * 3. TIDAK perlu menambahkan Poppler ke PATH sistem Windows — service ini memanggil
 *    executable langsung lewat path lengkap dari
 *    config('services.poppler.pdftoppm_path'), bukan lewat nama perintah pendek.
 * 4. Uji manual dari terminal (opsional, untuk memastikan instalasi benar):
 *      & "C:\Program Files\poppler\Library\bin\pdftoppm.exe" -png -r 150 contoh.pdf hal
 *    Bila menghasilkan hal-1.png, hal-2.png, dst. di folder yang sama, instalasi
 *    sudah benar.
 */
class PdfRasterService
{
    /**
     * Rasterisasi tiap halaman $pdfPath menjadi PNG di $outputDir, lalu kembalikan
     * potongan HTML siap tempel berisi <img> data: URI berurutan sesuai halaman.
     * Berkas PNG sementara DIHAPUS sebelum method ini kembali.
     */
    public function rasterizeToInlineHtml(string $pdfPath, string $outputDir): string
    {
        if (! is_file($pdfPath)) {
            throw new RuntimeException("Berkas PDF tidak ditemukan: {$pdfPath}");
        }

        $binary = config('services.poppler.pdftoppm_path');

        if (! is_string($binary) || ! is_file($binary)) {
            throw new RuntimeException(
                "Poppler (pdftoppm) tidak ditemukan di: {$binary}. Pasang Poppler dan/atau sesuaikan ".
                'POPPLER_PDFTOPPM_PATH di .env — lihat petunjuk instalasi di PdfRasterService.php.'
            );
        }

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $prefix = rtrim($outputDir, '/\\').DIRECTORY_SEPARATOR.'raster-'.uniqid();

        // -png   : format keluaran PNG.
        // -r 150 : resolusi 150 DPI — cukup tajam untuk dibaca di layar/cetak,
        //          tanpa membuat berkas HTML gabungan jadi terlalu besar.
        $process = new Process([$binary, '-png', '-r', '150', $pdfPath, $prefix]);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $berkasHalaman = glob($prefix.'*.png') ?: [];
        natsort($berkasHalaman);

        if (empty($berkasHalaman)) {
            throw new RuntimeException("Rasterisasi Poppler tidak menghasilkan gambar halaman di: {$prefix}*.png");
        }

        $html = '<div class="notula-inline notula-rasterisasi">';
        foreach ($berkasHalaman as $path) {
            $html .= '<img src="data:image/png;base64,'.base64_encode((string) file_get_contents($path)).
                '" style="width:100%;display:block;margin-bottom:6px">';
            @unlink($path);
        }
        $html .= '</div>';

        return $html;
    }
}
