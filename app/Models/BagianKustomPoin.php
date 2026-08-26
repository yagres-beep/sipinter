<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'teks',
        'status_verifikasi',
        'catatan',
    ];

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
