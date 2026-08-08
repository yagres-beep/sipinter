<?php

namespace App\Exports;

use App\Models\Lakin;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Unduhan Excel satu dokumen LAKIN (RF baru) — satu baris per indikator, sama persis
 * dengan yang tampil di LakinDetail (termasuk baris custom yang ditambah Tim SAKIP).
 */
class LakinExport implements FromCollection, WithColumnWidths, WithHeadings, WithStyles
{
    public function __construct(protected Lakin $lakin) {}

    public function collection()
    {
        return $this->lakin->baris->map(fn ($baris) => [
            $baris->sasaran,
            $baris->masterIku?->kode,
            $baris->indikator,
            $baris->target,
            $baris->realisasi,
            $baris->capaian_persen !== null ? $baris->capaian_persen.'%' : null,
        ]);
    }

    public function headings(): array
    {
        return ['Sasaran', 'Kode IKU', 'Indikator Kinerja', 'Target', 'Realisasi', 'Capaian %'];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 24,
            'B' => 14,
            'C' => 50,
            'D' => 14,
            'E' => 14,
            'F' => 12,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
