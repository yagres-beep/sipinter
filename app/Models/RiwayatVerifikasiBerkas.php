<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris = satu perubahan status Sesuai/Tidak Sesuai satu berkas oleh Tim SAKIP
 * (lihat Berkas::catatVerifikasi()). Kode & nama berkas ikut disalin supaya riwayat
 * tetap terbaca setelah berkasnya diganti atau dihapus.
 */
class RiwayatVerifikasiBerkas extends Model
{
    protected $table = 'riwayat_verifikasi_berkas';

    public const UPDATED_AT = null;

    protected $fillable = [
        'berkas_id',
        'berkas_kode',
        'nama_file',
        'status_lama',
        'status_baru',
        'catatan',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function berkas(): BelongsTo
    {
        return $this->belongsTo(Berkas::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
