<?php

namespace App\Imports;

use App\Models\MasterIku;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * Mengurai berkas Excel Master IKU (RF-06, RF-07).
 *
 * Memvalidasi bahwa header, baris contoh (baris 2), dan baris petunjuk (baris 3)
 * dari template resmi masih utuh sebelum mengurai baris data (baris 4 dst.) ke
 * tabel master_ikus. Bila salah satu bagian template hilang/berubah, seluruh
 * berkas ditolak dan $errors berisi alasannya.
 *
 * Template resmi (lihat MasterIkuTemplateExport) berisi DUA sheet: "Master IKU"
 * (data yang diproses di sini) dan "Daftar Nama" (referensi, ikut terunggah balik
 * tanpa diubah pengguna). Tanpa WithMultipleSheets, Maatwebsite Excel memanggil
 * collection() untuk SETIAP sheet pada berkas — sheet "Daftar Nama" dikenali lewat
 * headernya sendiri dan dilewati diam-diam (bukan error) di collection().
 */
class MasterIkuImport implements ToCollection
{
    public const EXPECTED_HEADER = ['Kode', 'Indikator', 'Tim', 'Penanggung Jawab', 'Sasaran'];

    public const HEADER_SHEET_REFERENSI = ['Nama', 'Peran', 'Tim'];

    /** @var list<string> */
    public array $errors = [];

    public int $imported = 0;

    public function collection(Collection $rows): void
    {
        $header = $this->normalizeRow($rows->get(0));

        if (array_slice($header, 0, 3) === self::HEADER_SHEET_REFERENSI) {
            // Sheet referensi "Daftar Nama" bawaan template — bukan data untuk diimpor.
            return;
        }

        if ($header !== self::EXPECTED_HEADER) {
            $this->errors[] = 'Format kolom tidak sesuai template resmi. Kolom yang diharapkan: Kode, Indikator, Tim, Penanggung Jawab, Sasaran.';

            return;
        }

        $contoh = $this->normalizeRow($rows->get(1));

        if ($contoh === ['', '', '', '', '']) {
            $this->errors[] = 'Baris contoh (baris ke-2) pada template tidak boleh dihapus.';

            return;
        }

        $petunjuk = $this->normalizeRow($rows->get(2));

        if (! str_contains($petunjuk[0] ?? '', 'Petunjuk')) {
            $this->errors[] = 'Baris petunjuk (baris ke-3) pada template tidak boleh dihapus atau diubah.';

            return;
        }

        foreach ($rows->slice(3) as $i => $row) {
            $baris = $i + 4;

            $kode = trim((string) ($row[0] ?? ''));
            $indikator = trim((string) ($row[1] ?? ''));
            $tim = trim((string) ($row[2] ?? ''));
            $penanggungJawab = trim((string) ($row[3] ?? ''));
            $sasaran = trim((string) ($row[4] ?? ''));

            if ($kode === '' && $indikator === '' && $tim === '' && $penanggungJawab === '' && $sasaran === '') {
                continue;
            }

            if ($kode === '' || $indikator === '' || $tim === '' || $penanggungJawab === '') {
                $this->errors[] = "Baris {$baris}: kolom Kode, Indikator, Tim, dan Penanggung Jawab wajib diisi.";

                continue;
            }

            MasterIku::updateOrCreate(
                ['kode' => $kode],
                [
                    'indikator' => $indikator,
                    'tim' => $tim,
                    'penanggung_jawab' => $penanggungJawab,
                    'sasaran' => $sasaran ?: null,
                ]
            );

            $this->imported++;
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    protected function normalizeRow(mixed $row): array
    {
        $row = collect($row ?? []);

        return [
            trim((string) ($row->get(0) ?? '')),
            trim((string) ($row->get(1) ?? '')),
            trim((string) ($row->get(2) ?? '')),
            trim((string) ($row->get(3) ?? '')),
            trim((string) ($row->get(4) ?? '')),
        ];
    }
}
