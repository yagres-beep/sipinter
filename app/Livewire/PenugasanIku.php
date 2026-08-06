<?php

namespace App\Livewire;

use App\Models\IkuPenugasan;
use App\Models\MasterIku;
use App\Models\User;
use App\Models\UserTim;
use Livewire\Component;

/**
 * Penugasan IKU — tiap IKU menampilkan penanggung jawab otomatis (via keanggotaan
 * tim, chip abu-abu) dan penanggung jawab manual (tambahan/override, chip biru
 * yang bisa dihapus).
 */
class PenugasanIku extends Component
{
    /**
     * Pilihan "tambah manual" per IKU, dikunci pada id IKU.
     *
     * @var array<int, string>
     */
    public array $orangBaru = [];

    public function tambahManual(int $ikuId): void
    {
        $userId = $this->orangBaru[$ikuId] ?? null;

        if (blank($userId)) {
            return;
        }

        IkuPenugasan::firstOrCreate(['iku_id' => $ikuId, 'user_id' => $userId]);

        $this->orangBaru[$ikuId] = '';

        session()->flash('status', 'Penugasan IKU berhasil ditambahkan.');
    }

    public function hapusManual(int $penugasanId): void
    {
        IkuPenugasan::whereKey($penugasanId)->delete();

        session()->flash('status', 'Penugasan IKU dihapus.');
    }

    public function render()
    {
        $ikuList = MasterIku::with(['penugasanManual.user'])->orderBy('kode')->get();

        $ketuaTimList = User::whereHas('role', fn ($q) => $q->where('nama', 'Ketua Tim'))
            ->where('status_verifikasi', 'terverifikasi')
            ->orderBy('nama')
            ->get();

        // Satu query untuk penanggung jawab otomatis SELURUH tim yang muncul di
        // $ikuList, dikelompokkan per tim di PHP — bukan satu query per baris IKU
        // (sebelumnya lewat $iku->penanggungJawabOtomatis() di dalam @foreach).
        $otomatisPerTim = UserTim::with('user')
            ->whereIn('tim', $ikuList->pluck('tim')->unique()->filter())
            ->get()
            ->groupBy('tim')
            ->map(fn ($baris) => $baris->pluck('user')->filter()->unique('id')->values());

        return view('livewire.penugasan-iku', [
            'ikuList' => $ikuList,
            'ketuaTimList' => $ketuaTimList,
            'otomatisPerTim' => $otomatisPerTim,
        ]);
    }
}
