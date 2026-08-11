<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Definisi bagian tambahan yang bisa dibuat Tim SAKIP tanpa mengubah kode (mis.
 * "Manajemen Risiko") — begitu aktif, muncul sebagai bagian baru di Isian Kegiatan
 * (Ketua Tim), diisi per POIN (lihat BagianKustomPoin) dengan bukti dukung wajib per
 * poin, lalu otomatis ikut masuk ke Bagian I notula seperti Kendala&Solusi/RTL.
 */
class BagianKustom extends Model
{
    use HasFactory;

    protected $table = 'bagian_kustom';

    public const FREKUENSI_OPSIONAL = 'opsional';

    public const FREKUENSI_SETIAP_BULAN = 'setiap_bulan';

    public const FREKUENSI_AKHIR_TRIWULAN = 'akhir_triwulan';

    protected $fillable = [
        'nama',
        'deskripsi',
        'frekuensi_wajib',
        'bukti_wajib',
        'aktif',
        'urutan',
    ];

    protected function casts(): array
    {
        return [
            'bukti_wajib' => 'boolean',
            'aktif' => 'boolean',
        ];
    }

    public function poin(): HasMany
    {
        return $this->hasMany(BagianKustomPoin::class);
    }

    /**
     * Minimal satu poin bagian ini wajib diisi pada bulan-ke ($bulanKe, 1-3 dalam
     * triwulan) yang sedang diajukan — 'setiap_bulan' selalu wajib, 'akhir_triwulan'
     * hanya wajib di bulan terakhir (sama seperti pola RTL baru), 'opsional' tidak
     * pernah wajib.
     */
    public function wajibDiisiPada(int $bulanKe): bool
    {
        return match ($this->frekuensi_wajib) {
            self::FREKUENSI_SETIAP_BULAN => true,
            self::FREKUENSI_AKHIR_TRIWULAN => $bulanKe === 3,
            default => false,
        };
    }

    public function labelFrekuensi(): string
    {
        return match ($this->frekuensi_wajib) {
            self::FREKUENSI_SETIAP_BULAN => 'Wajib setiap bulan',
            self::FREKUENSI_AKHIR_TRIWULAN => 'Wajib akhir triwulan',
            default => 'Selalu opsional',
        };
    }

    /**
     * Daftar bagian kustom AKTIF, di-cache 1 jam — dipakai untuk menentukan bagian apa
     * saja yang perlu dirender di Isian Kegiatan pada tiap render(), mirip
     * MasterIku::daftarUrutKode(). Dilupakan lewat lupakanCache() tiap kali Tim SAKIP
     * menyimpan perubahan lewat BagianKustomManager.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function daftarAktif()
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'bagian-kustom.aktif',
            3600,
            fn () => static::where('aktif', true)->orderBy('urutan')->orderBy('id')->get()
        );
    }

    public static function lupakanCache(): void
    {
        \Illuminate\Support\Facades\Cache::forget('bagian-kustom.aktif');
    }
}
