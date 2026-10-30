<?php

namespace App\Models;

use App\Support\MenuBagianKustom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Satu POIN isian untuk satu bagian kustom (mis. Manajemen Risiko), pada satu IKU
 * dan satu periode — pola sama seperti KendalaSolusi/RtlEvaluasi. Bukti dukung WAJIB
 * per poin (divalidasi di PengisianKegiatan, bukan lewat DB constraint di sini).
 */
class BagianKustomPoin extends Model
{
    use HasFactory;

    protected $table = 'bagian_kustom_poin';

    protected $fillable = [
        'bagian_kustom_id',
        'iku_id',
        'periode_id',
        'lanjutan_dari_id',
        'akar_id',
        'menu',
        'peran_pengisi',
        'diisi_oleh',
        'diubah_oleh',
        'teks',
        'nilai_kolom',
        'status_verifikasi',
        'catatan',
        'catatan_bukti_dihapus',
        'diajukan_pada',
        'diajukan_oleh',
        'diperiksa_pada',
        'diperiksa_oleh',
        'tahap_periksa',
        'versi_konfigurasi',
    ];

    protected function casts(): array
    {
        return [
            'nilai_kolom' => 'array',
            'diajukan_pada' => 'datetime',
            'diperiksa_pada' => 'datetime',
            'tahap_periksa' => 'integer',
            'versi_konfigurasi' => 'integer',
        ];
    }

    /**
     * Setiap kali ISI poin (IKU, uraian, nilai kolom) dibuat/diubah — dari menu mana pun —
     * catat versi definisi bagian yang dipakai formulirnya. Perubahan status saja
     * (ajukan/setujui) tidak mengubah versi ini.
     */
    protected static function booted(): void
    {
        static::saving(function (self $poin) {
            if (! $poin->exists || $poin->isDirty(['teks', 'nilai_kolom', 'iku_id'])) {
                $versi = $poin->relationLoaded('bagianKustom')
                    ? $poin->bagianKustom?->versi_konfigurasi
                    : BagianKustom::whereKey($poin->bagian_kustom_id)->value('versi_konfigurasi');

                $poin->versi_konfigurasi = max(1, (int) ($versi ?? 1));
            }
        });
    }

    /**
     * Poin isian Ketua Tim di menu Isian Kegiatan. Poin lama tanpa konteks (menu
     * NULL) ikut di sini: sebelum fitur peran & penempatan menu, hanya jalur Isian
     * Kegiatan yang bisa membuat poin.
     */
    public function scopeKonteksIsianKegiatan(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('menu', MenuBagianKustom::PENGISIAN)
            ->orWhereNull('menu'));
    }

    /**
     * Poin milik satu konteks pengisian: menu + peran. Pengguna dengan peran yang
     * sama di menu yang sama berbagi data yang sama (data tim, bukan data pribadi).
     */
    public function scopeKonteks(Builder $query, string $menu, string $peran): Builder
    {
        if ($menu === MenuBagianKustom::PENGISIAN) {
            return $query->konteksIsianKegiatan();
        }

        return $query->where('menu', $menu)->where('peran_pengisi', $peran);
    }

    /**
     * Sidik jari isi poin saat dimuat ke layar — dipakai untuk mendeteksi perubahan
     * oleh pengguna lain sebelum menimpa atau menghapus poin ini.
     */
    public function sidikJari(): string
    {
        return sha1(json_encode([
            (int) $this->iku_id,
            (string) $this->teks,
            $this->nilai_kolom ?? [],
            (string) $this->status_verifikasi,
            optional($this->updated_at)->format('Y-m-d H:i:s.u'),
        ]));
    }

    /**
     * Identitas objek yang tetap antarperiode: id poin pertama rangkaian lanjutannya.
     * Poin yang bukan lanjutan (termasuk semua poin lama) adalah akarnya sendiri.
     */
    public function identitasObjek(): int
    {
        return (int) ($this->akar_id ?? $this->id);
    }

    /** Poin periode sebelumnya yang dilanjutkan oleh poin ini. */
    public function lanjutanDari(): BelongsTo
    {
        return $this->belongsTo(self::class, 'lanjutan_dari_id');
    }

    /** Poin periode berikutnya yang melanjutkan poin ini. */
    public function lanjutan(): HasMany
    {
        return $this->hasMany(self::class, 'lanjutan_dari_id');
    }

    public function bagianKustom(): BelongsTo
    {
        return $this->belongsTo(BagianKustom::class);
    }

    public function masterIku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class, 'periode_id');
    }

    /**
     * Bukti dukung poin ini, disimpan di Drive dalam folder bernama sesuai
     * BagianKustom::nama (lihat FolderStructureService::unggahBerkas() param
     * $namaFolderOverride) — kategori DB tetap 'bagian_kustom' untuk semua bagian.
     */
    public function berkas(): MorphMany
    {
        return $this->morphMany(Berkas::class, 'ref', 'ref_type', 'ref_id');
    }
}
