<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Angka capaian IKU pada satu periode (RF-38) — satu baris per (iku_id, periode_id),
 * BUKAN per kegiatan, karena satu IKU boleh punya banyak kegiatan (RF-19) yang
 * berbagi satu set Target PK/Target TW/Realisasi/Capaian % yang sama, diisi Tim SAKIP
 * saat verifikasi. Bukti dukung capaian tetap melekat per Kegiatan (lihat Kegiatan::berkas()),
 * bukan di sini, karena RF-23 mewajibkan bukti capaian per kegiatan.
 */
class Capaian extends Model
{
    use HasFactory;

    protected $table = 'capaian';

    protected $fillable = [
        'iku_id',
        'periode_id',
        'analisis_capaian',
        'target_pk',
        'target_tw',
        'realisasi',
        'persentase_capaian',
    ];

    protected function casts(): array
    {
        return [
            'target_pk' => 'decimal:2',
            'target_tw' => 'decimal:2',
            'realisasi' => 'decimal:2',
            'persentase_capaian' => 'decimal:2',
        ];
    }

    public function masterIku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class);
    }

    /**
     * Semua kegiatan pendukung IKU pada periode yang sama (RF-37) — kegiatan tidak
     * disimpan ulang teksnya di sini, cukup ditarik otomatis lewat iku_id+periode_id.
     */
    public function kegiatanList(): HasMany
    {
        return $this->hasMany(Kegiatan::class, 'iku_id', 'iku_id')
            ->where('periode_id', $this->periode_id);
    }
}
