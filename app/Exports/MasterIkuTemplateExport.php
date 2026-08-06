<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Template Excel Master IKU (RF-05).
 *
 * Baris 1: header (Kode, Indikator, Tim, Penanggung Jawab).
 * Baris 2: contoh pengisian.
 * Baris 3: petunjuk pengisian.
 * Baris 4 dst.: diisi Tim SAKIP dengan data IKU sesungguhnya.
 *
 * Baris 2 & 3 sengaja tidak boleh dihapus — MasterIkuImport memvalidasi keberadaannya
 * sebelum mengurai baris data (lihat RF-06: "baris contoh dan petunjuk tidak boleh
 * dihapus agar validasi berhasil").
 */
class MasterIkuTemplateExport implements FromArray, WithColumnWidths, WithHeadings, WithStyles
{
    public const BARIS_PETUNJUK = 'Petunjuk: mulai isi data IKU dari baris ke-4 ke bawah. Kode harus unik. Jangan mengubah atau menghapus baris contoh (baris 2) dan baris petunjuk ini (baris 3) agar validasi unggahan berhasil.';

    public function array(): array
    {
        return [
            ['IKU-1131', 'Persentase publikasi tepat waktu', 'Produksi Statistik', 'Nama Penanggung Jawab'],
            [self::BARIS_PETUNJUK, '', '', ''],
        ];
    }

    public function headings(): array
    {
        return ['Kode', 'Indikator', 'Tim', 'Penanggung Jawab'];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 18,
            'B' => 48,
            'C' => 22,
            'D' => 28,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
            2 => ['font' => ['italic' => true]],
            3 => ['font' => ['italic' => true, 'color' => ['rgb' => '64748B']]],
        ];
    }
}
