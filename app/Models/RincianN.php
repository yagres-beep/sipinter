<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu item rincian milik satu MasterIku pada satu tahun -- lihat migrasi
 * 2026_08_27_000014_buat_tabel_rincian_n,
 * 2026_10_21_000001_tambah_sumber_dan_triwulan_mulai_ke_rincian_n dan
 * 2026_10_23_000001_tambah_diubah_oleh_ke_rincian_n.
 *
 * ANGKA N TIDAK berasal dari tabel ini. Angka total N diisi eksplisit di Target
 * Tahunan dan disimpan di CapaianTahunan::y_alokasi_tw1..4 (lihat
 * CapaianTahunan::angkaN()); jumlah baris di sini boleh berbeda dari angka itu.
 *
 * Satu baris sumber 'target' adalah rincian N sekaligus rincian n yang mengikutinya
 * (identitasnya sama, tanpa tabel salinan), jadi perubahan uraian di Target Tahunan
 * langsung berlaku di daftar n tanpa proses salin dan tanpa duplikasi. Uraiannya
 * hanya bisa diubah dari Target Tahunan; di Verifikasi hanya status realisasinya.
 *
 * Empat jenis baris yang dibedakan:
 * - target aktif          : sumber 'target', belum dilepas (scopeTargetAktif()).
 * - target dilepas        : sumber 'target', dikeluarkan dari daftar target padahal
 *                           sudah terealisasi -- realisasinya tetap tercatat.
 * - tambahan              : sumber 'verifikasi', dibuat di halaman Verifikasi;
 *                           tidak pernah termasuk daftar target.
 * - terealisasi           : baris jenis apa pun yang triwulan_realisasi-nya terisi.
 *
 * Pembilang n per triwulan = baris unik yang terealisasi pada triwulan itu dan tidak
 * sebelum triwulan_mulai_berlaku-nya (jumlahRealisasiPerTw()).
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
        'diubah_oleh',
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

    /** Rincian target yang masih ada di daftar Target Tahunan (belum dilepas). */
    public function scopeTargetAktif(Builder $query): Builder
    {
        return $query->where('sumber', self::SUMBER_TARGET)->whereNull('dilepas_dari_target_at');
    }

    /**
     * Baris yang sah masuk pembilang n s.d. triwulan $tw: sudah terealisasi pada
     * atau sebelum $tw, dan tidak sebelum triwulan mulai berlakunya.
     */
    public function scopeTerhitungSampai(Builder $query, int $tw): Builder
    {
        return $query->whereNotNull('triwulan_realisasi')
            ->where('triwulan_realisasi', '<=', $tw)
            ->whereColumn('triwulan_realisasi', '>=', 'triwulan_mulai_berlaku');
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

    /** Sama seperti scopeTerhitungSampai() untuk satu baris yang sudah dimuat. */
    public function terhitungSampai(int $tw): bool
    {
        return $this->triwulan_realisasi !== null
            && $this->triwulan_realisasi <= $tw
            && $this->triwulan_realisasi >= $this->triwulan_mulai_berlaku;
    }

    /**
     * Jumlah rincian target aktif satu IKU pada satu tahun -- informasi daftar,
     * BUKAN angka N (lihat docblock kelas).
     */
    public static function jumlahRincianTarget(int $ikuId, int $tahun): int
    {
        return static::milik($ikuId, $tahun)->targetAktif()->count();
    }

    /**
     * Ringkasan daftar n satu IKU pada satu tahun, dilihat dari triwulan $tw --
     * tiap angka punya satu arti, tidak dipakai bergantian:
     * - terdaftar   : seluruh baris rincian n (target aktif + dilepas + tambahan)
     * - target      : rincian target aktif
     * - tambahan    : rincian tambahan dari Verifikasi
     * - dilepas     : rincian target yang dilepas tapi realisasinya tetap tercatat
     * - terealisasi : pembilang n kumulatif TW I s.d. $tw (baris unik yang sah)
     *
     * @return array{terdaftar: int, target: int, tambahan: int, dilepas: int, terealisasi: int}
     */
    public static function ringkasan(int $ikuId, int $tahun, int $tw): array
    {
        $baris = static::milik($ikuId, $tahun)->get();

        return [
            'terdaftar' => $baris->count(),
            'target' => $baris->filter(fn (self $n) => ! $n->isTambahan() && ! $n->isDilepas())->count(),
            'tambahan' => $baris->filter(fn (self $n) => $n->isTambahan())->count(),
            'dilepas' => $baris->filter(fn (self $n) => ! $n->isTambahan() && $n->isDilepas())->count(),
            'terealisasi' => $baris->filter(fn (self $n) => $n->terhitungSampai($tw))->count(),
        ];
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
