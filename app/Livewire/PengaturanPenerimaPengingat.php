<?php

namespace App\Livewire;

use App\Models\PengaturanPenerimaPengingat as PengaturanPenerimaPengingatModel;
use Livewire\Component;

/**
 * Pengaturan penerima tiap jenis pengingat WA (Tim SAKIP) — menggantikan daftar
 * penerima yang tadinya dikodekan langsung (mis. "Tim SAKIP", "Kepala") di tiap
 * Command/Listener pengingat, lihat App\Models\PengaturanPenerimaPengingat.
 *
 * @property array<string, array<int, string>> $pilihan Peran tercentang per jenis, dikunci pada kunci jenis (mis. 'deadline_iku').
 */
class PengaturanPenerimaPengingat extends Component
{
    public array $pilihan = [];

    public function mount(): void
    {
        foreach (PengaturanPenerimaPengingatModel::JENIS as $jenis => $meta) {
            $this->pilihan[$jenis] = PengaturanPenerimaPengingatModel::rolesUntuk($jenis);
        }
    }

    public function simpan(): void
    {
        foreach (PengaturanPenerimaPengingatModel::JENIS as $jenis => $meta) {
            $roles = array_values(array_intersect($this->pilihan[$jenis] ?? [], $meta['opsi']));

            PengaturanPenerimaPengingatModel::simpan($jenis, $roles);
        }

        session()->flash('status', 'Pengaturan penerima pengingat berhasil disimpan.');
    }

    public function render()
    {
        return view('livewire.pengaturan-penerima-pengingat', [
            'jenisList' => PengaturanPenerimaPengingatModel::JENIS,
            'roleLabel' => PengaturanPenerimaPengingatModel::ROLE_LABEL,
        ]);
    }
}
