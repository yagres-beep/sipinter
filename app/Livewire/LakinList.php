<?php

namespace App\Livewire;

use App\Models\Lakin;
use Livewire\Component;

/**
 * Daftar dokumen LAKIN per tahun — semua peran bisa melihat daftar & membuka detail,
 * tapi hanya Tim SAKIP yang bisa membuat dokumen baru (lihat pastikanSakip()). Membuat
 * dokumen di sini TIDAK langsung mengisi baris apa pun — Tim SAKIP memilih sendiri
 * IKU mana yang mau dimasukkan lewat checklist di halaman detail (LakinDetail).
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

        $lakin = Lakin::firstOrCreate(['tahun' => (int) $this->tahunBaru]);

        $this->redirectRoute('lakin.show', $lakin, navigate: false);
    }

    public function render()
    {
        return view('livewire.lakin-list', [
            'daftar' => Lakin::withCount('baris')->orderByDesc('tahun')->get(),
        ]);
    }
}
