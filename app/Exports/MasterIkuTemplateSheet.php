<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet data Template Excel Master IKU (spek bagian 6.1) — 19 kolom (A-S), sesuai
 * struktur "Kertas Kerja Pengukuran Kinerja Triwulanan": hierarki Sasaran, tipe
 * indikator (Tipe A "%" pakai kolom I/L, Tipe B "Non %" kosongkan I/L), dan
 * Alokasi Target TW I-IV.
 *
 * Baris 1: header. Baris 2: contoh Tipe A ("%"). Baris 3: contoh Tipe B ("Non %").
 * Baris 4: petunjuk pengisian. Baris 5 dst.: diisi Tim SAKIP dengan data IKU
 * sesungguhnya. Kolom Q/R/S (Cek Total Alokasi/Target Acuan/Status) HANYA bantuan
 * visual Excel murni bagi pengisi manual — App\Services\MasterIkuImportValidator
 * TIDAK PERNAH membacanya, seluruh turunan dihitung ulang di server.
 *
 * Baris 2, 3 & 4 sengaja tidak boleh dihapus — MasterIkuImport memvalidasi
 * keberadaannya sebelum mengurai baris data.
 */
class MasterIkuTemplateSheet implements FromArray, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    public const BARIS_PETUNJUK = 'Petunjuk: mulai isi data IKU dari baris ke-5 ke bawah. Kode Indikator harus unik. Jenis Nilai "%" wajib mengisi kolom I-L (jumlah Alokasi Target TW I-IV harus sama dengan Target X, dan Target Tahunan harus sama dengan Target X ÷ Target Y × 100). Jenis Nilai "Non %" WAJIB mengosongkan kolom I-L (jumlah Alokasi Target TW I-IV harus sama dengan Target Tahunan). Jangan mengubah atau menghapus baris contoh (baris 2-3) dan baris petunjuk ini (baris 4) agar validasi unggahan berhasil.';

    public function array(): array
    {
        return [
            // Contoh Tipe A ("%"): X=8, Y=90 -> Target Tahunan 8,89%.
            [
                1, 'Persentase publikasi berkualitas',
                '1131', 'Persentase publikasi tepat waktu', 'Tahunan', '%', 'Persen', 8.89,
                'Jumlah publikasi tepat waktu', 8, 'Jumlah seluruh publikasi', 90,
                2, 2, 2, 2, '', '', '',
            ],
            // Contoh Tipe B ("Non %"): Indeks Pelayanan Publik target 4,35.
            [
                2, 'Kepuasan layanan publik',
                '1132', 'Indeks Pelayanan Publik', 'Triwulanan', 'Non %', 'Poin', 4.35,
                '', '', '', '',
                1.09, 1.08, 1.09, 1.09, '', '', '',
            ],
            [self::BARIS_PETUNJUK, ...array_fill(0, 18, '')],
        ];
    }

    public function headings(): array
    {
        return [
            'No.', 'Nama Sasaran',
            'Kode Indikator', 'Indikator Kinerja', 'Jenis Periode', 'Jenis Nilai', 'Satuan',
            'Target Tahunan', 'Deskripsi X (Pembilang)', 'Target X (Pembilang)', 'Deskripsi Y (Penyebut)', 'Target Y (Penyebut)',
            'Alokasi Target TW I', 'Alokasi Target TW II', 'Alokasi Target TW III', 'Alokasi Target TW IV',
            'Cek Total Alokasi', 'Target Acuan', 'Status',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 6, 'B' => 30,
            'C' => 16, 'D' => 40, 'E' => 14, 'F' => 12, 'G' => 12,
            'H' => 14, 'I' => 26, 'J' => 14, 'K' => 26, 'L' => 14,
            'M' => 16, 'N' => 16, 'O' => 16, 'P' => 16,
            'Q' => 16, 'R' => 14, 'S' => 14,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        // Kolom Q (Cek Total Alokasi): bantuan visual Excel murni, jumlah M:P
        // dibandingkan terhadap J/Target X (Tipe "%") atau H/Target Tahunan (Tipe
        // "Non %") — TIDAK pernah dibaca importer (lihat docblock kelas).
        foreach ([2, 3] as $barisContoh) {
            $sheet->setCellValue("Q{$barisContoh}", "=SUM(M{$barisContoh}:P{$barisContoh})");
        }

        return [
            1 => ['font' => ['bold' => true]],
            2 => ['font' => ['italic' => true]],
            3 => ['font' => ['italic' => true]],
            4 => ['font' => ['italic' => true, 'color' => ['rgb' => '64748B']]],
        ];
    }

    public function title(): string
    {
        return 'Master_IKU';
    }
}
