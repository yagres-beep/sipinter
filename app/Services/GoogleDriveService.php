<?php

namespace App\Services;

use App\Models\StorageAccount;
use Google\Client as GoogleClient;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;
use Google\Service\Exception as GoogleServiceException;
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
     * Berkas di atas ukuran ini diunggah bertahap (resumable, per potongan 8 MB)
     * alih-alih dibaca utuh ke memori — unggahan bukti tidak lagi dibatasi ukuran,
     * jadi berkas ratusan MB tidak boleh menabrak memory_limit PHP. Harus kelipatan
     * 256 KB (syarat Drive API untuk unggahan resumable).
     */
    protected const UKURAN_POTONGAN_UNGGAH = 8 * 1024 * 1024;

    /**
     * Unggah satu berkas lokal ke sebuah folder Drive.
     *
     * @param  string  $localPath  Path berkas di disk lokal server (mis. hasil upload sementara Livewire).
     * @param  string  $filename  Nama berkas yang akan tersimpan di Drive (sudah di-slugify di pemanggil, lihat RF-17).
     * @param  string  $parentFolderId  ID folder Drive tujuan (hasil dari findOrCreateFolder()).
     * @param  array<string, string>  $penanda  appProperties Drive (mis. ['sipinter_kode' => kode Berkas]) —
     *                                         penanda tersembunyi supaya unggahan ulang bisa menemukan berkas yang
     *                                         sebenarnya sudah masuk Drive (lihat cariBerkasBerpenanda()).
     * @return array{id: string, name: string, web_view_link: ?string, size_bytes: int} Metadata dari Drive,
     *              termasuk `size_bytes` asli di server Drive untuk dicatat lewat
     *              StorageAccount::tambahKuotaTerpakai() (RF-10c).
     */
    public function uploadFile(string $localPath, string $filename, string $parentFolderId, string $mimeType = 'application/pdf', array $penanda = []): array
    {
        if (! is_file($localPath)) {
            throw new RuntimeException("Berkas lokal tidak ditemukan: {$localPath}");
        }

        $metadata = new DriveFile(array_filter([
            'name' => $filename,
            'parents' => [$parentFolderId],
            'appProperties' => $penanda ?: null,
        ]));

        /** @var DriveFile $uploaded */
        $uploaded = $this->panggilApi(
            fn () => filesize($localPath) > self::UKURAN_POTONGAN_UNGGAH
                ? $this->unggahBertahap($localPath, $metadata, $mimeType)
                : $this->drive()->files->create($metadata, [
                    'data' => file_get_contents($localPath),
                    'mimeType' => $mimeType,
                    'uploadType' => 'multipart',
                    // 'fields' membatasi respons API hanya ke kolom yang kita perlukan (hemat kuota API).
                    'fields' => 'id, name, webViewLink, size',
                ]),
            "mengunggah berkas \"{$filename}\""
        );

        return [
            'id' => $uploaded->getId(),
            'name' => $uploaded->getName(),
            'web_view_link' => $uploaded->getWebViewLink(),
            'size_bytes' => (int) $uploaded->getSize(),
        ];
    }

    /**
     * Unggahan resumable per potongan UKURAN_POTONGAN_UNGGAH — pemakaian memori tetap
     * kecil berapa pun ukuran berkasnya. Batas waktu eksekusi PHP dilepas selama
     * unggahan berjalan karena berkas besar bisa memakan waktu lebih lama dari
     * max_execution_time.
     */
    protected function unggahBertahap(string $localPath, DriveFile $metadata, string $mimeType): DriveFile
    {
        @set_time_limit(0);

        $client = $this->client();
        $client->setDefer(true);

        try {
            $request = $this->drive()->files->create($metadata, ['fields' => 'id, name, webViewLink, size']);
            $media = new MediaFileUpload($client, $request, $mimeType, null, true, self::UKURAN_POTONGAN_UNGGAH);
            $media->setFileSize(filesize($localPath));

            $handle = fopen($localPath, 'rb');
            $hasil = false;

            try {
                while ($hasil === false && ! feof($handle)) {
                    $hasil = $media->nextChunk(stream_get_contents($handle, self::UKURAN_POTONGAN_UNGGAH));
                }
            } finally {
                fclose($handle);
            }
        } finally {
            $client->setDefer(false);
        }

        if (! $hasil instanceof DriveFile) {
            throw new RuntimeException('Unggahan bertahap ke Google Drive tidak selesai.');
        }

        return $hasil;
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
        $folder = $this->panggilApi(
            fn () => $this->drive()->files->create($metadata, ['fields' => 'id']),
            "membuat folder \"{$name}\""
        );

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

        $hasil = $this->panggilApi(fn () => $this->drive()->files->listFiles([
            'q' => $query,
            'fields' => 'files(id, name)',
            'pageSize' => 1,
        ]), "mencari folder \"{$name}\"");

        $folderDitemukan = $hasil->getFiles()[0] ?? null;

        if ($folderDitemukan) {
            return $folderDitemukan->getId();
        }

        return $this->createFolder($name, $parentFolderId);
    }

    /**
     * Ganti nama sebuah berkas/folder Drive yang sudah ada (ID-nya tetap sama,
     * jadi tautan lama tidak rusak). Dipakai untuk mengganti nama berkas bukti dari
     * web (Berkas::gantiNama()) & merapikan nama folder IKU lama.
     */
    public function renameFile(string $fileId, string $namaBaru): void
    {
        $this->panggilApi(
            fn () => $this->drive()->files->update($fileId, new DriveFile(['name' => $namaBaru]), ['fields' => 'id']),
            "mengganti nama menjadi \"{$namaBaru}\""
        );
    }

    /**
     * Hapus berkas dari Drive secara PERMANEN (tidak lewat Sampah, tidak bisa
     * dipulihkan) — pengguna sudah diminta konfirmasi lebih dulu di layar.
     */
    public function deleteFile(string $fileId, ?StorageAccount $akun = null): void
    {
        $drive = $this->driveMilik($akun);

        $this->abaikanBilaSudahTiada(fn () => $this->panggilApi(fn () => $drive->files->delete($fileId), 'menghapus berkas'));
    }

    /**
     * Pindahkan berkas ke Sampah Drive — masih bisa dipulihkan dari Sampah selama 30
     * hari. Dipakai saat SATU KEGIATAN dihapus (buktinya bisa saja masih dibutuhkan,
     * mis. bagian dari RTL triwulan itu), beda dari hapus satu bukti (deleteFile()).
     */
    public function trashFile(string $fileId, ?StorageAccount $akun = null): void
    {
        $drive = $this->driveMilik($akun);

        $this->abaikanBilaSudahTiada(fn () => $this->panggilApi(
            fn () => $drive->files->update($fileId, new DriveFile(['trashed' => true]), ['fields' => 'id']),
            'memindahkan berkas ke Sampah'
        ));
    }

    /**
     * Drive service milik akun storage yang dulu mengunggah berkas — di Drive biasa
     * hanya PEMILIK berkas yang boleh menghapus/membuangnya.
     */
    protected function driveMilik(?StorageAccount $akun): Drive
    {
        return $akun && $akun->id !== StorageAccount::aktif()?->id && $akun->googleTerhubung()
            ? new Drive($this->clientOAuthUntukAkun($akun))
            : $this->drive();
    }

    /** Berkas yang memang sudah tidak ada di Drive (404) dianggap beres. */
    protected function abaikanBilaSudahTiada(callable $panggilan): void
    {
        try {
            $panggilan();
        } catch (RuntimeException $e) {
            if (($e->getPrevious()?->getCode()) !== 404) {
                throw $e;
            }
        }
    }

    /**
     * Daftar folder anak langsung (tidak termasuk yang sudah di-trash) di dalam
     * sebuah folder, sebagai [id => nama].
     *
     * @return array<string, string>
     */
    public function foldersInFolder(string $parentFolderId): array
    {
        $hasil = $this->panggilApi(fn () => $this->drive()->files->listFiles([
            'q' => sprintf("mimeType = 'application/vnd.google-apps.folder' and '%s' in parents and trashed = false", $parentFolderId),
            'fields' => 'files(id, name)',
            'pageSize' => 1000,
        ]), 'membaca daftar folder');

        $folder = [];
        foreach ($hasil->getFiles() as $file) {
            $folder[$file->getId()] = $file->getName();
        }

        return $folder;
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
        $hasil = $this->panggilApi(fn () => $this->drive()->files->listFiles([
            'q' => sprintf("'%s' in parents and trashed = false", $parentFolderId),
            'fields' => 'files(name)',
            'pageSize' => 1000,
        ]), 'membaca isi folder');

        return array_map(fn (DriveFile $file) => $file->getName(), $hasil->getFiles());
    }

    /**
     * Berkas (belum di Sampah) yang diunggah dengan penanda appProperties $kunci=$nilai
     * — dipakai sebelum mengunggah ulang supaya berkas yang sebenarnya SUDAH masuk
     * Drive (tapi database gagal mencatatnya) tidak diunggah dobel.
     *
     * @return array{id: string, name: string, web_view_link: ?string, size_bytes: int}|null
     */
    public function cariBerkasBerpenanda(string $kunci, string $nilai): ?array
    {
        $nilai = str_replace(["\\", "'"], ["\\\\", "\\'"], $nilai);

        $hasil = $this->panggilApi(fn () => $this->drive()->files->listFiles([
            'q' => "appProperties has { key='{$kunci}' and value='{$nilai}' } and trashed = false",
            'fields' => 'files(id, name, webViewLink, size)',
            'pageSize' => 1,
        ]), 'mencari berkas');

        $file = $hasil->getFiles()[0] ?? null;

        return $file ? [
            'id' => $file->getId(),
            'name' => $file->getName(),
            'web_view_link' => $file->getWebViewLink(),
            'size_bytes' => (int) $file->getSize(),
        ] : null;
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
        $file = $this->panggilApi(
            fn () => $this->drive()->files->get($fileId, ['fields' => 'webViewLink']),
            'mengambil tautan berkas'
        );

        return $file->getWebViewLink();
    }

    /**
     * Unduh ISI (bytes) sebuah berkas dari Drive lewat ID-nya — dipakai
     * BerkasDownloadController sebagai jalur cadangan saat salinan lokalnya
     * sudah tidak ada (disk lokal server TIDAK persisten di Render free plan,
     * terhapus tiap kali container di-deploy ulang; lihat catatan di controller).
     *
     * $pemilik = akun storage TEMPAT berkas itu diunggah (Berkas::storage_account_id,
     * RF-10b). Wajib dicoba lebih dulu: berkas lama belum tentu bisa dibaca akun
     * aktif (mis. token lama sempat terbit dengan scope drive.file, atau folder
     * akun lama tidak dibagikan ke akun aktif) — begitu storage aktif berpindah ke
     * akun lain, client() (terikat ke akun aktif) bisa mendapat 404 "File not found"
     * untuk berkas lama. Bila akun pemilik tidak bisa dipakai
     * (belum/tidak lagi terhubung, token dicabut), jatuh ke client() biasa.
     */
    public function downloadFileContent(string $fileId, ?StorageAccount $pemilik = null): string
    {
        $unduh = fn (Drive $drive) => $this->panggilApi(
            fn () => $drive->files->get($fileId, ['alt' => 'media'])->getBody()->getContents(),
            'mengunduh berkas'
        );

        if ($pemilik && $pemilik->googleTerhubung() && $pemilik->id !== StorageAccount::aktif()?->id) {
            try {
                return $unduh(new Drive($this->clientOAuthUntukAkun($pemilik)));
            } catch (\Throwable) {
                // Lanjut ke client() akun aktif / Service Account di bawah.
            }
        }

        return $unduh($this->drive());
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
     * Ambil kuota Drive SEBENARNYA dari Google (bukan hitungan sendiri) untuk satu
     * akun storage — dipanggil lewat client MILIK akun itu sendiri (bukan client()
     * yang terikat ke akun "aktif" saat ini), supaya bisa disinkronkan untuk akun
     * mana pun, aktif atau tidak. Dipakai StorageAccounts::sinkronKuota() karena
     * kuota_terpakai tersimpan hanya bertambah lewat tambahKuotaTerpakai() (dihitung
     * dari unggahan lewat aplikasi ini saja) sehingga bisa melenceng dari kondisi
     * asli di Drive (mis. ada berkas lain di akun yang sama, di luar aplikasi ini).
     *
     * @return array{usage_bytes: int, limit_bytes: ?int} limit_bytes null berarti
     *              akun tidak punya batas kuota tetap (mis. Google Workspace tanpa
     *              batas) — pemanggil sebaiknya tidak menimpa kuota_total bila null.
     */
    public function ambilKuota(StorageAccount $akun): array
    {
        if (! $akun->googleTerhubung()) {
            throw new RuntimeException("Akun {$akun->email_gmail_institusi} belum terhubung ke Google Drive.");
        }

        $client = $this->clientOAuthUntukAkun($akun);
        $drive = new Drive($client);

        $about = $this->panggilApi(
            fn () => $drive->about->get(['fields' => 'storageQuota']),
            "mengambil info kuota akun {$akun->email_gmail_institusi}"
        );
        $kuota = $about->getStorageQuota();

        return [
            'usage_bytes' => (int) $kuota->getUsage(),
            'limit_bytes' => $kuota->getLimit() !== null ? (int) $kuota->getLimit() : null,
        ];
    }

    /**
     * Buat SATU folder di root ("My Drive") akun yang baru saja login lewat OAuth,
     * memakai client yang MASIH membawa access token segar hasil pertukaran kode
     * otorisasi (belum tentu = StorageAccount::aktif(), dan belum tentu tersimpan
     * lagi di DB saat method ini dipanggil) — dipakai HANYA oleh GoogleOAuthController
     * tepat setelah sebuah akun berhasil terhubung (lihat callback()), untuk
     * menyiapkan folder root milik akun itu (lihat catatan di GoogleOAuthController)
     * sebelum drive_folder_id akun itu diisi.
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
                $this->panggilApi(
                    fn () => $this->drive()->permissions->listPermissions($folderId, ['fields' => 'permissions(emailAddress)'])->getPermissions(),
                    'membaca daftar akses folder'
                )
            )->contains(fn (Permission $p) => strcasecmp((string) $p->getEmailAddress(), $email) === 0);

            if ($sudahDibagikan) {
                return;
            }

            $this->panggilApi(fn () => $this->drive()->permissions->create($folderId, new Permission([
                'type' => 'user',
                'role' => $role,
                'emailAddress' => $email,
            ]), ['sendNotificationEmail' => false]), "membagikan folder ke {$email}");
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
     * refresh_token sendiri tidak kedaluwarsa selama belum dicabut, KECUALI status
     * OAuth consent screen masih "Testing" (Google membatasinya 7 hari; lihat
     * catatan di GoogleOAuthController).
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
            $saran = $baru['error'] === 'invalid_grant'
                ? ' Bila ini terjadi tiap ±7 hari, ubah status aplikasi di Google Cloud Console '.
                  '(Google Auth Platform > Audience) dari "Testing" ke "In production", lalu hubungkan ulang sekali.'
                : '';

            throw new RuntimeException(
                "Sesi Google Drive akun {$akun->email_gmail_institusi} sudah tidak berlaku (".
                ($baru['error_description'] ?? $baru['error']).'). Hubungkan ulang lewat menu Akun & Storage.'.$saran
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

    /**
     * Bungkus SATU panggilan ke Drive API supaya error mentah dari Google (biasanya
     * JSON teknis, mis. `{"error":{"code":404,"message":"File not found: ..."}}`)
     * diterjemahkan jadi RuntimeException berbahasa Indonesia yang aman ditampilkan
     * apa adanya ke Tim SAKIP.
     *
     * Ini yang membuat kode pemanggil (Livewire component, dsb.) yang SUDAH terbiasa
     * menangkap RuntimeException (lihat FolderConfigManager, FolderStructureService)
     * tidak lagi kebobolan Google\Service\Exception mentah — tanpa perubahan ini,
     * exception itu lolos sampai ke Laravel dan berakhir sebagai halaman 500 kosong
     * tanpa keterangan, alih-alih notifikasi gagal yang menjelaskan sebabnya.
     *
     * @template T
     * @param  callable(): T  $panggilan
     * @return T
     */
    protected function panggilApi(callable $panggilan, string $konteks)
    {
        try {
            return $panggilan();
        } catch (GoogleServiceException $e) {
            throw new RuntimeException($this->pesanGoogleRamah($e, $konteks), previous: $e);
        }
    }

    /**
     * Ambil pesan INTI dari body JSON error Google (bukan seluruh JSON mentah), lalu
     * tambahkan saran tindak lanjut untuk kode HTTP yang paling sering terjadi di
     * SIPINTER: 404 (folder sudah dihapus/ID salah) & 403 (folder belum/tidak lagi
     * dibagikan ke akun ini).
     */
    protected function pesanGoogleRamah(GoogleServiceException $e, string $konteks): string
    {
        $data = json_decode($e->getMessage(), true);
        $pesanAsli = $data['error']['message'] ?? $e->getMessage();

        $saran = match ($e->getCode()) {
            404 => ' Folder/berkas yang dituju kemungkinan sudah dihapus atau ID-nya sudah tidak valid — periksa & perbarui folder Drive-nya di menu Akun & Storage.',
            403 => ' Akun Drive ini kemungkinan belum/tidak lagi punya akses ke folder tersebut — pastikan folder sudah dibagikan (peran Editor) ke akun ini.',
            default => '',
        };

        return "Gagal {$konteks}: {$pesanAsli}.{$saran}";
    }
}
