<?php

namespace App\Livewire;

use App\Models\MasterIku;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTim;
use Livewire\Component;

/**
 * Akun Aktif — satu tabel untuk peran DAN keanggotaan tim tiap akun terverifikasi/
 * ditolak (dulu dua bagian terpisah: tabel "Akun Aktif" biasa via form POST penuh
 * halaman, dan komponen Livewire "Keanggotaan Tim" tersendiri — digabung supaya
 * mengubah peran + tim satu orang bisa sekaligus di satu baris, tanpa reload).
 *
 * Kolom Tim hanya berlaku untuk Ketua Tim (lihat catatan User::timList() — satu
 * Ketua Tim boleh merangkap lebih dari satu tim, dipakai sebagai dasar "penugasan
 * otomatis via tim" di tab Penugasan IKU).
 */
class AkunAktif extends Component
{
    /**
     * Pilihan peran per baris, dikunci pada id pengguna — diisi nilai peran
     * saat ini di mount() supaya dropdown tidak kosong sebelum disentuh.
     *
     * @var array<int, int>
     */
    public array $roleBaru = [];

    /**
     * Input "tambah tim" per pengguna, dikunci pada id pengguna.
     *
     * @var array<int, string>
     */
    public array $timBaru = [];

    public function mount(): void
    {
        $this->roleBaru = User::where('status_verifikasi', '!=', 'pending')
            ->pluck('role_id', 'id')
            ->all();
    }

    public function updateRole(int $userId): void
    {
        $roleId = $this->roleBaru[$userId] ?? null;

        if (blank($roleId)) {
            return;
        }

        $user = User::findOrFail($userId);
        $role = Role::findOrFail($roleId);

        $user->update(['role_id' => $roleId]);

        session()->flash('status', "Peran {$user->nama} diperbarui menjadi {$role->nama}.");
    }

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
        $userList = User::with(['role', 'timList'])
            ->where('status_verifikasi', '!=', 'pending')
            ->orderBy('nama')
            ->get();

        $daftarTim = MasterIku::whereNotNull('tim')->distinct()->orderBy('tim')->pluck('tim');

        return view('livewire.akun-aktif', [
            'userList' => $userList,
            'roles' => Role::orderBy('nama')->get(),
            'daftarTim' => $daftarTim,
        ]);
    }
}
