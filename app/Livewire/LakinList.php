<?php

namespace App\Livewire;

use App\Models\Lakin;
use App\Services\LakinBuilderService;
use Livewire\Component;

/**
 * Daftar dokumen LAKIN per tahun — semua peran bisa melihat daftar & membuka detail,
 * tapi hanya Tim SAKIP yang bisa membentuk dokumen baru (lihat pastikanSakip()).
 */
class LakinList extends Component
{
    public string $tahunBaru;

    public function mount(): void
    {
        $this->tahunBaru = (string) now()->year;
    }

    protected function pastikanSakip(): void
    {
        abort_unless(auth()->user()->namaRole() === 'Tim SAKIP', 403, 'Hanya Tim SAKIP yang dapat membentuk LAKIN.');
    }

    public function bentukLakin(): void
    {
        $this->pastikanSakip();

        $this->validate([
            'tahunBaru' => ['required', 'integer', 'min:2020', 'max:2100'],
        ], [], ['tahunBaru' => 'tahun']);

        $lakin = app(LakinBuilderService::class)->bentuk((int) $this->tahunBaru);

        $this->redirectRoute('lakin.show', $lakin, navigate: false);
    }

    public function render()
    {
        return view('livewire.lakin-list', [
            'daftar' => Lakin::withCount('baris')->orderByDesc('tahun')->get(),
        ]);
    }
}
