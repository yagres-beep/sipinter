<?php

namespace App\Console\Commands;

use App\Models\StorageAccount;
use App\Services\GoogleDriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cek berkala: uji sambungan tiap akun storage Google ke Google (paksa refresh
 * token). Tanpa ini, token yang sudah ditolak Google baru ketahuan saat ada yang
 * mengunggah — sementara itu menu Akun & Storage tetap menampilkan "Terhubung".
 * Hasilnya dicatat di akun (lihat GoogleDriveService::clientOAuthUntukAkun()),
 * lalu dibaca banner peringatan di semua halaman & pengingat:google-reconnect.
 */
class CekSambunganGoogleCommand extends Command
{
    protected $signature = 'google:cek-sambungan';

    protected $description = 'Uji sambungan akun storage Google Drive dan tandai akun yang terputus';

    public function handle(GoogleDriveService $drive): int
    {
        $akunList = StorageAccount::all()->filter->googleTerhubung();

        foreach ($akunList as $akun) {
            try {
                $drive->periksaSambungan($akun);
                $this->info("{$akun->email_gmail_institusi}: terhubung");
            } catch (\Throwable $e) {
                Log::warning("Cek sambungan Google {$akun->email_gmail_institusi} gagal: ".$e->getMessage());
                $this->warn("{$akun->email_gmail_institusi}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
