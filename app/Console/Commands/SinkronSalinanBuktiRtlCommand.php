<?php

namespace App\Console\Commands;

use App\Services\SinkronBuktiRtlService;
use Illuminate\Console\Command;

/**
 * Coba lagi penyalinan bukti kegiatan ke folder Evaluasi-RTL yang gagal/tertunda
 * (lihat App\Services\SinkronBuktiRtlService). Dijadwalkan tiap 5 menit.
 *
 * Bukti lama (diunggah sebelum fitur salinan ada) TIDAK ikut disalin otomatis —
 * struktur Drive lama tidak diubah massal tanpa diperiksa. Tinjau dulu dengan
 * `--bukti-lama` (hanya menghitung), lalu jalankan `--bukti-lama --jalankan`.
 */
class SinkronSalinanBuktiRtlCommand extends Command
{
    protected $signature = 'bukti:sinkron-rtl
        {--batas=50 : jumlah salinan maksimal per jalan}
        {--bukti-lama : daftarkan bukti kegiatan tertaut RTL yang belum pernah disalin}
        {--jalankan : bersama --bukti-lama: benar-benar mendaftarkan (tanpa ini hanya pratinjau)}';

    protected $description = 'Salin ulang bukti kegiatan tertaut RTL ke folder Evaluasi-RTL yang belum/gagal tersalin';

    public function handle(SinkronBuktiRtlService $sinkron): int
    {
        if ($this->option('bukti-lama')) {
            $jalankan = (bool) $this->option('jalankan');
            $jumlah = $sinkron->daftarkanBuktiLama($jalankan);

            $this->info($jalankan
                ? "{$jumlah} bukti lama didaftarkan untuk disalin ke Evaluasi-RTL (diproses bertahap oleh perintah ini)."
                : "Pratinjau: {$jumlah} bukti lama akan didaftarkan. Jalankan ulang dengan --jalankan untuk mendaftarkannya.");

            if (! $jalankan) {
                return self::SUCCESS;
            }
        }

        $hasil = $sinkron->cobaLagi((int) $this->option('batas'));

        if ($hasil['berhasil'] > 0 || $hasil['gagal'] > 0) {
            $this->info("{$hasil['berhasil']} salinan Evaluasi-RTL berhasil, {$hasil['gagal']} masih gagal.");
        }

        return self::SUCCESS;
    }
}
