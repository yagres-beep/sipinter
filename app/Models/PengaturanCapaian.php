<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Parameter rumus capaian kinerja triwulanan (satu baris, id=1) yang bisa diubah Tim
 * SAKIP lewat halaman Pengaturan Rumus Capaian — dipakai Capaian::hitungPersentase()
 * supaya batas capaian (saat ini 120%, sesuai Kertas Kerja Pengukuran Kinerja
 * Triwulanan) tidak dikodekan langsung dan bisa menyesuaikan bila rumus resmi berubah.
 */
class PengaturanCapaian extends Model
{
    use HasFactory;

    protected $table = 'pengaturan_capaian';

    protected $fillable = [
        'batas_maksimal_persen',
    ];

    protected function casts(): array
    {
        return [
            'batas_maksimal_persen' => 'decimal:2',
        ];
    }

    /**
     * Baris pengaturan tunggal, di-cache permanen (nilainya jarang berubah) — mirip
     * Role::semuaNama(). Dibuat otomatis dengan nilai default bila baris id=1 belum
     * ada (mis. sebelum migrasi seed sempat jalan).
     */
    public static function ambil(): self
    {
        return Cache::rememberForever(
            'pengaturan-capaian',
            fn () => static::firstOrCreate(['id' => 1], ['batas_maksimal_persen' => 120])
        );
    }

    public static function lupakanCache(): void
    {
        Cache::forget('pengaturan-capaian');
    }
}
