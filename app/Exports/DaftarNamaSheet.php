<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet referensi "Daftar Nama" pada Template Excel Master IKU — daftar pengguna
 * terverifikasi (Nama, Peran, Tim) supaya kolom Penanggung Jawab (Tim) pada sheet
 * Master_IKU maupun form Master IKU bisa diisi dengan menyalin nama tim dari sini,
 * bukan mengetik ulang. Hanya referensi/bantuan pengisian — MasterIkuImport tidak
 * membaca sheet ini.
 */
class DaftarNamaSheet implements FromCollection, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    public function collection(): Collection
    {
        return User::with('role')
            ->where('status_verifikasi', 'terverifikasi')
            ->orderBy('nama')
            ->get()
            ->map(fn (User $user) => [
                $user->nama,
                $user->namaRole(),
                implode(', ', $user->namaTimList()) ?: '—',
            ]);
    }

    public function headings(): array
    {
        return ['Nama', 'Peran', 'Tim'];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 30,
            'B' => 16,
            'C' => 30,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    public function title(): string
    {
        return 'Daftar Nama';
    }
}
