<?php

namespace App\Models;

use App\Services\GoogleDriveService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Berkas extends Model
{
    use HasFactory;

    protected $table = 'berkas';

    protected $fillable = [
        'ref_id',
        'ref_type',
        'kategori',
        'nama_file',
        'path',
        // drive_file_id  = ID unik berkas ini di Google Drive.
        // storage_account_id = akun Gmail institusi TEMPAT berkas ini fisik tersimpan.
        // Keduanya WAJIB disimpan bersamaan (bukan cukup salah satu) — inilah RF-10b:
        // bila storage aktif berpindah ke akun lain di kemudian hari, berkas LAMA ini
        // tetap tahu harus dibuka dari akun asalnya (storage_account_id), bukan dari
        // akun yang sedang aktif sekarang. Tautan tidak pernah rusak akibat perpindahan akun.
        'drive_file_id',
        'storage_account_id',
        'status_verifikasi',
        'catatan',
    ];

    public function ref(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'ref_type', 'ref_id');
    }

    /**
     * Akun Gmail institusi tempat berkas ini fisik tersimpan di Drive (RF-10b).
     * Dicatat per berkas, BUKAN diambil dari StorageAccount::aktif() saat ini,
     * supaya berkas lama tetap tertaut ke akun asalnya walau storage aktif sudah berganti.
     */
    public function storageAccount(): BelongsTo
    {
        return $this->belongsTo(StorageAccount::class);
    }

    /**
     * Tautan Drive untuk membuka/melihat berkas ini (dipakai Tim SAKIP saat
     * verifikasi, lihat RF-40a). Cukup butuh drive_file_id — satu Service Account
     * yang sama bisa membaca berkas dari akun manapun yang pernah membagikan
     * folder kepadanya, jadi tidak masalah walau storage_account ini sudah
     * bukan storage aktif lagi.
     */
    public function driveLink(): ?string
    {
        if (! $this->drive_file_id) {
            return null;
        }

        return app(GoogleDriveService::class)->getFileLink($this->drive_file_id);
    }
}
