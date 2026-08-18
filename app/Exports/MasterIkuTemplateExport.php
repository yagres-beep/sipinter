<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Template Excel Master IKU (RF-05) — dua sheet: "Master IKU" (diisi &amp; diunggah
 * kembali, lihat MasterIkuTemplateSheet) dan "Daftar Nama" (referensi nama pengguna
 * terverifikasi untuk disalin ke kolom Penanggung Jawab, lihat DaftarNamaSheet).
 */
class MasterIkuTemplateExport implements WithMultipleSheets
{
    /**
     * @return array<int, \Maatwebsite\Excel\Concerns\FromCollection|\Maatwebsite\Excel\Concerns\FromArray>
     */
    public function sheets(): array
    {
        return [
            new MasterIkuTemplateSheet,
            new DaftarNamaSheet,
        ];
    }
}
