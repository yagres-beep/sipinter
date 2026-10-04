<?php

namespace App\Services;

use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Backup database (menu Kelola Pengguna → Backup Database). Dump dibuat lewat
 * pg_dump (bukan paket Composer -- lihat config/services.php untuk cara pasang)
 * karena database SIPINTER memakai Postgres terkelola di Supabase, bukan MySQL.
 *
 * Alurnya sama seperti Berkas/Template Notula: dump disimpan dulu di disk lokal
 * (storage/app/private/backups), lalu diarsipkan ke Google Drive lewat
 * FolderStructureService::unggahBackupDatabase() -- disk lokal server TIDAK
 * persisten di Render free plan (terhapus tiap deploy ulang), jadi tanpa salinan
 * Drive, riwayat backup bisa hilang begitu aplikasi di-deploy ulang.
 *
 * Kegagalan mengunggah ke Drive TIDAK membatalkan backup -- dump lokal yang
 * sudah jadi tetap dicatat & bisa diunduh selama disk lokal belum di-deploy
 * ulang, hanya dicatat sebagai peringatan di kolom `catatan` (pola sama seperti
 * TemplateNotula::unggah()).
 */
class BackupService
{
    public function __construct(protected FolderStructureService $folderService) {}

    public function buat(?User $user): Backup
    {
        $namaFile = 'backup_'.now()->format('Y-m-d_His').'.sql';
        $path = 'backups/'.$namaFile;

        Storage::disk('local')->makeDirectory('backups');
        $localPath = Storage::disk('local')->path($path);

        $hasilDump = $this->jalankanPgDump($localPath);

        if (! $hasilDump['berhasil']) {
            if (is_file($localPath)) {
                @unlink($localPath);
            }

            return Backup::create([
                'nama_file' => $namaFile,
                'status' => Backup::STATUS_GAGAL,
                'catatan' => $hasilDump['pesan'],
                'dibuat_oleh' => $user?->id,
            ]);
        }

        $isian = [
            'nama_file' => $namaFile,
            'path' => $path,
            'ukuran_bytes' => filesize($localPath) ?: null,
            'status' => Backup::STATUS_BERHASIL,
            'dibuat_oleh' => $user?->id,
        ];

        try {
            $hasilUnggah = $this->folderService->unggahBackupDatabase($localPath, $namaFile);
            $isian['drive_file_id'] = $hasilUnggah['drive_file_id'];
            $isian['storage_account_id'] = $hasilUnggah['storage_account_id'];
        } catch (\Throwable $e) {
            Log::warning("Gagal mengarsipkan backup database ke Drive: {$e->getMessage()}");
            $isian['catatan'] = 'Backup lokal berhasil, tapi gagal mengunggah ke Google Drive: '.$e->getMessage();
        }

        return Backup::create($isian);
    }

    /**
     * @return array{berhasil: bool, pesan: ?string}
     */
    protected function jalankanPgDump(string $localPath): array
    {
        $binary = config('services.postgres.pg_dump_path');
        $koneksi = config('database.connections.pgsql');

        $process = new Process([
            $binary,
            '--no-owner',
            '--no-privileges',
            '--format=plain',
            // Database SIPINTER memakai Supabase -- SETIAP project Supabase punya
            // banyak schema infrastruktur bawaan (auth, storage, realtime, extensions,
            // vault, dst.) yang TIDAK terkait data aplikasi ini sama sekali (aplikasi
            // hanya memakai schema 'public', lihat search_path di config/database.php).
            // Tanpa --schema, pg_dump ikut mengatalogkan semua schema bawaan itu --
            // jauh lebih lambat dan rentan putus di koneksi pooler yang berlatensi
            // tinggi, padahal tidak pernah dipakai Eloquent sama sekali.
            '--schema=public',
            '-h', (string) $koneksi['host'],
            '-p', (string) $koneksi['port'],
            '-U', (string) $koneksi['username'],
            '-d', (string) $koneksi['database'],
            '-f', $localPath,
        ]);

        // PGPASSWORD lewat environment variable, BUKAN argumen command line --
        // argumen proses bisa terlihat pemakai lain lewat daftar proses (ps/tasklist).
        $process->setEnv(['PGPASSWORD' => (string) $koneksi['password']]);

        // Koneksi lewat connection pooler Supabase (lihat DB_HOST di .env) berlatensi
        // tinggi untuk pg_dump (banyak query katalog kecil berurutan) -- diberi waktu
        // SANGAT longgar (maksimum yang wajar untuk proses latar, bukan request HTTP
        // biasa) supaya dump yang sah tidak terpotong di tengah jalan.
        $process->setTimeout(600);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return ['berhasil' => false, 'pesan' => "pg_dump tidak bisa dijalankan: {$e->getMessage()}"];
        }

        if (! $process->isSuccessful()) {
            return ['berhasil' => false, 'pesan' => trim($process->getErrorOutput()) ?: 'pg_dump gagal tanpa pesan error.'];
        }

        if (! is_file($localPath) || filesize($localPath) === 0) {
            return ['berhasil' => false, 'pesan' => 'pg_dump berhasil dijalankan tapi tidak menghasilkan berkas dump.'];
        }

        return ['berhasil' => true, 'pesan' => null];
    }
}
