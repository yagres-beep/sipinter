<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris = satu perubahan status pada satu Notula — siapa yang melakukannya
 * (user_id), status hasil perubahannya, catatan (mis. alasan pengembalian) bila
 * ada, dan kapan (created_at). Ditampilkan sebagai "Riwayat Tindakan" di halaman
 * Persetujuan Notula.
 */
class RiwayatStatusNotula extends Model
{
    protected $table = 'riwayat_status_notula';

    public const UPDATED_AT = null;

    protected $fillable = [
        'notula_id',
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

    public function notula(): BelongsTo
    {
        return $this->belongsTo(Notula::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
