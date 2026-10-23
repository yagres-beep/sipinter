<?php

namespace App\Console\Commands;

use App\Models\Berkas;
use App\Models\LogAktivitas;
use App\Support\KonsistensiBukti;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Hapus berkas LOKAL di folder bukti yang tidak dirujuk baris `berkas` mana pun
 * (sisa unggahan yang transaksinya batal, penghapusan berkas lokal yang gagal, dsb.).
 *
 * Pengaman:
 * - Bawaannya hanya LAPORAN (dry-run). Penghapusan butuh --jalankan.
 * - Hanya folder bukti yang dikenal (KonsistensiBukti::FOLDER_BUKTI).
 * - Hanya berkas yang lebih tua dari --umur-jam (bawaan 24 jam), supaya unggahan
 *   yang sedang diproses tidak ikut terhapus.
 * - Rujukan diperiksa ULANG tepat sebelum tiap berkas dihapus.
 * - TIDAK PERNAH menghapus baris database maupun berkas di Google Drive.
 * - Ringkasan tindakan dicatat ke log_aktivitas.
 */
class BersihkanBuktiLokalYatimCommand extends Command
{
    protected $signature = 'bukti:bersihkan-lokal-yatim
        {--jalankan : benar-benar menghapus (tanpa ini hanya laporan)}
        {--umur-jam=24 : hanya berkas yang terakhir diubah lebih dari sekian jam lalu}';

    protected $description = 'Laporkan/hapus berkas bukti lokal yang tidak dirujuk database (tidak menyentuh database & Drive)';

    public function handle(KonsistensiBukti $periksa): int
    {
        $umurJam = max(1, (int) $this->option('umur-jam'));
        $batas = now()->subHours($umurJam);
        $jalankan = (bool) $this->option('jalankan');

        $kandidat = array_values(array_filter($periksa->lokalYatim(), fn ($f) => $f['diubah']->lt($batas)));

        $this->line(($jalankan ? 'MODE HAPUS' : 'MODE LAPORAN (tidak ada yang dihapus)').": ".count($kandidat)." berkas lokal yatim lebih tua dari {$umurJam} jam.");

        if ($kandidat === []) {
            return self::SUCCESS;
        }

        if (! $jalankan) {
            $this->table(['path', 'ukuran (byte)', 'diubah'], array_map(fn ($f) => [$f['path'], $f['ukuran'], $f['diubah']->wita()->format('Y-m-d H:i')], array_slice($kandidat, 0, 200)));
            $this->line('Jalankan ulang dengan --jalankan untuk menghapus.');

            return self::SUCCESS;
        }

        $disk = Storage::disk('local');
        $dihapus = [];
        $dilewati = [];

        foreach ($kandidat as $f) {
            $path = $f['path'];

            // Periksa ulang tepat sebelum menghapus: masih di folder bukti, masih ada,
            // masih tua, dan TIDAK dirujuk baris berkas mana pun.
            $aman = in_array(explode('/', $path)[0], KonsistensiBukti::FOLDER_BUKTI, true)
                && ! str_contains($path, '..')
                && $disk->exists($path)
                && $disk->lastModified($path) < $batas->getTimestamp()
                && ! Berkas::where('path', $path)->exists();

            if (! $aman) {
                $dilewati[] = $path;

                continue;
            }

            if ($disk->delete($path)) {
                $dihapus[] = $path;
            } else {
                $dilewati[] = $path;
            }
        }

        DB::transaction(fn () => LogAktivitas::catat(
            'bukti.lokal_yatim_dibersihkan',
            'Pembersihan berkas bukti lokal tanpa rujukan database: '.count($dihapus).' dihapus, '.count($dilewati).' dilewati. Database dan Google Drive tidak disentuh.',
            ['objek_tipe' => LogAktivitas::OBJEK_BERKAS, 'perubahan' => ['sebelum' => ['berkas_lokal' => array_slice($dihapus, 0, 100)], 'sesudah' => ['jumlah_dihapus' => count($dihapus), 'jumlah_dilewati' => count($dilewati)]]],
        ));

        $this->info(count($dihapus).' berkas dihapus, '.count($dilewati).' dilewati (rujukan/umur berubah atau gagal dihapus).');

        return self::SUCCESS;
    }
}
