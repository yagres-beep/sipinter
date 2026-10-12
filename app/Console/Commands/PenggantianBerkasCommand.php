<?php

namespace App\Console\Commands;

use App\Support\PemulihPenggantianBerkas;
use Illuminate\Console\Command;

/**
 * Pemeriksaan & pemulihan operasi penggantian berkas notula (PDF draf/final/Bagian II-III,
 * lihat App\Support\PenggantiBerkasAman) yang tidak tuntas karena proses PHP berhenti,
 * server restart, atau crash.
 *
 * Tanpa --pulihkan: READ-ONLY, hanya melaporkan. Dengan --pulihkan: memulihkan operasi
 * yang arahnya TERBUKTI dari jurnal + berkas + DB; operasi yang ambigu, masih berjalan,
 * atau bertentangan dilewati dan semua berkasnya dipertahankan. SENGAJA tidak dijadwalkan
 * otomatis -- jalankan manual setelah membaca hasil pemeriksaan.
 */
class PenggantianBerkasCommand extends Command
{
    protected $signature = 'notula:penggantian-berkas
        {--pulihkan : Pulihkan operasi yang arahnya terbukti (tanpa opsi ini: hanya memeriksa)}
        {--id=* : Batasi ke ID operasi tertentu}
        {--force : Lewati konfirmasi saat --pulihkan di production}';

    protected $description = 'Periksa (read-only) atau pulihkan operasi penggantian berkas notula yang tidak tuntas';

    public function handle(PemulihPenggantianBerkas $pemulih): int
    {
        $hanyaId = array_values(array_filter((array) $this->option('id')));
        $laporan = array_values(array_filter(
            $pemulih->periksa(),
            fn ($a) => $hanyaId === [] || in_array($a['id'], $hanyaId, true)
        ));

        $yatim = $pemulih->berkasTanpaJurnal([storage_path('app/private/notula')]);

        if ($laporan === [] && $yatim === []) {
            $this->info('Tidak ada operasi penggantian berkas yang tertunda.');

            return self::SUCCESS;
        }

        if ($laporan !== []) {
            $this->table(
                ['ID', 'Operasi', 'Berkas', 'Tahap', 'DB', 'Keputusan', 'Alasan'],
                array_map(fn ($a) => [$a['id'], $a['label'] ?? '-', $a['tujuan'] ? basename($a['tujuan']) : '-', $a['tahap'] ?? '-', $a['db'], $a['keputusan'], $a['alasan']], $laporan)
            );
        }

        if ($yatim !== []) {
            $this->warn('Berkas cadangan/sementara TANPA jurnal (tidak akan disentuh; periksa manual):');
            foreach ($yatim as $path) {
                $this->line('  - '.$path);
            }
        }

        if (! $this->option('pulihkan')) {
            $this->line('Mode periksa (read-only): tidak ada berkas yang diubah. Tambahkan --pulihkan untuk memulihkan.');

            return $this->adaYangPerluTindakan($laporan, $yatim) ? self::FAILURE : self::SUCCESS;
        }

        if ($this->laravel->environment('production') && ! $this->option('force')
            && ! $this->confirm('Pulihkan operasi di atas pada server PRODUCTION?', false)) {
            $this->line('Dibatalkan.');

            return self::FAILURE;
        }

        $hasil = $pemulih->pulihkan($hanyaId);
        $this->table(
            ['ID', 'Keputusan', 'Berhasil', 'Keterangan'],
            array_map(fn ($h) => [$h['id'], $h['keputusan'], $h['berhasil'] ? 'ya' : 'TIDAK', $h['pesan']], $hasil)
        );

        $gagal = array_filter($hasil, fn ($h) => ! $h['berhasil']);

        return $gagal === [] && $yatim === [] ? self::SUCCESS : self::FAILURE;
    }

    private function adaYangPerluTindakan(array $laporan, array $yatim): bool
    {
        return $laporan !== [] || $yatim !== [];
    }
}
