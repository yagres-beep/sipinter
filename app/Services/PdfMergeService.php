<?php

namespace App\Services;

use RuntimeException;
use setasign\Fpdi\Fpdi;

/**
 * Menggabungkan beberapa berkas PDF menjadi satu, URUT sesuai urutan array masukan
 * (RF-42d: Bagian I → II → III).
 *
 * Dipilih setasign/fpdi (murni PHP lewat Composer) dari 3 opsi yang disebut SRS
 * (FPDI/pdftk/qpdf) karena PALING MUDAH dipasang di Windows — cukup
 * `composer require setasign/fpdi setasign/fpdi-fpdf`, TANPA perlu memasang binari
 * command-line terpisah + mengatur PATH seperti qpdf/pdftk.
 */
class PdfMergeService
{
    /**
     * @param  list<string>  $pdfPaths  urutan berkas PDF sumber, sesuai urutan akhir yang diinginkan.
     * @return string path lengkap PDF hasil gabungan.
     */
    public function merge(array $pdfPaths, string $outputPath): string
    {
        if (count($pdfPaths) === 0) {
            throw new RuntimeException('Tidak ada berkas PDF untuk digabungkan.');
        }

        foreach ($pdfPaths as $path) {
            if (! is_file($path)) {
                throw new RuntimeException("Berkas PDF tidak ditemukan: {$path}");
            }
        }

        $pdf = new Fpdi;

        foreach ($pdfPaths as $path) {
            $jumlahHalaman = $pdf->setSourceFile($path);

            for ($nomorHalaman = 1; $nomorHalaman <= $jumlahHalaman; $nomorHalaman++) {
                $templateId = $pdf->importPage($nomorHalaman);
                $ukuran = $pdf->getTemplateSize($templateId);

                $orientasi = $ukuran['width'] > $ukuran['height'] ? 'L' : 'P';
                $pdf->AddPage($orientasi, [$ukuran['width'], $ukuran['height']]);
                $pdf->useTemplate($templateId);
            }
        }

        $outputDir = dirname($outputPath);

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Mode 'F' = tulis langsung ke berkas di $outputPath (bukan dikirim ke browser).
        $pdf->Output('F', $outputPath);

        return $outputPath;
    }
}
