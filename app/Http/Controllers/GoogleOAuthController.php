<?php

namespace App\Http\Controllers;

use App\Models\StorageAccount;
use App\Services\GoogleDriveService;
use Google\Client as GoogleClient;
use Google\Service\Drive;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Login OAuth "Sign in with Google" untuk akun storage (RF baru — lihat catatan di
 * config/services.php). Google tidak lagi mengizinkan Service Account menulis berkas
 * baru ke folder yang di-share dari akun Gmail biasa, jadi Tim SAKIP harus login
 * sekali ke akun Gmail institusi lewat alur ini supaya unggahan berikutnya jalan
 * SEBAGAI akun itu sendiri (pakai kuota pribadinya), bukan sebagai robot tanpa kuota.
 *
 * Scope yang diminta sengaja drive.file + drive.metadata.readonly (BUKAN drive
 * penuh) — keduanya tidak tergolong "restricted scope" Google, jadi cukup status
 * Testing + Test user (lihat Audience di Google Auth Platform), tidak perlu proses
 * verifikasi aplikasi yang panjang. drive.file membatasi tulis/baca isi berkas HANYA
 * ke yang dibuat aplikasi ini sendiri — karena itu begitu berhasil terhubung,
 * langsung dibuatkan folder root baru (lihat callback()). metadata.readonly
 * ditambahkan supaya FolderStructureService boleh MENCARI (files.list) folder yang
 * sudah dibuat sebelumnya — drive.file sendiri menolak operasi pencarian sama
 * sekali (403 ACCESS_TOKEN_SCOPE_INSUFFICIENT), hanya boleh operasi per-berkas.
 */
class GoogleOAuthController extends Controller
{
    public function __construct(protected GoogleDriveService $driveService) {}

    public function redirect(StorageAccount $storageAccount): RedirectResponse
    {
        try {
            $client = $this->clientDasar();
        } catch (RuntimeException $e) {
            return redirect()->route('storage-accounts.index')->with('error', $e->getMessage());
        }

        $state = Str::random(40);
        session([
            'google_oauth_state' => $state,
            'google_oauth_storage_account_id' => $storageAccount->id,
        ]);

        $client->setRedirectUri(route('storage-accounts.google-callback'));
        $client->setState($state);
        $client->setAccessType('offline');
        // 'consent' dipaksa supaya Google SELALU mengirim ulang refresh_token — tanpa ini,
        // login kedua+ ke akun yang sama tidak akan menyertakan refresh_token sama sekali.
        $client->setPrompt('consent');
        // Scope PENUH (Drive::DRIVE), bukan drive.file — drive.file hanya boleh menyentuh
        // berkas/folder yang DIBUAT SENDIRI oleh akun yang login, jadi akun BUKAN-master
        // tidak akan pernah bisa menulis ke folder MASTER yang dibagikan (share) kepadanya
        // (lihat GoogleOAuthController::callback() & GoogleDriveService::bagikanFolder(),
        // config('services.google_drive.master_account_email')). Scope ini tergolong
        // "sensitive" (bukan "restricted") di Google — tetap boleh dipakai akun yang
        // terdaftar sebagai Test user tanpa proses verifikasi aplikasi yang panjang,
        // selama status OAuth consent screen masih Testing.
        $client->addScope([Drive::DRIVE, 'openid', 'email']);

        return redirect()->away($client->createAuthUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $stateTersimpan = session('google_oauth_state');
        $storageAccountId = session('google_oauth_storage_account_id');
        session()->forget(['google_oauth_state', 'google_oauth_storage_account_id']);

        if (! $stateTersimpan || $request->query('state') !== $stateTersimpan || ! $storageAccountId) {
            return redirect()->route('storage-accounts.index')
                ->with('error', 'Sesi menghubungkan Google Drive tidak valid atau kedaluwarsa — silakan coba lagi.');
        }

        $storageAccount = StorageAccount::find($storageAccountId);

        if (! $storageAccount) {
            return redirect()->route('storage-accounts.index')->with('error', 'Akun storage tidak ditemukan.');
        }

        if ($request->query('error')) {
            return redirect()->route('storage-accounts.index')->with('error', 'Izin Google Drive dibatalkan.');
        }

        // Token lama yang sudah tidak bisa didekripsi (APP_KEY berubah) wajib dibersihkan
        // LEWAT QUERY MENTAH di sini, SEBELUM Eloquent sempat menyentuhnya lagi. save() di
        // bawah memanggil isDirty(), yang SELALU mendekripsi nilai ORIGINAL kolom encrypted
        // untuk dibandingkan dengan nilai baru — jalur ini TIDAK lewat googleRefreshToken()
        // yang sudah menangkap DecryptException, jadi tanpa pembersihan ini save() akan
        // melempar "The MAC is invalid." dan mematikan satu-satunya jalur pemulihan
        // (Hubungkan ulang) untuk akun yang justru sedang mencoba pulih.
        if ($storageAccount->googlePerluHubungUlang()) {
            DB::table('storage_account')->where('id', $storageAccount->id)->update(['google_refresh_token' => null]);
            $storageAccount->refresh();
        }

        try {
            $client = $this->clientDasar();
        } catch (RuntimeException $e) {
            return redirect()->route('storage-accounts.index')->with('error', $e->getMessage());
        }

        $client->setRedirectUri(route('storage-accounts.google-callback'));

        $token = $client->fetchAccessTokenWithAuthCode((string) $request->query('code'));

        if (isset($token['error'])) {
            return redirect()->route('storage-accounts.index')
                ->with('error', 'Gagal menghubungkan Google Drive: '.($token['error_description'] ?? $token['error']));
        }

        // Cocokkan email akun Google yang benar-benar login dengan email_gmail_institusi
        // yang terdaftar — mencegah Tim SAKIP salah pilih/salah login ke akun Gmail lain.
        $emailTerhubung = isset($token['id_token']) ? ($client->verifyIdToken($token['id_token'])['email'] ?? null) : null;

        if ($emailTerhubung !== null && strcasecmp($emailTerhubung, $storageAccount->email_gmail_institusi) !== 0) {
            return redirect()->route('storage-accounts.index')->with('error', sprintf(
                'Akun Google yang login (%s) tidak sama dengan email akun storage ini (%s). Login ulang pakai akun yang benar.',
                $emailTerhubung,
                $storageAccount->email_gmail_institusi
            ));
        }

        $storageAccount->google_access_token = $token['access_token'];
        // googleRefreshToken() (bukan properti langsung) karena token LAMA bisa saja tidak
        // bisa didekripsi — justru itu alasan paling sering orang menghubungkan ulang di
        // sini, jadi jangan sampai jalur pemulihannya ikut melempar DecryptException.
        $storageAccount->google_refresh_token = $token['refresh_token'] ?? $storageAccount->googleRefreshToken();
        $storageAccount->google_token_expires_at = now()->addSeconds($token['expires_in']);
        $storageAccount->save();

        if (! $storageAccount->googleTerhubung()) {
            return redirect()->route('storage-accounts.index')->with('error',
                'Google tidak mengirim izin akses jangka panjang (refresh token). Coba hubungkan ulang — bila '.
                'masih gagal, cabut dulu akses SIPINTER di myaccount.google.com/permissions lalu ulangi.'
            );
        }

        $master = StorageAccount::master();
        $akunIniMaster = $master && $master->id === $storageAccount->id;

        try {
            if ($master && ! $akunIniMaster) {
                // Akun BUKAN-master: jangan buat folder root sendiri — pakai folder milik
                // akun master supaya semua akun institusi tetap menulis ke SATU struktur
                // folder yang sama (lihat StorageAccount::master() & menu Akun & Storage →
                // "Jadikan Master").
                if (! $master->drive_folder_id) {
                    return redirect()->route('storage-accounts.index')->with('error', sprintf(
                        'Akun master folder (%s) belum siap — hubungkan akun itu ke Google Drive terlebih dahulu, baru hubungkan akun ini.',
                        $master->email_gmail_institusi
                    ));
                }

                if (! $master->googleTerhubung()) {
                    return redirect()->route('storage-accounts.index')->with('error', sprintf(
                        'Akun master folder (%s) sesi Drive-nya belum/tidak aktif — hubungkan ulang akun itu dulu supaya bisa membagikan foldernya ke akun ini.',
                        $master->email_gmail_institusi
                    ));
                }

                $clientMaster = $this->driveService->clientOAuthUntukAkun($master);
                $this->driveService->bagikanFolder($clientMaster, $master->drive_folder_id, $storageAccount->email_gmail_institusi);

                $storageAccount->update(['drive_folder_id' => $master->drive_folder_id]);
            } elseif (! $storageAccount->drive_folder_id) {
                // Akun master (atau tidak ada master dikonfigurasi): buat folder root
                // sendiri HANYA bila belum pernah punya (mis. drive_folder_id sudah diisi
                // manual sebelumnya lewat menu Akun & Storage — dipertahankan apa adanya).
                $folderId = $this->driveService->buatFolderRoot($client, 'SIPINTER');
                $storageAccount->update(['drive_folder_id' => $folderId]);
            }
        } catch (\Throwable $e) {
            Log::error('Gagal menyiapkan folder Drive setelah OAuth: '.$e->getMessage());

            return redirect()->route('storage-accounts.index')->with('error',
                'Akun Google berhasil terhubung, tapi gagal menyiapkan folder Drive: '.$e->getMessage()
            );
        }

        return redirect()->route('storage-accounts.index')
            ->with('status', "Akun {$storageAccount->email_gmail_institusi} berhasil terhubung ke Google Drive.");
    }

    protected function clientDasar(): GoogleClient
    {
        $clientId = config('services.google_drive.oauth_client_id');
        $clientSecret = config('services.google_drive.oauth_client_secret');

        if (blank($clientId) || blank($clientSecret)) {
            throw new RuntimeException(
                'GOOGLE_OAUTH_CLIENT_ID / GOOGLE_OAUTH_CLIENT_SECRET belum diisi di .env — lihat config/services.php.'
            );
        }

        $client = new GoogleClient;
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);

        return $client;
    }
}
