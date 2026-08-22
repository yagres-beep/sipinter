<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris = satu perubahan status pada satu Capaian (IKU+periode) — siapa yang
 * melakukannya (user_id), status hasil perubahannya, dan kapan (created_at). Dicatat
 * di granularitas Capaian, bukan Kegiatan, lihat catatan migrasi pembuat tabel ini.
 */
class RiwayatStatusCapaian extends Model
{
    protected $table = 'riwayat_status_capaian';

    public const UPDATED_AT = null;

    protected $fillable = [
        'capaian_id',
        'status',
        'user_id',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function capaian(): BelongsTo
    {
        return $this->belongsTo(Capaian::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
