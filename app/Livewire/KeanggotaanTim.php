<?php

namespace App\Livewire;

use App\Models\MasterIku;
use App\Models\User;
use App\Models\UserTim;
use Livewire\Component;

/**
 * Keanggotaan Tim — satu Ketua Tim bisa merangkap lebih dari satu tim (dipakai
 * sebagai dasar "penugasan otomatis via tim" pada tab Penugasan IKU).
 */
class KeanggotaanTim extends Component
{
    /**
     * Input "tambah tim" per pengguna, dikunci pada id pengguna.
     *
     * @var array<int, string>
     */
    public array $timBaru = [];

    public function tambahTim(int $userId): void
    {
        $tim = trim($this->timBaru[$userId] ?? '');

        if ($tim === '') {
            return;
        }

        UserTim::firstOrCreate(['user_id' => $userId, 'tim' => $tim]);

        $this->timBaru[$userId] = '';

        session()->flash('status', 'Keanggotaan tim berhasil ditambahkan.');
    }

    public function hapusTim(int $userTimId): void
    {
        UserTim::whereKey($userTimId)->delete();

        session()->flash('status', 'Keanggotaan tim dihapus.');
    }

    public function render()
    {
        $ketuaTimList = User::with('timList')
            ->whereHas('role', fn ($q) => $q->where('nama', 'Ketua Tim'))
            ->where('status_verifikasi', 'terverifikasi')
            ->orderBy('nama')
            ->get();

        $daftarTim = MasterIku::whereNotNull('tim')->distinct()->orderBy('tim')->pluck('tim');

        return view('livewire.keanggotaan-tim', [
            'ketuaTimList' => $ketuaTimList,
            'daftarTim' => $daftarTim,
        ]);
    }
}
