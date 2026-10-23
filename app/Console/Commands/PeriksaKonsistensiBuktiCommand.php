<?php

namespace App\Console\Commands;

use App\Services\GoogleDriveService;
use App\Support\KonsistensiBukti;
use Illuminate\Console\Command;

/**
 * Laporan konsistensi bukti dukung (database ↔ disk lokal ↔ Google Drive).
 * BACA-SAJA: tidak mengubah database, tidak menghapus/memindah berkas lokal, dan
 * hanya memanggil Drive API untuk membaca. Tindak lanjut dilakukan manual atau lewat
 * `berkas:sinkron-drive` (menyusulkan unggahan) dan `bukti:bersihkan-lokal-yatim`.
 */
class PeriksaKonsistensiBuktiCommand extends Command
{
    protected $signature = 'bukti:periksa-konsistensi
        {--drive : periksa juga salinan di Google Drive (memanggil Drive API, baca saja)}
        {--batas-drive=200 : jumlah berkas maksimal yang dicek ke Drive}';

    protected $description = 'Laporan baca-saja konsistensi bukti dukung antara database, disk lokal, dan Google Drive';

    public function handle(KonsistensiBukti $periksa): int
    {
        $this->line('Pemeriksaan BACA-SAJA — tidak ada data atau berkas yang diubah.');
        $temuan = 0;

        $temuan += $this->bagian('Berkas TANPA salinan (Drive kosong & berkas lokal hilang) — perlu diunggah ulang pengguna', $periksa->tanpaSalinan());
        $this->bagian('Berkas menunggu disusulkan ke Drive (akan dicoba berkas:sinkron-drive)', $periksa->menungguSinkron(), hitungTemuan: false);
        $temuan += $this->bagian('Berkas yang pemiliknya (kegiatan/poin/RTL) sudah tidak ada', $periksa->rujukanHilang());

        $yatim = array_map(fn ($f) => [
            'path' => $f['path'],
            'ukuran' => number_format($f['ukuran'] / 1024, 1, ',', '.').' KB',
            'diubah' => $f['diubah']->wita()->format('Y-m-d H:i'),
        ], $periksa->lokalYatim());
        $temuan += $this->bagian('Berkas lokal tanpa baris database (kandidat bukti:bersihkan-lokal-yatim)', $yatim);

        if ($this->option('drive')) {
            $drive = app(GoogleDriveService::class);
            $batas = max(1, (int) $this->option('batas-drive'));

            try {
                $temuan += $this->bagian("Salinan Drive tidak ditemukan / di tempat sampah ({$batas} berkas terbaru)", $periksa->salinanDriveHilang($drive, $batas));
                $temuan += $this->bagian('Berkas Drive bertanda SIPINTER tanpa padanan database', $periksa->driveYatim($drive, $batas));
            } catch (\Throwable $e) {
                $this->error('Pemeriksaan Drive tidak bisa diselesaikan: '.$e->getMessage());

                return self::FAILURE;
            }
        } else {
            $this->line('Pemeriksaan Google Drive dilewati (pakai --drive untuk menyertakan).');
        }

        $this->newLine();
        $this->info($temuan === 0 ? 'Selesai: tidak ada temuan.' : "Selesai: {$temuan} temuan perlu ditinjau.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $baris
     */
    protected function bagian(string $judul, array $baris, bool $hitungTemuan = true): int
    {
        $this->newLine();
        $this->line("== {$judul}: ".count($baris));

        if ($baris !== []) {
            $this->table(array_keys($baris[0]), array_slice($baris, 0, 100));

            if (count($baris) > 100) {
                $this->line('… dan '.(count($baris) - 100).' lainnya.');
            }
        }

        return $hitungTemuan ? count($baris) : 0;
    }
}
