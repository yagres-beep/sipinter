<?php

namespace App\Imports;

use App\Models\MasterIku;
use App\Services\MasterIkuImportValidator;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * Mengurai berkas Excel Master IKU (spek bagian 6).
 *
 * Memvalidasi bahwa header, DUA baris contoh (baris 2-3), dan baris petunjuk
 * (baris 4) dari template resmi masih utuh, lalu mendelegasikan setiap baris data
 * (baris 5 dst.) ke App\Services\MasterIkuImportValidator — HANYA membaca &
 * mengurai, TIDAK PERNAH menulis ke DB sendiri (itu tugas tahap konfirmasi di
 * App\Livewire\MasterIku::konfirmasiImpor(), lihat $hasilValidasi di sini).
 *
 * Template resmi (lihat MasterIkuTemplateExport) berisi DUA sheet: "Master_IKU"
 * (data yang diproses di sini) dan "Daftar Nama" (referensi, ikut terunggah balik
 * tanpa diubah pengguna). Tanpa WithMultipleSheets, Maatwebsite Excel memanggil
 * collection() untuk SETIAP sheet pada berkas — sheet "Daftar Nama" dikenali lewat
 * headernya sendiri dan dilewati diam-diam (bukan error) di collection().
 */
class MasterIkuImport implements ToCollection
{
    public const EXPECTED_HEADER = [
        'No.', 'Nama Sasaran',
        'Kode Indikator', 'Penanggung Jawab (Tim)', 'Indikator Kinerja',
        'Dasar Hitung', 'Basis Data',
        'Jenis Periode', 'Jenis Nilai', 'Satuan',
        'Target Tahunan', 'Deskripsi X (Pembilang)', 'Target X (Pembilang)', 'Deskripsi Y (Penyebut)', 'Target Y (Penyebut)',
        'Alokasi Target TW I', 'Alokasi Target TW II', 'Alokasi Target TW III', 'Alokasi Target TW IV',
        'Cek Total Alokasi', 'Target Acuan', 'Status',
    ];

    public const HEADER_SHEET_REFERENSI = ['Nama', 'Peran', 'Tim'];

    /** @var list<string> pesan error struktural (header/baris contoh/petunjuk hilang) — berkas ditolak SELURUHNYA bila terisi */
    public array $errors = [];

    /**
     * Hasil validasi per baris data (lihat MasterIkuImportValidator::validasiSemua()),
     * kosong bila $errors terisi (berkas ditolak sebelum sampai baris data).
     *
     * @var list<array{baris: int, valid: bool, data: array|null, errors: list<string>}>
     */
    public array $hasilValidasi = [];

    public function __construct(
        protected bool $modeUpsert = false,
    ) {}

    public function collection(Collection $rows): void
    {
        $header = $this->normalizeRow($rows->get(0), count(self::EXPECTED_HEADER));

        if (array_slice($header, 0, 3) === self::HEADER_SHEET_REFERENSI) {
            // Sheet referensi "Daftar Nama" bawaan template — bukan data untuk diimpor.
            return;
        }

        if ($header !== self::EXPECTED_HEADER) {
            $this->errors[] = 'Format kolom tidak sesuai template resmi Master_IKU. Unduh ulang template terbaru dan jangan mengubah baris header.';

            return;
        }

        $contohPersen = $this->rowToArray($rows->get(1));
        $contohNonPersen = $this->rowToArray($rows->get(2));

        if ($this->rowKosong($contohPersen) || $this->rowKosong($contohNonPersen)) {
            $this->errors[] = 'Baris contoh (baris ke-2 dan ke-3) pada template tidak boleh dihapus.';

            return;
        }

        $petunjuk = trim((string) (collect($rows->get(3) ?? [])->get(0) ?? ''));

        if (! str_contains($petunjuk, 'Petunjuk')) {
            $this->errors[] = 'Baris petunjuk (baris ke-4) pada template tidak boleh dihapus atau diubah.';

            return;
        }

        $barisData = $rows->slice(4)->map(fn ($row) => $this->rowToArray($row))->values()->all();

        $kodeSudahAdaDiDb = MasterIku::pluck('kode');

        $this->hasilValidasi = MasterIkuImportValidator::validasiSemua($barisData, 5, $this->modeUpsert, $kodeSudahAdaDiDb);
    }

    /**
     * @return array<int, mixed>
     */
    protected function rowToArray(mixed $row): array
    {
        return collect($row ?? [])->values()->all();
    }

    /**
     * @param  array<int, mixed>  $row
     */
    protected function rowKosong(array $row): bool
    {
        return collect($row)->filter(fn ($v) => trim((string) $v) !== '')->isEmpty();
    }

    /**
     * @return list<string>
     */
    protected function normalizeRow(mixed $row, int $panjang): array
    {
        $row = collect($row ?? []);

        return collect(range(0, $panjang - 1))
            ->map(fn ($i) => trim((string) ($row->get($i) ?? '')))
            ->all();
    }
}
