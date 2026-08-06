<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Mengonversi berkas Bagian II & III notula (.docx/.xlsx/gambar) menjadi PDF lewat
 * LibreOffice headless (RF-42a, RF-42b, SRS §5.1).
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
     * Konversi satu berkas (docx/xlsx/gambar/dll.) menjadi PDF, disimpan di $outputDir.
     * Nama berkas hasil SAMA dengan nama asli, hanya ekstensinya berubah jadi .pdf.
     *
     * @return string path lengkap ke PDF hasil konversi.
     */
    public function convertToPdf(string $inputPath, string $outputDir): string
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

        // --headless    : jalan tanpa membuka jendela GUI (cocok dipanggil dari server).
        // --convert-to  : format tujuan konversi.
        // --outdir      : folder tempat PDF hasil konversi disimpan.
        $process = new Process([
            $binary,
            '--headless',
            '--convert-to', 'pdf',
            '--outdir', $outputDir,
            $inputPath,
        ]);

        // Konversi dokumen besar (mis. xlsx banyak sheet, gambar resolusi tinggi)
        // bisa agak lama — beri waktu lebih longgar dari request HTTP biasa.
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $namaPdf = pathinfo($inputPath, PATHINFO_FILENAME).'.pdf';
        $outputPath = rtrim($outputDir, '/\\').DIRECTORY_SEPARATOR.$namaPdf;

        if (! is_file($outputPath)) {
            throw new RuntimeException("Konversi LibreOffice tidak menghasilkan berkas PDF yang diharapkan di: {$outputPath}");
        }

        return $outputPath;
    }

    /**
     * Berkas berformat PDF tidak perlu dikonversi — dipakai pemanggil (NotulaService)
     * untuk memutuskan apakah convertToPdf() perlu dipanggil sama sekali.
     */
    public function sudahPdf(string $filePath): bool
    {
        return strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'pdf';
    }
}
