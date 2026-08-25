<?php

namespace App\Livewire;

use App\Models\MasterIku;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTim;
use Illuminate\Support\Str;
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
        $pesan = [];

        if (filled($roleId)) {
            $user = User::findOrFail($userId);
            $role = Role::findOrFail($roleId);

            $user->update(['role_id' => $roleId]);

            $pesan[] = "peran {$user->nama} diperbarui menjadi {$role->nama}";
        }

        // Tombol "Simpan" ini satu-satunya tombol simpan yang terlihat di baris —
        // kalau pengguna sudah mengetik nama tim di kotak "+ tim…" tapi lupa/tidak
        // sadar harus menekan tombol ＋ terpisah (atau Enter) untuk menambahkannya,
        // klik "Simpan" di sini ikut menambahkannya juga supaya tidak terasa seperti
        // "sudah klik simpan tapi tidak tersimpan".
        $tim = trim($this->timBaru[$userId] ?? '');

        if ($tim !== '') {
            UserTim::firstOrCreate(['user_id' => $userId, 'tim' => $tim]);
            $this->timBaru[$userId] = '';

            $pesan[] = "tim \"{$tim}\" ditambahkan";
        }

        if ($pesan) {
            session()->flash('status', ucfirst(implode(', ', $pesan)).'.');
        }
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

    /**
     * Reset manual oleh Tim SAKIP — dibutuhkan terutama untuk akun yang belum
     * punya email (lihat EnsureEmailIsComplete) sehingga tidak bisa memakai
     * "lupa kata sandi" sendiri. Password baru ditampilkan sekali ke Tim SAKIP
     * untuk disampaikan langsung ke pengguna.
     */
    public function resetPassword(int $userId): void
    {
        $user = User::findOrFail($userId);
        $passwordBaru = Str::password(10, symbols: false);

        $user->update(['password' => $passwordBaru]);

        session()->flash('status', "Kata sandi {$user->nama} direset menjadi: {$passwordBaru} — sampaikan ke pengguna secara langsung, lalu minta segera menggantinya di halaman Profil.");
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
