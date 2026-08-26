<?php

namespace App\Livewire;

use App\Models\PengaturanTemplatePengingat as PengaturanTemplatePengingatModel;
use Livewire\Component;

/**
 * Pengaturan format pesan tiap jenis pengingat WA (Tim SAKIP) — menggantikan
 * string pesan yang tadinya dikodekan langsung (sprintf) di tiap Command/
 * Listener pengingat, lihat App\Models\PengaturanTemplatePengingat.
 */
class PengaturanTemplatePengingat extends Component
{
    public array $template = [];

    public function mount(): void
    {
        foreach (PengaturanTemplatePengingatModel::JENIS as $jenis => $meta) {
            $this->template[$jenis] = PengaturanTemplatePengingatModel::ambil($jenis);
        }
    }

    protected function rules(): array
    {
        return collect(PengaturanTemplatePengingatModel::JENIS)
            ->keys()
            ->mapWithKeys(fn ($jenis) => ["template.{$jenis}" => ['required', 'string', 'max:1000']])
            ->all();
    }

    public function pulihkanBawaan(string $jenis): void
    {
        if (! isset(PengaturanTemplatePengingatModel::JENIS[$jenis])) {
            return;
        }

        $this->template[$jenis] = PengaturanTemplatePengingatModel::JENIS[$jenis]['default'];
    }

    public function simpan(): void
    {
        $this->validate();

        foreach (PengaturanTemplatePengingatModel::JENIS as $jenis => $meta) {
            PengaturanTemplatePengingatModel::simpan($jenis, $this->template[$jenis]);
        }

        session()->flash('status', 'Format pesan pengingat berhasil disimpan.');
    }

    public function render()
    {
        return view('livewire.pengaturan-template-pengingat', [
            'jenisList' => PengaturanTemplatePengingatModel::JENIS,
        ]);
    }
}
