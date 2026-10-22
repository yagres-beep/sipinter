<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Backup database otomatis tiap minggu.
 *
 * Dijadwalkan tiap jam (routes/console.php), bukan sekali seminggu di jam tetap:
 * server bisa sedang tidur atau di-deploy ulang tepat di jam itu, dan jadwal
 * yang terlewat tidak diulang Laravel. Jadi tiap jam dicek saja -- backup baru
 * dibuat hanya bila belum ada backup BERHASIL dalam 7 hari terakhir (backup
 * manual dari halaman Backup Database juga dihitung).
 *
 * Bila percobaan otomatis gagal, dicoba lagi paling cepat sehari kemudian supaya
 * riwayat tidak dibanjiri baris "Gagal" tiap jam.
 */
class BackupMingguanCommand extends Command
{
    protected $signature = 'backup:mingguan {--paksa : Buat backup sekarang tanpa cek jadwal}';

    protected $description = 'Buat backup database otomatis bila belum ada backup berhasil dalam 7 hari terakhir';

    public const JEDA_HARI = 7;

    public const JEDA_COBA_ULANG_JAM = 24;

    public function handle(BackupService $backupService): int
    {
        if (! $this->option('paksa')) {
            $adaBackupBaru = Backup::where('status', Backup::STATUS_BERHASIL)
                ->where('created_at', '>=', now()->subDays(self::JEDA_HARI))
                ->exists();

            if ($adaBackupBaru) {
                $this->info('Sudah ada backup berhasil dalam '.self::JEDA_HARI.' hari terakhir, dilewati.');

                return self::SUCCESS;
            }

            $baruGagalOtomatis = Backup::whereNull('dibuat_oleh')
                ->where('status', Backup::STATUS_GAGAL)
                ->where('created_at', '>=', now()->subHours(self::JEDA_COBA_ULANG_JAM))
                ->exists();

            if ($baruGagalOtomatis) {
                $this->info('Backup otomatis terakhir gagal kurang dari '.self::JEDA_COBA_ULANG_JAM.' jam lalu, dicoba lagi nanti.');

                return self::SUCCESS;
            }
        }

        $backup = $backupService->buat(null);

        if ($backup->status !== Backup::STATUS_BERHASIL) {
            Log::error("Backup database otomatis gagal: {$backup->catatan}");
            $this->error("Backup gagal: {$backup->catatan}");

            return self::FAILURE;
        }

        $this->info("Backup otomatis dibuat: {$backup->nama_file}");

        return self::SUCCESS;
    }
}
