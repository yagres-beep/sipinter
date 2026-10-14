<?php

namespace App\Console\Commands;

use App\Models\Berkas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Susulkan ke Google Drive berkas yang unggahan pertamanya gagal (Drive belum
 * tersambung, token kedaluwarsa, jaringan putus, dsb.) selama salinan lokalnya
 * masih ada. Disk lokal container terhapus tiap deploy ulang — tanpa perintah ini
 * berkas seperti itu hilang permanen begitu server di-deploy ulang.
 */
class SinkronBerkasDriveCommand extends Command
{
    protected $signature = 'berkas:sinkron-drive {--batas=50 : jumlah berkas maksimal per jalan}';

    protected $description = 'Unggah ulang ke Google Drive berkas yang belum tersalin ke sana';

    /**
     * Berhenti setelah sekian kegagalan beruntun — kemungkinan besar Drive-nya
     * sendiri yang sedang bermasalah, tidak perlu mencoba sisa antrean satu per satu.
     */
    protected const BATAS_GAGAL_BERUNTUN = 3;

    public function handle(): int
    {
        $berhasil = 0;
        $gagalBeruntun = 0;

        $antrean = Berkas::belumDiDrive()->oldest('id')->limit((int) $this->option('batas'))->get();

        foreach ($antrean as $berkas) {
            try {
                if ($berkas->sinkronUlangKeDrive()) {
                    $berhasil++;
                }
                $gagalBeruntun = 0;
            } catch (\Throwable $e) {
                Log::warning("Sinkron ulang berkas {$berkas->kode} ke Drive masih gagal: {$e->getMessage()}");

                if (++$gagalBeruntun >= self::BATAS_GAGAL_BERUNTUN) {
                    break;
                }
            }
        }

        if ($berhasil > 0) {
            $this->info("{$berhasil} berkas berhasil disusulkan ke Google Drive.");
        }

        return self::SUCCESS;
    }
}
