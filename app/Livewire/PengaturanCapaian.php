<?php

namespace App\Livewire;

use App\Models\PengaturanCapaian as PengaturanCapaianModel;
use Livewire\Component;

/**
 * Pengaturan Rumus Capaian (Tim SAKIP) — batas maksimal capaian yang dipakai rumus
 * resmi (Kertas Kerja Pengukuran Kinerja Triwulanan) tanpa dikodekan langsung
 * ("hard-coded"), supaya kalau angkanya berubah lagi nanti Tim SAKIP bisa
 * menyesuaikan sendiri tanpa rilis kode baru — lihat App\Models\PengaturanCapaian::
 * batas_maksimal_persen, dipakai Capaian::hitungPersentase() untuk membatasi capaian
 * (aturan b) dan nilai capaian saat target=0&realisasi>0 (aturan c).
 */
class PengaturanCapaian extends Component
{
    public $batasMaksimalPersen = 120;

    public bool $tampilkanNolSebagaiStrip = false;

    public function mount(): void
    {
        $pengaturan = PengaturanCapaianModel::ambil();
        $this->batasMaksimalPersen = (float) $pengaturan->batas_maksimal_persen;
        $this->tampilkanNolSebagaiStrip = (bool) $pengaturan->tampilkan_nol_sebagai_strip;
    }

    protected function rules(): array
    {
        return [
            'batasMaksimalPersen' => ['required', 'numeric', 'min:0', 'max:9999.99'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'batasMaksimalPersen' => 'batas maksimal capaian',
        ];
    }

    public function simpan(): void
    {
        $this->validate();

        PengaturanCapaianModel::ambil()->update([
            'batas_maksimal_persen' => $this->batasMaksimalPersen,
            'tampilkan_nol_sebagai_strip' => $this->tampilkanNolSebagaiStrip,
        ]);

        PengaturanCapaianModel::lupakanCache();

        session()->flash('status', 'Pengaturan rumus capaian berhasil disimpan. Berlaku untuk perhitungan berikutnya.');
    }

    public function render()
    {
        return view('livewire.pengaturan-capaian');
    }
}
