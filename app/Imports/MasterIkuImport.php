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
 */
class MasterIkuImport implements ToCollection
{
    public const EXPECTED_HEADER = ['Kode', 'Indikator', 'Tim', 'Penanggung Jawab'];

    /** @var list<string> */
    public array $errors = [];

    public int $imported = 0;

    public function collection(Collection $rows): void
    {
        $header = $this->normalizeRow($rows->get(0));

        if ($header !== self::EXPECTED_HEADER) {
            $this->errors[] = 'Format kolom tidak sesuai template resmi. Kolom yang diharapkan: Kode, Indikator, Tim, Penanggung Jawab.';

            return;
        }

        $contoh = $this->normalizeRow($rows->get(1));

        if ($contoh === ['', '', '', '']) {
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

            if ($kode === '' && $indikator === '' && $tim === '' && $penanggungJawab === '') {
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
                ]
            );

            $this->imported++;
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    protected function normalizeRow(mixed $row): array
    {
        $row = collect($row ?? []);

        return [
            trim((string) ($row->get(0) ?? '')),
            trim((string) ($row->get(1) ?? '')),
            trim((string) ($row->get(2) ?? '')),
            trim((string) ($row->get(3) ?? '')),
        ];
    }
}
