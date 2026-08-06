<?php

namespace App\Livewire;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Notula;
use App\Services\NotulaService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Kepala — Persetujuan Notula (RF-44, RF-44a).
 *
 * Kepala meninjau PDF notula gabungan (belum ber-TTD) lalu menyetujui atau
 * mengembalikannya ke Tim SAKIP beserta catatan. Setelah disetujui, blok TTD
 * elektronik + tanggal otomatis muncul pada PDF final dan diarsipkan ke Drive.
 */
class PersetujuanNotula extends Component
{
    public int $tahun;

    public int $triwulan;

    public string $catatanPengembalian = '';

    public bool $tampilkanFormKembalikan = false;

    /**
     * Cache dalam satu siklus request — notula() dipanggil beberapa kali per
     * render (langsung + di dalam riwayatDisetujui()); $cacheNotulaDihitung
     * menandai "sudah pernah dihitung" karena hasilnya sendiri boleh null.
     */
    protected ?Notula $cacheNotula = null;

    protected bool $cacheNotulaDihitung = false;

    public function mount(): void
    {
        $this->tahun = (int) now()->year;
        $this->triwulan = (int) ceil(((int) now()->month) / 3);
    }

    public function updatedTahun(): void
    {
        $this->reset(['catatanPengembalian', 'tampilkanFormKembalikan']);
    }

    public function updatedTriwulan(): void
    {
        $this->reset(['catatanPengembalian', 'tampilkanFormKembalikan']);
    }

    protected function notula(): ?Notula
    {
        if ($this->cacheNotulaDihitung) {
            return $this->cacheNotula;
        }

        $this->cacheNotulaDihitung = true;
        $bulanPertama = ($this->triwulan - 1) * 3 + 1;

        return $this->cacheNotula = Notula::with(['periode', 'disetujuiOleh'])
            ->whereHas('periode', fn ($q) => $q->where('tahun', $this->tahun)->where('bulan', $bulanPertama))
            ->first();
    }

    public function setujui(): void
    {
        $notula = $this->notula();

        if (! $notula) {
            return;
        }

        try {
            app(NotulaService::class)->setujui($notula, Auth::user());

            session()->flash('status', 'Notula berhasil disetujui. Blok TTD elektronik & PDF final sudah tersedia.');
        } catch (InvalidStatusTransitionException $e) {
            $this->addError('aksi', $e->getMessage());
        }
    }

    public function bukaFormKembalikan(): void
    {
        $this->tampilkanFormKembalikan = true;
    }

    public function batalKembalikan(): void
    {
        $this->reset(['catatanPengembalian', 'tampilkanFormKembalikan']);
    }

    public function kembalikan(): void
    {
        $this->validate([
            'catatanPengembalian' => ['required', 'string', 'min:5'],
        ], [], ['catatanPengembalian' => 'catatan pengembalian']);

        $notula = $this->notula();

        if (! $notula) {
            return;
        }

        try {
            app(NotulaService::class)->kembalikan($notula, $this->catatanPengembalian);

            session()->flash('status', 'Notula dikembalikan ke Tim SAKIP.');
            $this->reset(['catatanPengembalian', 'tampilkanFormKembalikan']);
        } catch (InvalidStatusTransitionException $e) {
            $this->addError('aksi', $e->getMessage());
        }
    }

    /**
     * Riwayat notula yang sudah disetujui (ber-TTD) di triwulan-triwulan lain (RF-44a),
     * supaya Kepala bisa menelusuri versi lama tanpa berpindah halaman.
     */
    protected function riwayatDisetujui(?Notula $notulaSaatIni)
    {
        return Notula::with('periode')
            ->where('status', Notula::STATUS_DISETUJUI)
            ->when($notulaSaatIni, fn ($q) => $q->whereKeyNot($notulaSaatIni->id))
            ->get()
            ->sortByDesc(fn ($n) => $n->periode->tahun * 10 + $n->periode->triwulan)
            ->take(5)
            ->values();
    }

    public function render()
    {
        $notula = $this->notula();

        return view('livewire.persetujuan-notula', [
            'notula' => $notula,
            'riwayatDisetujui' => $this->riwayatDisetujui($notula),
        ]);
    }
}
