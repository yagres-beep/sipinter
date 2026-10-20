<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Salinan fisik satu bukti capaian kegiatan di folder Evaluasi-RTL poin RTL yang
 * dilaksanakan kegiatan itu (lihat App\Services\SinkronBuktiRtlService). Hanya
 * mencatat letak salinan di Drive — status verifikasinya SELALU dibaca dari berkas
 * sumber (berkas()), jadi Kegiatan & Evaluasi RTL tidak pernah berbeda status.
 */
class SalinanBuktiRtl extends Model
{
    protected $table = 'salinan_bukti_rtl';

    public const STATUS_MENUNGGU = 'menunggu';

    public const STATUS_TERSALIN = 'tersalin';

    public const STATUS_GAGAL = 'gagal';

    protected $fillable = [
        'berkas_id',
        'rtl_evaluasi_id',
        'sumber_drive_file_id',
        'drive_file_id',
        'storage_account_id',
        'status',
        'percobaan',
        'galat',
        'tersalin_pada',
    ];

    protected function casts(): array
    {
        return [
            'tersalin_pada' => 'datetime',
        ];
    }

    public function berkas(): BelongsTo
    {
        return $this->belongsTo(Berkas::class);
    }

    public function rtlEvaluasi(): BelongsTo
    {
        return $this->belongsTo(RtlEvaluasi::class);
    }

    public function storageAccount(): BelongsTo
    {
        return $this->belongsTo(StorageAccount::class);
    }

    /**
     * Salinan masih mencerminkan berkas sumber yang sekarang (belum diganti).
     */
    public function mutakhir(?Berkas $sumber = null): bool
    {
        $sumber ??= $this->berkas;

        return $this->status === self::STATUS_TERSALIN
            && filled($this->drive_file_id)
            && $sumber !== null
            && $this->sumber_drive_file_id === $sumber->drive_file_id;
    }
}
