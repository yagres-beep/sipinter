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

    public string $kode = '';

    public string $indikator = '';

    public string $tim = '';

    public string $penanggungJawab = '';

    public string $sasaran = '';

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

        $this->dispatch('scroll-ke-form-iku');
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'kode', 'indikator', 'tim', 'penanggungJawab', 'sasaran']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'kode' => $this->kode,
            'indikator' => $this->indikator,
            'tim' => $this->tim,
            'penanggung_jawab' => $this->penanggungJawab,
            'sasaran' => $this->sasaran ?: null,
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
        $this->pendingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->pendingDeleteId = null;
    }

    public function delete(): void
    {
        if (! $this->pendingDeleteId) {
            return;
        }

        MasterIkuModel::whereKey($this->pendingDeleteId)->delete();

        MasterIkuModel::lupakanCache();

        $this->pendingDeleteId = null;

        session()->flash('status', 'IKU berhasil dihapus.');
    }

    /**
     * Nilai yang sudah pernah dipakai untuk kolom Tim/Sasaran/Penanggung Jawab —
     * ditawarkan sebagai saran (datalist) di form Tambah/Ubah supaya penamaan
     * konsisten, tanpa mengunci pengguna: kolom tetap teks bebas, saran ini cuma
     * mempercepat pengisian ulang nilai yang sudah ada.
     */
    protected function daftarSaran(string $kolom): array
    {
        return MasterIkuModel::query()
            ->whereNotNull($kolom)
            ->where($kolom, '!=', '')
            ->distinct()
            ->orderBy($kolom)
            ->pluck($kolom)
            ->all();
    }

    public function render()
    {
        $daftarPenanggungJawab = collect($this->daftarSaran('penanggung_jawab'))
            ->merge(User::where('status_verifikasi', 'terverifikasi')->orderBy('nama')->pluck('nama'))
            ->unique()
            ->sort()
            ->values();

        return view('livewire.master-iku', [
            'ikuList' => MasterIkuModel::orderBy('kode')->get(),
            'totalIndikator' => MasterIkuModel::count(),
            'daftarTim' => $this->daftarSaran('tim'),
            'daftarSasaran' => $this->daftarSaran('sasaran'),
            'daftarPenanggungJawab' => $daftarPenanggungJawab,
            'pendingDeleteKode' => $this->pendingDeleteId
                ? MasterIkuModel::find($this->pendingDeleteId)?->kode
                : null,
        ]);
    }
}
