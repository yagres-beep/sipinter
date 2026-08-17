<?php

namespace App\Services;

use App\Models\StorageAccount;
use Google\Client as GoogleClient;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;
use RuntimeException;

/**
 * Jembatan ke Google Drive lewat Service Account (RF-10, SRS §5.3).
 *
 * KONSEP PENTING — kenapa tidak perlu pengguna login Google:
 * Service Account adalah "akun robot" milik aplikasi, punya alamat email sendiri
 * (terlihat di client_email pada file kredensial JSON) dan bisa login sendiri
 * memakai file JSON itu (bukan lewat browser). Robot ini TIDAK otomatis punya
 * akses ke Drive siapa pun. Supaya bisa membuat folder/berkas di Drive akun
 * Gmail institusi, folder induk di akun tersebut harus di-*share* (peran Editor)
 * ke alamat email si robot terlebih dahulu — persis seperti berbagi folder ke
 * kolega. Setelah dibagikan, robot ini bisa dipakai untuk SEMUA akun institusi
 * sekaligus (satu kredensial, banyak folder yang dibagikan ke robot yang sama),
 * itulah kenapa storage_account.drive_folder_id berbeda-beda per akun tapi
 * kredensial Service Account-nya (config/services.php) cukup satu untuk semua.
 *
 * Client & service Google baru benar-benar dibuat saat pertama kali dipakai
 * (lazy), bukan di constructor — supaya kelas ini tetap aman di-resolve dari
 * container Laravel meskipun file kredensial belum ada (mis. saat instalasi
 * awal sebelum Tim SAKIP mengunggah service-account.json).
 */
class GoogleDriveService
{
    protected ?GoogleClient $client = null;

    protected ?Drive $drive = null;

    /**
     * Unggah satu berkas lokal ke sebuah folder Drive.
     *
     * @param  string  $localPath  Path berkas di disk lokal server (mis. hasil upload sementara Livewire).
     * @param  string  $filename  Nama berkas yang akan tersimpan di Drive (sudah di-slugify di pemanggil, lihat RF-17).
     * @param  string  $parentFolderId  ID folder Drive tujuan (hasil dari findOrCreateFolder()).
     * @return array{id: string, name: string, web_view_link: ?string, size_bytes: int} Metadata dari Drive,
     *              termasuk `size_bytes` asli di server Drive untuk dicatat lewat
     *              StorageAccount::tambahKuotaTerpakai() (RF-10c).
     */
    public function uploadFile(string $localPath, string $filename, string $parentFolderId, string $mimeType = 'application/pdf'): array
    {
        if (! is_file($localPath)) {
            throw new RuntimeException("Berkas lokal tidak ditemukan: {$localPath}");
        }

        $metadata = new DriveFile([
            'name' => $filename,
            'parents' => [$parentFolderId],
        ]);

        /** @var DriveFile $uploaded */
        $uploaded = $this->drive()->files->create($metadata, [
            'data' => file_get_contents($localPath),
            'mimeType' => $mimeType,
            'uploadType' => 'multipart',
            // 'fields' membatasi respons API hanya ke kolom yang kita perlukan (hemat kuota API).
            'fields' => 'id, name, webViewLink, size',
        ]);

        return [
            'id' => $uploaded->getId(),
            'name' => $uploaded->getName(),
            'web_view_link' => $uploaded->getWebViewLink(),
            'size_bytes' => (int) $uploaded->getSize(),
        ];
    }

    /**
     * Terima ID folder Drive ATAU URL folder Drive lengkap (mis. hasil "Salin tautan"
     * di Drive: https://drive.google.com/drive/folders/&lt;ID&gt;?usp=sharing) dan selalu
     * kembalikan ID mentahnya — dipakai di StorageAccounts supaya Tim SAKIP cukup
     * tempel URL folder tanpa perlu tahu cara membaca ID dari dalam URL-nya.
     */
    public static function idFolderDariInput(string $input): string
    {
        $input = trim($input);

        if ($input === '') {
            return '';
        }

        if (preg_match('#/folders/([a-zA-Z0-9_-]+)#', $input, $cocok)) {
            return $cocok[1];
        }

        if (preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $input, $cocok)) {
            return $cocok[1];
        }

        return $input;
    }

    /**
     * Buat folder baru di Drive. Dipakai langsung hanya bila pemanggil SUDAH yakin
     * folder belum ada; untuk pemakaian normal (hierarki folder otomatis RF-11 s.d.
     * RF-14) pakai findOrCreateFolder() supaya tidak membuat folder duplikat.
     *
     * @return string ID folder yang baru dibuat.
     */
    public function createFolder(string $name, string $parentFolderId): string
    {
        $metadata = new DriveFile([
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parentFolderId],
        ]);

        /** @var DriveFile $folder */
        $folder = $this->drive()->files->create($metadata, ['fields' => 'id']);

        return $folder->getId();
    }

    /**
     * Cari folder bernama $name tepat di dalam $parentFolderId; buat baru hanya
     * bila belum ada. Inilah mekanisme "lazy creation" pada RF-14: folder per
     * tahun/triwulan/bulan/IKU/kategori/kegiatan baru benar-benar dibuat di Drive
     * saat berkas pertamanya diunggah, bukan dibuat di muka untuk semua kombinasi
     * yang mungkin.
     *
     * @return string ID folder (baik yang sudah ada maupun yang baru dibuat).
     */
    public function findOrCreateFolder(string $name, string $parentFolderId): string
    {
        // Escape tanda kutip tunggal pada nama folder supaya tidak merusak query pencarian di bawah.
        $namaAman = str_replace("'", "\\'", $name);

        $query = sprintf(
            "mimeType = 'application/vnd.google-apps.folder' and name = '%s' and '%s' in parents and trashed = false",
            $namaAman,
            $parentFolderId
        );

        $hasil = $this->drive()->files->listFiles([
            'q' => $query,
            'fields' => 'files(id, name)',
            'pageSize' => 1,
        ]);

        $folderDitemukan = $hasil->getFiles()[0] ?? null;

        if ($folderDitemukan) {
            return $folderDitemukan->getId();
        }

        return $this->createFolder($name, $parentFolderId);
    }

    /**
     * Daftar nama anak langsung (file atau folder, tidak termasuk yang sudah di-trash)
     * di dalam sebuah folder. Dipakai untuk mengecek tabrakan nama sebelum membuat
     * folder/berkas baru (RF-17: "diberi penomoran berurutan bila terjadi duplikasi").
     *
     * @return list<string>
     */
    public function namesInFolder(string $parentFolderId): array
    {
        $hasil = $this->drive()->files->listFiles([
            'q' => sprintf("'%s' in parents and trashed = false", $parentFolderId),
            'fields' => 'files(name)',
            'pageSize' => 1000,
        ]);

        return array_map(fn (DriveFile $file) => $file->getName(), $hasil->getFiles());
    }

    /**
     * Ambil tautan Drive (webViewLink) sebuah berkas berdasarkan ID-nya.
     *
     * Dipakai lewat Berkas::driveLink() — perhatikan method ini hanya butuh
     * $fileId, TIDAK peduli akun mana yang sedang "aktif" saat ini. Karena satu
     * Service Account punya akses ke semua folder yang pernah dibagikan
     * kepadanya (lihat catatan kelas di atas), berkas lama tetap bisa dibuka
     * meski storage aktif sudah berpindah ke akun lain (RF-10b).
     */
    public function getFileLink(string $fileId): string
    {
        /** @var DriveFile $file */
        $file = $this->drive()->files->get($fileId, ['fields' => 'webViewLink']);

        return $file->getWebViewLink();
    }

    /**
     * Tautan Drive ke sebuah FOLDER berdasarkan ID-nya — berbeda dari getFileLink(),
     * ini TIDAK perlu memanggil Drive API sama sekali (URL folder Drive selalu
     * mengikuti pola tetap ini), jadi aman & murah dipanggil berkali-kali (mis. satu
     * kali per IKU saat menyusun Notula) tanpa menambah kuota API.
     */
    public function folderLink(string $folderId): string
    {
        return "https://drive.google.com/drive/folders/{$folderId}";
    }

    /**
     * Buat SATU folder di root ("My Drive") akun yang baru saja login lewat OAuth,
     * memakai client yang MASIH membawa access token segar hasil pertukaran kode
     * otorisasi (belum tentu = StorageAccount::aktif(), dan belum tentu tersimpan
     * lagi di DB saat method ini dipanggil) — dipakai HANYA oleh GoogleOAuthController
     * tepat setelah sebuah akun berhasil terhubung (lihat callback()), untuk
     * menyiapkan folder yang DIMILIKI aplikasi (wajib untuk scope drive.file, lihat
     * catatan di GoogleOAuthController) sebelum drive_folder_id akun itu diisi.
     */
    public function buatFolderRoot(GoogleClient $client, string $namaFolder): string
    {
        $clientLama = $this->client;
        $driveLama = $this->drive;

        $this->client = $client;
        $this->drive = null;

        try {
            return $this->createFolder($namaFolder, 'root');
        } finally {
            $this->client = $clientLama;
            $this->drive = $driveLama;
        }
    }

    /**
     * Bagikan (share) satu folder Drive ke alamat email lain, peran "writer" secara
     * default — dipakai supaya akun storage BUKAN-master menulis ke folder MASTER yang
     * sama (lihat config('services.google_drive.master_account_email') & catatan di
     * GoogleOAuthController::callback()) alih-alih membuat folder root sendiri-sendiri.
     *
     * $ownerClient WAJIB client milik akun yang jadi PEMILIK folder (atau minimal sudah
     * berperan Editor di folder itu) — Drive API hanya mengizinkan permission baru dibuat
     * oleh yang sudah punya hak kelola atas folder tsb.
     *
     * Idempotent: dicek dulu apakah email itu sudah punya akses supaya reconnect
     * berulang tidak menumpuk permission duplikat untuk email yang sama.
     */
    public function bagikanFolder(GoogleClient $ownerClient, string $folderId, string $email, string $role = 'writer'): void
    {
        $clientLama = $this->client;
        $driveLama = $this->drive;

        $this->client = $ownerClient;
        $this->drive = null;

        try {
            $sudahDibagikan = collect(
                $this->drive()->permissions->listPermissions($folderId, ['fields' => 'permissions(emailAddress)'])->getPermissions()
            )->contains(fn (Permission $p) => strcasecmp((string) $p->getEmailAddress(), $email) === 0);

            if ($sudahDibagikan) {
                return;
            }

            $this->drive()->permissions->create($folderId, new Permission([
                'type' => 'user',
                'role' => $role,
                'emailAddress' => $email,
            ]), ['sendNotificationEmail' => false]);
        } finally {
            $this->client = $clientLama;
            $this->drive = $driveLama;
        }
    }

    /**
     * Siapkan Google\Client sekali saja (lazy). Diprioritaskan dari token OAuth akun
     * storage AKTIF (lihat catatan panjang di config/services.php: Google melarang
     * Service Account menulis berkas baru ke folder yang di-share dari Gmail biasa),
     * baru jatuh ke kredensial Service Account lama bila akun aktif belum pernah
     * dihubungkan lewat OAuth (mis. instalasi lama yang belum sempat migrasi, atau
     * memang memakai folder Shared Drive/Workspace yang masih boleh diakses Service Account).
     */
    protected function client(): GoogleClient
    {
        if ($this->client) {
            return $this->client;
        }

        $akun = StorageAccount::aktif();

        if ($akun && $akun->googleTerhubung()) {
            return $this->client = $this->clientOAuthUntukAkun($akun);
        }

        return $this->client = $this->clientServiceAccount();
    }

    /**
     * Bangun client dari token OAuth tersimpan milik SATU akun storage, me-refresh
     * (dan menyimpan ulang) access token-nya lebih dulu bila sudah kedaluwarsa —
     * refresh_token sendiri tidak pernah kedaluwarsa selama belum dicabut manual.
     *
     * Publik (bukan cuma dipakai client() di atas) — GoogleOAuthController::callback()
     * juga memanggilnya langsung untuk membangun client MILIK AKUN MASTER saat
     * membagikan folder master ke akun storage lain yang baru terhubung.
     */
    public function clientOAuthUntukAkun(StorageAccount $akun): GoogleClient
    {
        $refreshToken = $akun->googleRefreshToken() ?? throw new RuntimeException(
            "Token Google Drive akun {$akun->email_gmail_institusi} tidak bisa dibaca lagi ".
            '(biasanya karena APP_KEY di .env sempat diganti setelah akun terhubung). '.
            'Hubungkan ulang akun lewat menu Akun & Storage.'
        );

        $client = new GoogleClient;
        $client->setClientId(config('services.google_drive.oauth_client_id'));
        $client->setClientSecret(config('services.google_drive.oauth_client_secret'));
        $client->setAccessToken([
            'access_token' => $akun->google_access_token,
            'refresh_token' => $refreshToken,
        ]);

        $kedaluwarsa = ! $akun->google_token_expires_at || now()->gte($akun->google_token_expires_at);

        if (! $kedaluwarsa) {
            return $client;
        }

        $baru = $client->fetchAccessTokenWithRefreshToken($refreshToken);

        if (isset($baru['error'])) {
            throw new RuntimeException(
                "Sesi Google Drive akun {$akun->email_gmail_institusi} sudah tidak berlaku (".
                ($baru['error_description'] ?? $baru['error']).'). Hubungkan ulang lewat menu Akun & Storage.'
            );
        }

        $akun->forceFill([
            'google_access_token' => $baru['access_token'],
            'google_refresh_token' => $baru['refresh_token'] ?? $refreshToken,
            'google_token_expires_at' => now()->addSeconds($baru['expires_in']),
        ])->save();

        return $client;
    }

    /**
     * Kredensial JSON Service Account dari config/services.php (google_drive.service_account_path)
     * — jalur fallback lama, lihat catatan client() di atas.
     */
    protected function clientServiceAccount(): GoogleClient
    {
        $path = config('services.google_drive.service_account_path');

        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException(
                "Berkas kredensial Google Service Account tidak ditemukan di: {$path}. ".
                'Lihat storage/app/google/README.md untuk cara mendapatkannya.'
            );
        }

        $client = new GoogleClient;
        $client->setAuthConfig($path);
        // Scope penuh (bukan drive.file) karena Service Account perlu menjelajah
        // folder yang DIBAGIKAN kepadanya oleh akun Gmail institusi, bukan hanya
        // berkas yang ia buat sendiri.
        $client->addScope(Drive::DRIVE);

        return $client;
    }

    /**
     * Siapkan objek Drive service (API wrapper) sekali saja, di atas client yang sudah otentik.
     */
    protected function drive(): Drive
    {
        return $this->drive ??= new Drive($this->client());
    }
}
