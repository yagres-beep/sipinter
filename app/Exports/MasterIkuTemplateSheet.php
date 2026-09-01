<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet data Template Excel Master IKU (spek bagian 6.1) — 22 kolom (A-V), sesuai
 * struktur "Kertas Kerja Pengukuran Kinerja Triwulanan": hierarki Sasaran, tipe
 * indikator (Tipe A "%" pakai kolom L/O, Tipe B "Non %" kosongkan L/O), dan
 * Alokasi Target TW I-IV.
 *
 * Baris 1: header. Baris 2: contoh Tipe A ("%"). Baris 3: contoh Tipe B ("Non %").
 * Baris 4: petunjuk pengisian. Baris 5 dst.: diisi Tim SAKIP dengan data IKU
 * sesungguhnya. Kolom T/U/V (Cek Total Alokasi/Target Acuan/Status) HANYA bantuan
 * visual Excel murni bagi pengisi manual (diisi lewat formula sampai baris
 * BARIS_FORMULA_SAMPAI supaya siap dipakai begitu Tim SAKIP mulai mengetik) —
 * App\Services\MasterIkuImportValidator TIDAK PERNAH membacanya, seluruh turunan
 * dihitung ulang di server.
 *
 * Baris 2, 3 & 4 sengaja tidak boleh dihapus — MasterIkuImport memvalidasi
 * keberadaannya sebelum mengurai baris data.
 */
class MasterIkuTemplateSheet implements FromArray, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    public const BARIS_PETUNJUK = 'Petunjuk: mulai isi data IKU dari baris ke-5 ke bawah. Kode Indikator harus unik. Kolom Penanggung Jawab (Tim) wajib diisi — boleh tim baru atau salin dari sheet "Daftar Nama". Kolom Dasar Hitung & Basis Data boleh dikosongkan. Jenis Nilai "%" wajib mengisi kolom L-O (jumlah Alokasi Target TW I-IV harus sama dengan Target X, dan Target Tahunan harus sama dengan Target X ÷ Target Y × 100). Jenis Nilai "Non %" WAJIB mengosongkan kolom L-O (jumlah Alokasi Target TW I-IV harus sama dengan Target Tahunan). Jangan mengubah atau menghapus baris contoh (baris 2-3) dan baris petunjuk ini (baris 4) agar validasi unggahan berhasil.';

    /**
     * Formula bantuan (Cek Total Alokasi/Target Acuan/Status) disiapkan sampai
     * baris ini supaya sudah aktif begitu Tim SAKIP mengetik data baru — tanpa
     * batas ini template akan membengkak sia-sia untuk ribuan baris yang mungkin
     * tidak pernah dipakai.
     */
    private const BARIS_FORMULA_SAMPAI = 300;

    public function array(): array
    {
        return [
            // Contoh Tipe A ("%"): X=8, Y=90 -> Target Tahunan 8,89%.
            [
                1, 'Persentase publikasi berkualitas',
                '1131', 'Produksi Statistik', 'Persentase publikasi tepat waktu',
                'y = [[n|N]] x 100%', 'Data internal BPS, hasil survei',
                'Tahunan', '%', 'Persen', 8.89,
                'Jumlah publikasi tepat waktu', 8, 'Jumlah seluruh publikasi', 90,
                2, 2, 2, 2, '', '', '',
            ],
            // Contoh Tipe B ("Non %"): Indeks Pelayanan Publik target 4,35.
            [
                2, 'Kepuasan layanan publik',
                '1132', 'Pelayanan Publik', 'Indeks Pelayanan Publik',
                'Rata-rata skor survei kepuasan layanan publik', 'Hasil survei kepuasan pengguna layanan',
                'Triwulanan', 'Non %', 'Poin', 4.35,
                '', '', '', '',
                1.09, 1.08, 1.09, 1.09, '', '', '',
            ],
            [self::BARIS_PETUNJUK, ...array_fill(0, 21, '')],
        ];
    }

    public function headings(): array
    {
        return [
            'No.', 'Nama Sasaran',
            'Kode Indikator', 'Penanggung Jawab (Tim)', 'Indikator Kinerja',
            'Dasar Hitung', 'Basis Data',
            'Jenis Periode', 'Jenis Nilai', 'Satuan',
            'Target Tahunan', 'Deskripsi X (Pembilang)', 'Target X (Pembilang)', 'Deskripsi Y (Penyebut)', 'Target Y (Penyebut)',
            'Alokasi Target TW I', 'Alokasi Target TW II', 'Alokasi Target TW III', 'Alokasi Target TW IV',
            'Cek Total Alokasi', 'Target Acuan', 'Status',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 6, 'B' => 30,
            'C' => 16, 'D' => 22, 'E' => 40,
            'F' => 30, 'G' => 26,
            'H' => 14, 'I' => 12, 'J' => 12,
            'K' => 14, 'L' => 26, 'M' => 14, 'N' => 26, 'O' => 14,
            'P' => 16, 'Q' => 16, 'R' => 16, 'S' => 16,
            'T' => 16, 'U' => 14, 'V' => 14,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        // Kolom T (Cek Total Alokasi): jumlah P:S. Kolom U (Target Acuan): Target X
        // (M) untuk Jenis Nilai "%", atau Target Tahunan (K) untuk "Non %". Kolom V
        // (Status): "OK" bila T sama dengan U (toleransi 0,01), "Cek lagi" bila
        // tidak — murni bantuan visual Excel bagi pengisi manual, gated ke kolom E
        // (Indikator Kinerja) supaya baris kosong tidak ikut ditampilkan "OK".
        // TIDAK pernah dibaca importer (lihat docblock kelas).
        for ($baris = 2; $baris <= self::BARIS_FORMULA_SAMPAI; $baris++) {
            $sheet->setCellValue("T{$baris}", "=IF(E{$baris}=\"\",\"\",SUM(P{$baris}:S{$baris}))");
            $sheet->setCellValue("U{$baris}", "=IF(E{$baris}=\"\",\"\",IF(I{$baris}=\"%\",M{$baris},K{$baris}))");
            $sheet->setCellValue("V{$baris}", "=IF(E{$baris}=\"\",\"\",IF(ABS(T{$baris}-U{$baris})<=0.01,\"OK\",\"Cek lagi\"))");
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
