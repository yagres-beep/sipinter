<?php

namespace App\Livewire;

use App\Exports\MasterIkuTemplateExport;
use App\Imports\MasterIkuImport;
use App\Models\MasterIku as MasterIkuModel;
use App\Models\User;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Livewire\Component;
use Livewire\WithFileUploads;

class MasterIku extends Component
{
    use WithFileUploads;

    public $excelFile = null;

    public ?int $editingId = null;

    public ?int $pendingDeleteId = null;

    /** @var list<string>|null */
    public ?array $alasanTidakBisaHapus = null;

    public string $kode = '';

    public string $indikator = '';

    public string $tim = '';

    public string $penanggungJawab = '';

    public string $sasaran = '';

    public string $satuan = 'Persen';

    protected function rules(): array
    {
        return [
            'kode' => [
                'required', 'string', 'max:50',
                'unique:master_iku,kode,'.($this->editingId ?? 'NULL').',id',
            ],
            'indikator' => ['required', 'string'],
            'tim' => ['required', 'string', 'max:255'],
            'penanggungJawab' => ['required', 'string', 'max:255'],
            'sasaran' => ['nullable', 'string', 'max:255'],
            'satuan' => ['required', 'string', 'in:Persen,Poin'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'kode' => 'kode',
            'indikator' => 'indikator',
            'tim' => 'tim',
            'penanggungJawab' => 'penanggung jawab',
            'sasaran' => 'sasaran',
            'satuan' => 'satuan',
        ];
    }

    public function downloadTemplate()
    {
        return ExcelFacade::download(new MasterIkuTemplateExport, 'template-master-iku.xlsx');
    }

    public function uploadExcel(): void
    {
        $this->validate([
            'excelFile' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ]);

        $import = new MasterIkuImport;

        ExcelFacade::import($import, $this->excelFile);

        MasterIkuModel::lupakanCache();

        if ($import->errors) {
            session()->flash('excelErrors', $import->errors);
        } else {
            session()->flash('status', "Berhasil mengunggah {$import->imported} baris data IKU.");
        }

        $this->reset('excelFile');
    }

    public function edit(int $id): void
    {
        $iku = MasterIkuModel::findOrFail($id);

        $this->editingId = $iku->id;
        $this->kode = $iku->kode;
        $this->indikator = $iku->indikator;
        $this->tim = $iku->tim;
        $this->penanggungJawab = $iku->penanggung_jawab;
        $this->sasaran = $iku->sasaran ?? '';
        $this->satuan = $iku->satuan;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'kode', 'indikator', 'tim', 'penanggungJawab', 'sasaran']);
        $this->satuan = 'Persen';
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            // Kode dipakai polos angka/kodenya saja (mis. "1131"), tanpa awalan "IKU-"
            // — dinormalisasi di sini juga (bukan cuma migrasi backfill data lama) supaya
            // tetap konsisten walau seseorang terbiasa mengetik "IKU-1131" di kolom Kode.
            'kode' => preg_replace('/^\D+/', '', trim($this->kode)) ?: trim($this->kode),
            'indikator' => $this->indikator,
            'tim' => $this->tim,
            'penanggung_jawab' => $this->penanggungJawab,
            'sasaran' => $this->sasaran ?: null,
            'satuan' => $this->satuan,
        ];

        if ($this->editingId) {
            MasterIkuModel::whereKey($this->editingId)->update($data);
        } else {
            MasterIkuModel::create($data);
        }

        MasterIkuModel::lupakanCache();

        session()->flash('status', $this->editingId ? 'IKU berhasil diperbarui.' : 'IKU baru berhasil ditambahkan.');

        $this->cancelEdit();
    }

    public function confirmDelete(int $id): void
    {
        // Dicek DI SINI, sebelum modal konfirmasi muncul — supaya Tim SAKIP langsung
        // diberi tahu KENAPA tidak bisa dihapus, bukan baru tahu setelah klik Hapus
        // dan gagal (lihat relasiYangMenghalangiHapus() untuk daftar relasinya).
        $iku = MasterIkuModel::findOrFail($id);

        $this->pendingDeleteId = $id;
        $this->alasanTidakBisaHapus = $iku->relasiYangMenghalangiHapus() ?: null;
    }

    public function cancelDelete(): void
    {
        $this->pendingDeleteId = null;
        $this->alasanTidakBisaHapus = null;
    }

    public function delete(): void
    {
        if (! $this->pendingDeleteId || $this->alasanTidakBisaHapus) {
            return;
        }

        try {
            MasterIkuModel::whereKey($this->pendingDeleteId)->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            // Jaring pengaman kalau ada relasi baru muncul di antara confirmDelete()
            // dicek dan tombol Hapus benar-benar diklik (mis. dua tab dibuka
            // bersamaan) — 23503 = foreign_key_violation (Postgres).
            if ($e->getCode() === '23503') {
                $this->pendingDeleteId = null;
                $this->alasanTidakBisaHapus = null;
                session()->flash('error', 'IKU ini tidak bisa dihapus karena masih ada data terkait yang baru saja ditambahkan. Muat ulang halaman lalu coba lagi.');

                return;
            }

            throw $e;
        }

        MasterIkuModel::lupakanCache();

        $this->pendingDeleteId = null;

        session()->flash('status', 'IKU berhasil dihapus.');
    }

    public function render()
    {
        // Satu query untuk seluruh daftar IKU, dipakai ulang di PHP untuk saran
        // datalist Kode/Tim/Sasaran DAN untuk kode IKU yang mau dihapus — bukan
        // 4 query distinct() + 1 query find() terpisah seperti sebelumnya. Halaman
        // ini sengaja TIDAK memakai cache Laravel untuk ikuList sendiri (supaya
        // perubahan sendiri selalu terlihat instan), tapi query distinct terpisah
        // untuk saran datalist itu murni pemborosan — datanya sudah ada di sini.
        $ikuList = MasterIkuModel::orderBy('kode')->get();

        $daftarPenanggungJawab = $ikuList->pluck('penanggung_jawab')->filter()
            ->merge(User::where('status_verifikasi', 'terverifikasi')->orderBy('nama')->pluck('nama'))
            ->unique()
            ->sort()
            ->values();

        return view('livewire.master-iku', [
            'ikuList' => $ikuList,
            'totalIndikator' => $ikuList->count(),
            'daftarKode' => $ikuList->pluck('kode')->filter()->unique()->sort()->values()->all(),
            'daftarTim' => $ikuList->pluck('tim')->filter()->unique()->sort()->values()->all(),
            'daftarSasaran' => $ikuList->pluck('sasaran')->filter()->unique()->sort()->values()->all(),
            'daftarPenanggungJawab' => $daftarPenanggungJawab,
            'pendingDeleteKode' => $this->pendingDeleteId
                ? $ikuList->firstWhere('id', $this->pendingDeleteId)?->kode
                : null,
        ]);
    }
}
