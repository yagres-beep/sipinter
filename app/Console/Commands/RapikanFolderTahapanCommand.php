<?php

namespace App\Console\Commands;

use App\Livewire\PengisianKegiatan;
use App\Models\Kegiatan;
use App\Services\FolderStructureService;
use App\Services\GoogleDriveService;
use App\Services\SinkronBuktiRtlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Data lama: folder tahapan kegiatan yang dipisah ke beberapa tahapan dulu bernama
 * "<Tahapan> <uraian kegiatan>" — kini cukup "<Tahapan>" karena sudah berada di
 * dalam folder kegiatannya (Kegiatan::namaFolderRekomendasi()).
 *
 * 1. nama_folder_kustom yang sebenarnya hanya nama otomatis format lama dikosongkan
 *    (Kegiatan::namaKustomFormatLama()); nama pilihan pengguna yang lain dibiarkan.
 * 2. Folder Drive tahapan yang masih bernama format lama diganti namanya (ID folder
 *    tetap, tautan lama tidak rusak), lalu salinan Evaluasi-RTL-nya ikut dipindah.
 *
 * Aman dijalankan berulang. Dipanggil sekali oleh migrasi saat deploy; bila Drive
 * gagal saat itu, jalankan ulang: `php artisan kegiatan:rapikan-folder-tahapan`.
 */
class RapikanFolderTahapanCommand extends Command
{
    protected $signature = 'kegiatan:rapikan-folder-tahapan
        {--tanpa-drive : hanya rapikan data di aplikasi, tanpa mengganti nama folder di Google Drive}';

    protected $description = 'Folder tahapan kegiatan cukup bernama tahapannya (tanpa nama kegiatan), termasuk data lama';

    public function handle(): int
    {
        $kegiatan = Kegiatan::query()
            ->where('jenis', 'survei_sensus')
            ->whereNotNull('tahapan_survei')
            ->where('tahapan_survei', '!=', '')
            ->orderBy('id')
            ->get();

        $dikosongkan = 0;
        foreach ($kegiatan as $k) {
            if ($k->namaKustomFormatLama()) {
                $k->update(['nama_folder_kustom' => null]);
                $dikosongkan++;
            }
        }

        $this->info("{$dikosongkan} nama folder format lama dikembalikan ke rekomendasi sistem.");

        if ($this->option('tanpa-drive')) {
            return self::SUCCESS;
        }

        $diganti = 0;
        $gagal = 0;

        foreach ($kegiatan->whereNotNull('drive_folder_id')->groupBy('drive_folder_id') as $folderId => $pemakai) {
            $utama = $pemakai->first();
            $target = FolderStructureService::namaOtomatis($utama->namaFolder(), 150);

            try {
                $drive = app(GoogleDriveService::class);
                $namaSekarang = $drive->namaFile((string) $folderId);

                if ($namaSekarang === $target || ! $this->namaFormatLama($namaSekarang, $utama)) {
                    continue;
                }

                $drive->renameFile((string) $folderId, $target);
                $diganti++;

                $sinkron = app(SinkronBuktiRtlService::class);
                $pemakai->each(fn (Kegiatan $k) => $sinkron->pindahkanSalinanKegiatan($k->fresh()));
            } catch (\Throwable $e) {
                $gagal++;
                Log::warning("Gagal merapikan nama folder tahapan {$folderId}: {$e->getMessage()}");
            }
        }

        $this->info("{$diganti} folder tahapan di Google Drive diganti namanya".($gagal ? ", {$gagal} gagal (lihat log, jalankan ulang perintah ini)." : '.'));

        return self::SUCCESS;
    }

    /**
     * Nama folder Drive masih format lama: diawali nama tahapan lalu uraian kegiatan
     * ("Persiapan Sakernas", "[Persiapan] Sakernas", "Persiapan Sakernas (2)") — nama
     * yang diganti sendiri oleh pengguna langsung di Drive tidak disentuh.
     */
    protected function namaFormatLama(string $nama, Kegiatan $kegiatan): bool
    {
        $label = mb_strtolower(PengisianKegiatan::labelTahapan((string) $kegiatan->tahapan_survei));
        $nama = mb_strtolower(trim(preg_replace('/^\[([^\]]+)\]\s*/u', '$1 ', $nama) ?? ''));

        return str_starts_with($nama, $label.' ') && mb_strlen($nama) > mb_strlen($label) + 1;
    }
}
