<?php

namespace App\Livewire;

use App\Models\StorageAccount;
use Livewire\Component;

/**
 * Modul Tim SAKIP untuk mengelola akun penyimpanan Google Drive (RF-10a, RF-10c).
 *
 * Tim SAKIP di sini HANYA mengurus "akun mana yang dipakai", bukan mengelola
 * kredensial Service Account itu sendiri (itu tugas teknis Admin lewat file
 * storage/app/google/service-account.json — lihat catatan pemisahan peran
 * Admin/Tim SAKIP di SRS §2.3). Tim SAKIP cukup:
 *  1. Menambah akun Gmail institusi baru ke daftar (RF-10a), dan
 *  2. Menetapkan satu di antaranya sebagai "storage aktif" untuk unggahan baru.
 */
class StorageAccounts extends Component
{
    public string $email = '';

    public string $driveFolderId = '';

    public $kuotaTotal = 15;

    public function mount(): void
    {
        // Diisi otomatis dari .env supaya Tim SAKIP tidak perlu mengetik ID folder
        // teknis saat menambah akun institusi PERTAMA (biasanya = storage aktif awal).
        // Untuk akun kedua dst., Tim SAKIP mengganti nilai ini dengan ID folder milik
        // akun Gmail yang bersangkutan (folder yang sudah dibagikan ke Service Account).
        $this->driveFolderId = (string) config('services.google_drive.default_folder_id');
    }

    protected function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', 'unique:storage_account,email_gmail_institusi'],
            'driveFolderId' => ['nullable', 'string', 'max:255'],
            'kuotaTotal' => ['required', 'numeric', 'min:1'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'email' => 'email Gmail institusi',
            'driveFolderId' => 'ID folder Drive',
            'kuotaTotal' => 'kuota total (GB)',
        ];
    }

    public function tambah(): void
    {
        $this->validate();

        $akunPertama = StorageAccount::count() === 0;

        $akun = StorageAccount::create([
            'email_gmail_institusi' => $this->email,
            'drive_folder_id' => $this->driveFolderId ?: null,
            'kuota_total' => $this->kuotaTotal,
            // Default 'penuh' (= belum aktif). Lihat StorageAccount::STATUS_PENUH —
            // status ini dipakai juga untuk akun yang belum pernah diaktifkan, bukan
            // cuma akun yang benar-benar sudah penuh.
            'status' => StorageAccount::STATUS_PENUH,
        ]);

        // Akun institusi PERTAMA otomatis dijadikan storage aktif, supaya sistem
        // langsung punya tujuan unggahan tanpa langkah aktivasi manual tambahan.
        if ($akunPertama) {
            $akun->jadikanAktif();
        }

        session()->flash('status', "Akun {$akun->email_gmail_institusi} berhasil ditambahkan.");

        $this->reset(['email', 'kuotaTotal']);
        $this->driveFolderId = (string) config('services.google_drive.default_folder_id');
    }

    /**
     * RF-10a: "menetapkan SATU akun sebagai storage aktif" — logika exclusivity-nya
     * (menonaktifkan akun aktif lama) ada di StorageAccount::jadikanAktif(), bukan di sini,
     * supaya aturan ini tetap berlaku walau dipanggil dari tempat lain di luar komponen ini.
     */
    public function jadikanAktif(int $id): void
    {
        StorageAccount::findOrFail($id)->jadikanAktif();

        session()->flash('status', 'Storage aktif berhasil diperbarui.');
    }

    public function render()
    {
        // Diurutkan supaya akun 'aktif' (alfabetis lebih kecil dari 'penuh') tampil di atas.
        $akunList = StorageAccount::orderBy('status')->orderBy('email_gmail_institusi')->get();

        return view('livewire.storage-accounts', [
            'akunList' => $akunList,
            // Diturunkan dari $akunList (satu query) alih-alih StorageAccount::aktif() (query kedua).
            'akunAktif' => $akunList->firstWhere('status', StorageAccount::STATUS_AKTIF),
        ]);
    }
}
