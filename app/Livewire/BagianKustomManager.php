<?php

namespace App\Livewire;

use App\Models\BagianKustom;
use Livewire\Component;

/**
 * Tim SAKIP — kelola definisi bagian tambahan (mis. "Manajemen Risiko") yang muncul
 * sebagai bagian baru di Isian Kegiatan (Ketua Tim) tanpa perlu mengubah kode.
 */
class BagianKustomManager extends Component
{
    public string $namaBaru = '';

    public string $deskripsiBaru = '';

    public bool $wajibAkhirTriwulanBaru = false;

    /**
     * Koreksi nama/deskripsi bagian yang sudah ada, dikunci pada id — supaya Tim SAKIP
     * bisa mengedit langsung di daftar tanpa modal terpisah.
     *
     * @var array<int, array{nama: string, deskripsi: ?string}>
     */
    public array $edit = [];

    public function mount(): void
    {
        foreach ($this->daftar() as $bagian) {
            $this->edit[$bagian->id] = ['nama' => $bagian->nama, 'deskripsi' => $bagian->deskripsi];
        }
    }

    public function daftar()
    {
        return BagianKustom::orderBy('id')->withCount('poin')->get();
    }

    protected function rules(): array
    {
        return [
            'namaBaru' => ['required', 'string', 'max:255'],
            'deskripsiBaru' => ['nullable', 'string'],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['namaBaru' => 'nama bagian'];
    }

    public function tambah(): void
    {
        $this->validate();

        $sudahAda = BagianKustom::whereRaw('LOWER(nama) = ?', [mb_strtolower(trim($this->namaBaru))])->exists();

        if ($sudahAda) {
            $this->addError('namaBaru', 'Bagian dengan nama tersebut sudah ada.');

            return;
        }

        $bagian = BagianKustom::create([
            'nama' => trim($this->namaBaru),
            'deskripsi' => trim($this->deskripsiBaru) ?: null,
            'wajib_akhir_triwulan' => $this->wajibAkhirTriwulanBaru,
            'aktif' => true,
        ]);

        $this->edit[$bagian->id] = ['nama' => $bagian->nama, 'deskripsi' => $bagian->deskripsi];

        $this->reset(['namaBaru', 'deskripsiBaru', 'wajibAkhirTriwulanBaru']);

        BagianKustom::lupakanCache();
        session()->flash('status', 'Bagian kustom "'.$bagian->nama.'" berhasil ditambahkan.');
    }

    public function simpanEdit(int $id): void
    {
        $data = $this->edit[$id] ?? null;

        if (! $data || trim($data['nama']) === '') {
            $this->addError("edit.{$id}.nama", 'Nama bagian tidak boleh kosong.');

            return;
        }

        BagianKustom::whereKey($id)->update([
            'nama' => trim($data['nama']),
            'deskripsi' => trim($data['deskripsi'] ?? '') ?: null,
        ]);

        BagianKustom::lupakanCache();
        session()->flash('status', 'Perubahan disimpan.');
    }

    public function toggleAktif(int $id): void
    {
        $bagian = BagianKustom::findOrFail($id);
        $bagian->update(['aktif' => ! $bagian->aktif]);

        BagianKustom::lupakanCache();
    }

    public function toggleWajib(int $id): void
    {
        $bagian = BagianKustom::findOrFail($id);
        $bagian->update(['wajib_akhir_triwulan' => ! $bagian->wajib_akhir_triwulan]);

        BagianKustom::lupakanCache();
    }

    /**
     * Hapus permanen HANYA bila belum ada satu pun poin tersimpan untuk bagian ini —
     * bila sudah ada isian, nonaktifkan saja (toggleAktif) supaya data historis tidak hilang.
     */
    public function hapus(int $id): void
    {
        $bagian = BagianKustom::withCount('poin')->findOrFail($id);

        if ($bagian->poin_count > 0) {
            $this->addError('hapus', 'Bagian "'.$bagian->nama.'" sudah punya isian tersimpan — nonaktifkan saja, jangan dihapus, supaya data lama tidak hilang.');

            return;
        }

        $bagian->delete();
        unset($this->edit[$id]);

        BagianKustom::lupakanCache();
        session()->flash('status', 'Bagian kustom dihapus.');
    }

    public function render()
    {
        return view('livewire.bagian-kustom-manager', [
            'daftar' => $this->daftar(),
        ]);
    }
}
