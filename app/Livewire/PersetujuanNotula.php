<?php

namespace App\Livewire;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Capaian;
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
     * Form "kembalikan isian ini" per-baris IKU (lihat kembalikanIsian() di bawah) — beda
     * dari catatanPengembalian/tampilkanFormKembalikan di atas yang mengembalikan SELURUH
     * notula. capaianDikembalikanId null berarti tidak ada baris yang sedang dibuka formnya.
     */
    public ?int $capaianDikembalikanId = null;

    public string $catatanKembalikanIsian = '';

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
        $this->reset(['catatanPengembalian', 'tampilkanFormKembalikan', 'capaianDikembalikanId', 'catatanKembalikanIsian']);
    }

    public function updatedTriwulan(): void
    {
        $this->reset(['catatanPengembalian', 'tampilkanFormKembalikan', 'capaianDikembalikanId', 'catatanKembalikanIsian']);
    }

    /**
     * Daftar IKU (Capaian) triwulan yang sedang ditinjau, supaya Kepala bisa melihat rincian
     * per-IKU (bukan cuma PDF gabungan) dan menunjuk isian mana yang perlu dikembalikan —
     * lihat kembalikanIsian(). "diverifikasi" adalah satu-satunya status yang tombol
     * pengembaliannya aktif (lihat Capaian::bisaDikembalikanOlehKepala()); status lain
     * (dikembalikan/disetujui) ditampilkan sebagai konteks saja.
     */
    protected function daftarCapaian()
    {
        return Capaian::with('masterIku')
            ->whereHas('periode', fn ($q) => $q->where('tahun', $this->tahun)->where('triwulan', $this->triwulan))
            ->whereIn('status', [Capaian::STATUS_DIVERIFIKASI, Capaian::STATUS_DIKEMBALIKAN, Capaian::STATUS_DISETUJUI])
            ->get()
            ->sortBy(fn ($c) => $c->masterIku?->kode)
            ->values();
    }

    public function bukaFormKembalikanIsian(int $capaianId): void
    {
        $this->capaianDikembalikanId = $capaianId;
        $this->catatanKembalikanIsian = '';
    }

    public function batalKembalikanIsian(): void
    {
        $this->reset(['capaianDikembalikanId', 'catatanKembalikanIsian']);
    }

    public function kembalikanIsian(): void
    {
        $this->validate([
            'catatanKembalikanIsian' => ['required', 'string', 'min:5'],
        ], [], ['catatanKembalikanIsian' => 'catatan pengembalian']);

        $capaian = $this->capaianDikembalikanId ? Capaian::find($this->capaianDikembalikanId) : null;

        if (! $capaian) {
            return;
        }

        try {
            app(NotulaService::class)->kembalikanIsian($capaian, Auth::user(), $this->catatanKembalikanIsian);

            session()->flash('status', 'Isian dikembalikan langsung ke Ketua Tim.');
            $this->reset(['capaianDikembalikanId', 'catatanKembalikanIsian']);
            $this->cacheNotulaDihitung = false;
        } catch (InvalidStatusTransitionException $e) {
            $this->addError('aksiIsian', $e->getMessage());
        }
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
            'daftarCapaian' => $this->daftarCapaian(),
        ]);
    }
}
