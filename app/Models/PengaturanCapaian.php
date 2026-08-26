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
 *
 * Kolom `batas_normalisasi_pko` masih ada di tabel (data lama) tapi TIDAK dipakai
 * lagi di mana pun — rumus Penilaian Kinerja Organisasi (PKO) yang dulu memakainya
 * sudah dihapus dari cakupan aplikasi ini.
 */
class PengaturanCapaian extends Model
{
    use HasFactory;

    protected $table = 'pengaturan_capaian';

    protected $fillable = [
        'batas_maksimal_persen',
        'batas_normalisasi_pko',
        'tampilkan_nol_sebagai_strip',
    ];

    protected function casts(): array
    {
        return [
            'batas_maksimal_persen' => 'decimal:2',
            'batas_normalisasi_pko' => 'decimal:2',
            'tampilkan_nol_sebagai_strip' => 'boolean',
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
            fn () => static::firstOrCreate(['id' => 1], [
                'batas_maksimal_persen' => 120,
                'batas_normalisasi_pko' => 110,
                'tampilkan_nol_sebagai_strip' => false,
            ])
        );
    }

    public static function lupakanCache(): void
    {
        Cache::forget('pengaturan-capaian');
    }

    /**
     * Format nilai Target/Realisasi (tabel Rekap Kinerja Tahunan/LAKIN & Excel-nya)
     * sesuai pilihan tampilkan_nol_sebagai_strip — null SELALU "-" (belum ada data
     * sama sekali), sedangkan 0 mengikuti pengaturan (default: ditulis "0" apa
     * adanya, sama seperti perilaku sebelum pengaturan ini ada).
     */
    public static function formatAngka(mixed $nilai): string
    {
        if ($nilai === null || $nilai === '') {
            return '-';
        }

        if ((float) $nilai === 0.0 && static::ambil()->tampilkan_nol_sebagai_strip) {
            return '-';
        }

        return (string) $nilai;
    }

    /**
     * Sama seperti formatAngka(), ditambah sufiks "%" — dipakai untuk kolom Capaian %.
     */
    public static function formatPersen(mixed $nilai): string
    {
        $formatted = static::formatAngka($nilai);

        return $formatted === '-' ? '-' : $formatted.'%';
    }
}
