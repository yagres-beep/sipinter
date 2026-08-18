<?php

namespace App\Livewire;

use App\Models\StorageAccount;
use App\Services\GoogleDriveService;
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

    /** ID akun yang sedang diubah folder Drive-nya lewat menu "✏️ Ubah folder", null bila tidak ada. */
    public ?int $editFolderId = null;

    public string $editFolderValue = '';

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
            'drive_folder_id' => GoogleDriveService::idFolderDariInput($this->driveFolderId) ?: null,
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

    /**
     * Pindahkan status "akun master folder" (lihat StorageAccount::master()) ke akun
     * ini — dulu hanya bisa diatur lewat GOOGLE_DRIVE_MASTER_ACCOUNT_EMAIL di .env,
     * sekarang Tim SAKIP bisa mengubahnya sendiri tanpa perlu akses server.
     */
    public function jadikanMaster(int $id): void
    {
        StorageAccount::findOrFail($id)->jadikanMaster();

        session()->flash('status', 'Akun master folder berhasil diperbarui. Akun yang baru terhubung ke Google Drive setelah ini akan otomatis diarahkan ke folder akun master.');
    }

    /**
     * Sinkronkan kuota_terpakai/kuota_total dengan angka SEBENARNYA dari Google
     * Drive (RF-10c) — kuota_terpakai tersimpan cuma bertambah lewat unggahan lewat
     * aplikasi ini (lihat StorageAccount::tambahKuotaTerpakai()), jadi bisa
     * melenceng dari kondisi asli. Tombol ini menimpanya dengan data langsung dari
     * Google (about.get), bukan cuma menambah.
     */
    public function sinkronKuota(int $id): void
    {
        $akun = StorageAccount::findOrFail($id);

        try {
            $kuota = app(GoogleDriveService::class)->ambilKuota($akun);
            $akun->sinkronKuota($kuota);

            session()->flash('status', "Kuota akun {$akun->email_gmail_institusi} disinkronkan dari Google Drive.");
        } catch (\Throwable $e) {
            session()->flash('error', "Gagal menyinkronkan kuota akun {$akun->email_gmail_institusi}: ".$e->getMessage());
        }
    }

    public function mulaiUbahFolder(int $id): void
    {
        $akun = StorageAccount::findOrFail($id);

        $this->editFolderId = $akun->id;
        $this->editFolderValue = (string) $akun->drive_folder_id;
        $this->resetErrorBag('editFolderValue');
    }

    public function batalUbahFolder(): void
    {
        $this->editFolderId = null;
        $this->editFolderValue = '';
        $this->resetErrorBag('editFolderValue');
    }

    /**
     * Ganti folder Drive tujuan sebuah akun storage — Tim SAKIP tinggal tempel URL
     * folder Drive (lihat GoogleDriveService::idFolderDariInput()) alih-alih mengetik
     * ID mentahnya. Dipakai mis. saat institusi sudah punya folder lama (mis. folder
     * "sakip7409" yang dibuat manual sebelum SIPINTER ada) dan ingin akun terhubung
     * ke folder ITU alih-alih folder "SIPINTER" baru yang dibuat otomatis saat OAuth.
     *
     * Bila akun yang diubah adalah akun MASTER, folder baru ini otomatis dibagikan
     * (share, writer) ke semua akun LAIN yang sudah terhubung ke Google Drive, dan
     * drive_folder_id mereka ikut diarahkan ke folder baru — supaya semua akun
     * institusi tetap menulis ke SATU folder yang sama tanpa Tim SAKIP harus
     * menghubungkan ulang akun-akun itu satu per satu (lihat StorageAccount::master(),
     * GoogleOAuthController::callback()).
     */
    public function simpanFolder(): void
    {
        // Diambil lewat app() (bukan type-hint parameter) — method aksi Livewire
        // dipanggil TANPA dependency injection (lihat HandleComponents::callMethods():
        // wrap($root)->{$method}(...$params), hanya param dari front-end), beda dari
        // method controller biasa.
        $driveService = app(GoogleDriveService::class);

        // find() (bukan findOrFail()) — editFolderId bisa saja sudah null/berubah
        // lagi (mis. dua klik beruntun sebelum mode edit sempat termuat di browser).
        // findOrFail() di sini akan membuat Laravel merender HALAMAN 404 MENTAH
        // menggantikan komponen, bukan pesan error yang ramah seperti di bawah.
        $akun = StorageAccount::find($this->editFolderId);

        if (! $akun) {
            $this->batalUbahFolder();

            return;
        }

        $folderId = GoogleDriveService::idFolderDariInput($this->editFolderValue);

        if ($folderId === '') {
            $this->addError('editFolderValue', 'ID/URL folder tidak boleh kosong.');

            return;
        }

        $akun->update(['drive_folder_id' => $folderId]);

        if ($akun->is_master && $akun->googleTerhubung()) {
            try {
                $clientMaster = $driveService->clientOAuthUntukAkun($akun);

                StorageAccount::where('id', '!=', $akun->id)->get()
                    ->filter(fn (StorageAccount $lain) => $lain->googleTerhubung())
                    ->each(function (StorageAccount $lain) use ($driveService, $clientMaster, $folderId) {
                        $driveService->bagikanFolder($clientMaster, $folderId, $lain->email_gmail_institusi);
                        $lain->update(['drive_folder_id' => $folderId]);
                    });
            } catch (\Throwable $e) {
                session()->flash('error',
                    "Folder akun {$akun->email_gmail_institusi} tersimpan, tapi gagal membagikannya ke akun lain: ".$e->getMessage()
                );

                $this->editFolderId = null;
                $this->editFolderValue = '';

                return;
            }
        }

        $this->editFolderId = null;
        $this->editFolderValue = '';

        session()->flash('status', "Folder Drive akun {$akun->email_gmail_institusi} berhasil diperbarui.");
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
