<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu item rincian milik satu MasterIku pada satu tahun -- lihat migrasi
 * 2026_08_27_000014_buat_tabel_rincian_n dan
 * 2026_10_21_000001_tambah_sumber_dan_triwulan_mulai_ke_rincian_n.
 *
 * Satu baris melayani N dan n sekaligus (tidak ada tabel salinan untuk n), jadi
 * rincian N yang ditambah/diubah di Target Tahunan otomatis ikut di daftar n
 * tanpa proses salin dan tanpa duplikasi.
 *
 * - N (penyebut)  = baris sumber 'target' yang belum dilepas, lihat scopePenyebut().
 * - n (pembilang) = baris SUMBER APA PUN yang sudah terealisasi, dihitung pada
 *                   triwulan_realisasi-nya, lihat jumlahRealisasiPerTw().
 *
 * Rincian tambahan (sumber 'verifikasi') tidak pernah masuk N dan tidak pernah
 * bisa direalisasikan sebelum triwulan_mulai_berlaku-nya.
 */
class RincianN extends Model
{
    public const SUMBER_TARGET = 'target';

    public const SUMBER_VERIFIKASI = 'verifikasi';

    protected $table = 'rincian_n';

    protected $fillable = [
        'iku_id',
        'tahun',
        'uraian',
        'sumber',
        'triwulan_mulai_berlaku',
        'triwulan_realisasi',
        'dilepas_dari_target_at',
        'dibuat_oleh',
    ];

    protected $attributes = [
        'sumber' => self::SUMBER_TARGET,
        'triwulan_mulai_berlaku' => 1,
    ];

    protected function casts(): array
    {
        return [
            'triwulan_mulai_berlaku' => 'integer',
            'triwulan_realisasi' => 'integer',
            'dilepas_dari_target_at' => 'datetime',
        ];
    }

    public function masterIku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }

    public function scopeMilik(Builder $query, int $ikuId, int $tahun): Builder
    {
        return $query->where('iku_id', $ikuId)->where('tahun', $tahun);
    }

    /** Baris yang dihitung sebagai N: rincian target yang belum dilepas. */
    public function scopePenyebut(Builder $query): Builder
    {
        return $query->where('sumber', self::SUMBER_TARGET)->whereNull('dilepas_dari_target_at');
    }

    public function isTambahan(): bool
    {
        return $this->sumber === self::SUMBER_VERIFIKASI;
    }

    public function isDilepas(): bool
    {
        return $this->dilepas_dari_target_at !== null;
    }

    public function isTerealisasi(): bool
    {
        return $this->triwulan_realisasi !== null;
    }

    /** Angka N satu IKU pada satu tahun. */
    public static function jumlahPenyebut(int $ikuId, int $tahun): int
    {
        return static::milik($ikuId, $tahun)->penyebut()->count();
    }

    /**
     * Jumlah rincian yang terealisasi PADA tiap triwulan (bukan kumulatif) --
     * dasar x_realisasi_tw1..4. Satu baris hanya punya satu triwulan_realisasi,
     * jadi paling banyak dihitung sekali. Baris yang triwulan_realisasi-nya
     * lebih awal dari triwulan_mulai_berlaku tidak pernah dihitung.
     *
     * @return array<int, int> [1 => .., 2 => .., 3 => .., 4 => ..]
     */
    public static function jumlahRealisasiPerTw(int $ikuId, int $tahun): array
    {
        $hitung = array_fill(1, 4, 0);

        static::milik($ikuId, $tahun)
            ->whereNotNull('triwulan_realisasi')
            ->whereColumn('triwulan_realisasi', '>=', 'triwulan_mulai_berlaku')
            ->pluck('triwulan_realisasi')
            ->each(function ($tw) use (&$hitung) {
                if (isset($hitung[(int) $tw])) {
                    $hitung[(int) $tw]++;
                }
            });

        return $hitung;
    }
}
