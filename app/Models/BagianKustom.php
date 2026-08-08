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

    protected $fillable = [
        'nama',
        'deskripsi',
        'wajib_akhir_triwulan',
        'aktif',
    ];

    protected function casts(): array
    {
        return [
            'wajib_akhir_triwulan' => 'boolean',
            'aktif' => 'boolean',
        ];
    }

    public function poin(): HasMany
    {
        return $this->hasMany(BagianKustomPoin::class);
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
            fn () => static::where('aktif', true)->orderBy('id')->get()
        );
    }

    public static function lupakanCache(): void
    {
        \Illuminate\Support\Facades\Cache::forget('bagian-kustom.aktif');
    }
}
