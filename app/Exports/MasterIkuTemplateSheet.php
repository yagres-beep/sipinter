<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet data Template Excel Master IKU (spek bagian 6.1) — 20 kolom (A-T), sesuai
 * struktur "Kertas Kerja Pengukuran Kinerja Triwulanan": hierarki Sasaran, tipe
 * indikator (Tipe A "%" pakai kolom J/M, Tipe B "Non %" kosongkan J/M), dan
 * Alokasi Target TW I-IV.
 *
 * Baris 1: header. Baris 2: contoh Tipe A ("%"). Baris 3: contoh Tipe B ("Non %").
 * Baris 4: petunjuk pengisian. Baris 5 dst.: diisi Tim SAKIP dengan data IKU
 * sesungguhnya. Kolom R/S/T (Cek Total Alokasi/Target Acuan/Status) HANYA bantuan
 * visual Excel murni bagi pengisi manual — App\Services\MasterIkuImportValidator
 * TIDAK PERNAH membacanya, seluruh turunan dihitung ulang di server.
 *
 * Baris 2, 3 & 4 sengaja tidak boleh dihapus — MasterIkuImport memvalidasi
 * keberadaannya sebelum mengurai baris data.
 */
class MasterIkuTemplateSheet implements FromArray, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    public const BARIS_PETUNJUK = 'Petunjuk: mulai isi data IKU dari baris ke-5 ke bawah. Kode Indikator harus unik. Jenis Nilai "%" wajib mengisi kolom J-M (jumlah Alokasi Target TW I-IV harus sama dengan Target X, dan Target Tahunan harus sama dengan Target X ÷ Target Y × 100). Jenis Nilai "Non %" WAJIB mengosongkan kolom J-M (jumlah Alokasi Target TW I-IV harus sama dengan Target Tahunan). Jangan mengubah atau menghapus baris contoh (baris 2-3) dan baris petunjuk ini (baris 4) agar validasi unggahan berhasil.';

    public function array(): array
    {
        return [
            // Contoh Tipe A ("%"): X=8, Y=90 -> Target Tahunan 8,89%.
            [
                1, 'S01', 'Persentase publikasi berkualitas',
                '1131', 'Persentase publikasi tepat waktu', 'Tahunan', '%', 'Persen', 8.89,
                'Jumlah publikasi tepat waktu', 8, 'Jumlah seluruh publikasi', 90,
                2, 2, 2, 2, '', '', '',
            ],
            // Contoh Tipe B ("Non %"): Indeks Pelayanan Publik target 4,35.
            [
                2, 'S02', 'Kepuasan layanan publik',
                '1132', 'Indeks Pelayanan Publik', 'Triwulanan', 'Non %', 'Poin', 4.35,
                '', '', '', '',
                1.09, 1.08, 1.09, 1.09, '', '', '',
            ],
            [self::BARIS_PETUNJUK, ...array_fill(0, 19, '')],
        ];
    }

    public function headings(): array
    {
        return [
            'No.', 'Kode Sasaran', 'Nama Sasaran',
            'Kode Indikator', 'Indikator Kinerja', 'Jenis Periode', 'Jenis Nilai', 'Satuan',
            'Target Tahunan', 'Deskripsi X (Pembilang)', 'Target X (Pembilang)', 'Deskripsi Y (Penyebut)', 'Target Y (Penyebut)',
            'Alokasi Target TW I', 'Alokasi Target TW II', 'Alokasi Target TW III', 'Alokasi Target TW IV',
            'Cek Total Alokasi', 'Target Acuan', 'Status',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 6, 'B' => 14, 'C' => 30,
            'D' => 16, 'E' => 40, 'F' => 14, 'G' => 12, 'H' => 12,
            'I' => 14, 'J' => 26, 'K' => 14, 'L' => 26, 'M' => 14,
            'N' => 16, 'O' => 16, 'P' => 16, 'Q' => 16,
            'R' => 16, 'S' => 14, 'T' => 14,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        // Kolom R (Cek Total Alokasi): bantuan visual Excel murni, jumlah N:Q
        // dibandingkan terhadap K/Target X (Tipe "%") atau I/Target Tahunan (Tipe
        // "Non %") — TIDAK pernah dibaca importer (lihat docblock kelas).
        foreach ([2, 3] as $barisContoh) {
            $sheet->setCellValue("R{$barisContoh}", "=SUM(N{$barisContoh}:Q{$barisContoh})");
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
