<?php

namespace App\Console\Commands;

use App\Jobs\KirimPengingatWhatsAppJob;
use App\Models\PengaturanPenerimaPengingat;
use App\Models\PengaturanTemplatePengingat;
use App\Models\StorageAccount;
use Illuminate\Console\Command;

/**
 * Cek harian: akun storage Google yang token OAuth-nya sudah tidak valid lagi
 * (StorageAccount::googlePerluHubungUlang()) — unggahan bukti dukung ke Drive
 * lewat akun itu sedang mati sampai Tim SAKIP menghubungkan ulang.
 */
class PengingatSambungGoogleCommand extends Command
{
    protected $signature = 'pengingat:google-reconnect';

    protected $description = 'Kirim pengingat WA ke Tim SAKIP untuk akun Google yang perlu disambungkan ulang';

    public function handle(): int
    {
        $akunPerluHubungUlang = StorageAccount::all()->filter->googlePerluHubungUlang();

        if ($akunPerluHubungUlang->isEmpty()) {
            return self::SUCCESS;
        }

        $penerima = PengaturanPenerimaPengingat::resolveUsers('google_reconnect');

        foreach ($akunPerluHubungUlang as $akun) {
            $pesan = PengaturanTemplatePengingat::render('google_reconnect', [
                'akun_google' => $akun->email_gmail_institusi,
            ]);

            foreach ($penerima as $user) {
                KirimPengingatWhatsAppJob::dispatch($user->nomor_telepon, $pesan);
            }
        }

        return self::SUCCESS;
    }
}
