<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Data lama: folder tahapan kegiatan cukup bernama tahapannya, tanpa nama kegiatan
 * (lihat App\Console\Commands\RapikanFolderTahapanCommand). Bagian Drive best-effort:
 * kegagalan tidak menggagalkan deploy — jalankan ulang perintahnya bila perlu.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            Artisan::call('kegiatan:rapikan-folder-tahapan');
            Log::info('kegiatan:rapikan-folder-tahapan: '.trim(Artisan::output()));
        } catch (\Throwable $e) {
            Log::warning('Gagal merapikan nama folder tahapan saat migrasi: '.$e->getMessage());
        }
    }

    public function down(): void
    {
        // Nama folder lama tidak dikembalikan.
    }
};
