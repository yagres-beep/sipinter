<?php

namespace App\Livewire;

use App\Models\PengaturanCapaian as PengaturanCapaianModel;
use Livewire\Component;

/**
 * Pengaturan Rumus Capaian (Tim SAKIP) — satu angka "batas maksimal capaian" yang
 * dipakai Capaian::hitungPersentase() untuk membatasi capaian (aturan b) dan nilai
 * capaian saat target=0&realisasi>0 (aturan c). Rumus resmi (Kertas Kerja Pengukuran
 * Kinerja Triwulanan) sengaja tidak dikodekan langsung ("hard-coded") supaya kalau
 * angkanya berubah lagi nanti, Tim SAKIP bisa menyesuaikan sendiri tanpa rilis kode
 * baru — lihat App\Models\PengaturanCapaian.
 */
class PengaturanCapaian extends Component
{
    public $batasMaksimalPersen = 120;

    public function mount(): void
    {
        $this->batasMaksimalPersen = (float) PengaturanCapaianModel::ambil()->batas_maksimal_persen;
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
        ]);

        PengaturanCapaianModel::lupakanCache();

        session()->flash('status', 'Pengaturan rumus capaian berhasil disimpan. Berlaku untuk perhitungan berikutnya.');
    }

    public function render()
    {
        return view('livewire.pengaturan-capaian');
    }
}
