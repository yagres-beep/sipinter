<?php

namespace App\Livewire;

use App\Models\MasterIku;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTim;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
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

    /**
     * Id pengguna yang sedang dikonfirmasi reset kata sandinya — mengontrol
     * tampil/tidaknya modal reset (lihat pola x-confirm-modal, tapi di sini
     * butuh input teks jadi modalnya ditulis manual).
     */
    public ?int $pendingResetId = null;

    /** Kata sandi baru yang diketik Tim SAKIP untuk pengguna $pendingResetId. */
    public string $passwordBaru = '';

    /**
     * Id pengguna yang sedang diubah email/nomor teleponnya — dibutuhkan
     * karena tidak semua pengguna sempat/bisa melengkapi datanya sendiri
     * lewat halaman Profil (lihat EnsureEmailIsComplete), jadi Tim SAKIP
     * bisa membetulkannya langsung dari sini.
     */
    public ?int $pendingEditId = null;

    public string $emailBaru = '';

    public string $nomorTeleponBaru = '';

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
     * "lupa kata sandi" sendiri. Tim SAKIP sendiri yang menentukan kata sandi
     * barunya (bukan diacak) supaya bisa langsung disampaikan dan diingat
     * pengguna, lalu modal minta ini muncul dulu untuk menuliskannya.
     */
    public function confirmReset(int $userId): void
    {
        $this->pendingResetId = $userId;
        $this->passwordBaru = '';
        $this->resetErrorBag();
    }

    public function cancelReset(): void
    {
        $this->pendingResetId = null;
        $this->passwordBaru = '';
        $this->resetErrorBag();
    }

    public function resetPassword(): void
    {
        if (! $this->pendingResetId) {
            return;
        }

        $this->validate([
            'passwordBaru' => ['required', 'string', Password::defaults()],
        ], [], ['passwordBaru' => 'kata sandi baru']);

        $user = User::findOrFail($this->pendingResetId);
        $user->update(['password' => $this->passwordBaru]);

        session()->flash('status', "Kata sandi {$user->nama} berhasil direset. Sampaikan kata sandi baru ke pengguna secara langsung, lalu minta segera menggantinya di halaman Profil.");

        $this->pendingResetId = null;
        $this->passwordBaru = '';
    }

    /**
     * Buka modal ubah email/nomor telepon, diisi otomatis dengan nilai
     * pengguna saat ini supaya Tim SAKIP hanya membetulkan yang perlu.
     */
    public function confirmEdit(int $userId): void
    {
        $user = User::findOrFail($userId);

        $this->pendingEditId = $userId;
        $this->emailBaru = (string) $user->email;
        $this->nomorTeleponBaru = (string) $user->nomor_telepon;
        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->pendingEditId = null;
        $this->emailBaru = '';
        $this->nomorTeleponBaru = '';
        $this->resetErrorBag();
    }

    public function simpanProfil(): void
    {
        if (! $this->pendingEditId) {
            return;
        }

        $this->validate([
            'emailBaru' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->pendingEditId)],
            'nomorTeleponBaru' => ['required', 'string', 'max:20'],
        ], [], ['emailBaru' => 'email', 'nomorTeleponBaru' => 'nomor telepon']);

        $user = User::findOrFail($this->pendingEditId);
        $user->update([
            'email' => $this->emailBaru,
            'nomor_telepon' => $this->nomorTeleponBaru,
        ]);

        session()->flash('status', "Email & nomor telepon {$user->nama} berhasil diperbarui.");

        $this->pendingEditId = null;
        $this->emailBaru = '';
        $this->nomorTeleponBaru = '';
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
