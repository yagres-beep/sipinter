<?php

namespace App\Livewire;

use App\Models\PengaturanCapaian as PengaturanCapaianModel;
use Livewire\Component;

/**
 * Pengaturan Rumus Capaian (Tim SAKIP) — dua angka yang dipakai rumus resmi (Kertas
 * Kerja Pengukuran Kinerja Triwulanan) tanpa dikodekan langsung ("hard-coded"),
 * supaya kalau angkanya berubah lagi nanti Tim SAKIP bisa menyesuaikan sendiri tanpa
 * rilis kode baru — lihat App\Models\PengaturanCapaian:
 * - batas maksimal capaian: dipakai Capaian::hitungPersentase() untuk membatasi
 *   capaian (aturan b) dan nilai capaian saat target=0&realisasi>0 (aturan c).
 * - batas normalisasi Capaian PK: dipakai Capaian::normalisasiCapaianPk() pada rumus
 *   Penilaian Kinerja Organisasi (PKO, App\Livewire\DasborCapaian) — batas TERPISAH
 *   dari batas maksimal capaian di atas.
 */
class PengaturanCapaian extends Component
{
    public $batasMaksimalPersen = 120;

    public $batasNormalisasiPko = 110;

    public bool $tampilkanNolSebagaiStrip = false;

    public function mount(): void
    {
        $pengaturan = PengaturanCapaianModel::ambil();
        $this->batasMaksimalPersen = (float) $pengaturan->batas_maksimal_persen;
        $this->batasNormalisasiPko = (float) $pengaturan->batas_normalisasi_pko;
        $this->tampilkanNolSebagaiStrip = (bool) $pengaturan->tampilkan_nol_sebagai_strip;
    }

    protected function rules(): array
    {
        return [
            'batasMaksimalPersen' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'batasNormalisasiPko' => ['required', 'numeric', 'min:0', 'max:9999.99'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'batasMaksimalPersen' => 'batas maksimal capaian',
            'batasNormalisasiPko' => 'batas normalisasi Capaian PK',
        ];
    }

    public function simpan(): void
    {
        $this->validate();

        PengaturanCapaianModel::ambil()->update([
            'batas_maksimal_persen' => $this->batasMaksimalPersen,
            'batas_normalisasi_pko' => $this->batasNormalisasiPko,
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
