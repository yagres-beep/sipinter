<?php

namespace App\Models;

use App\Services\GoogleDriveService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Satu baris = satu kali proses backup database (lihat App\Services\BackupService).
 * Dump SQL-nya disimpan sementara di disk lokal (path) DAN diarsipkan ke Google
 * Drive (drive_file_id) -- sama seperti Berkas/Template Notula -- supaya tetap
 * bisa diunduh walau salinan lokalnya sudah hilang (disk lokal server TIDAK
 * persisten di Render free plan, lihat BerkasDownloadController).
 */
class Backup extends Model
{
    protected $table = 'backups';

    public const STATUS_BERHASIL = 'berhasil';

    public const STATUS_GAGAL = 'gagal';

    protected $fillable = [
        'nama_file',
        'path',
        'ukuran_bytes',
        'drive_file_id',
        'storage_account_id',
        'status',
        'catatan',
        'dibuat_oleh',
    ];

    protected function casts(): array
    {
        return [
            'ukuran_bytes' => 'integer',
        ];
    }

    public function storageAccount(): BelongsTo
    {
        return $this->belongsTo(StorageAccount::class);
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    /**
     * True bila salinan lokalnya masih ada -- dipakai untuk menentukan jalur unduh
     * tercepat (lokal) sebelum jatuh ke Drive (lihat BackupDatabase::unduh()).
     */
    public function adaSalinanLokal(): bool
    {
        return $this->path && Storage::disk('local')->exists($this->path);
    }

    public function ukuranHuman(): string
    {
        if (! $this->ukuran_bytes) {
            return '—';
        }

        $satuan = ['B', 'KB', 'MB', 'GB'];
        $nilai = (float) $this->ukuran_bytes;
        $i = 0;

        while ($nilai >= 1024 && $i < count($satuan) - 1) {
            $nilai /= 1024;
            $i++;
        }

        return round($nilai, 1).' '.$satuan[$i];
    }

    public function driveLink(): ?string
    {
        if (! $this->drive_file_id) {
            return null;
        }

        return app(GoogleDriveService::class)->getFileLink($this->drive_file_id);
    }
}
