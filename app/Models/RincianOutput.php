<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris Rincian Output (RO) milik satu Kegiatan (RF baru) — SATU Kegiatan boleh
 * punya BANYAK RO (mis. satu kegiatan survei menghasilkan beberapa publikasi berbeda),
 * beda dari desain lama yang menaruh satu RO langsung sebagai kolom di tabel kegiatan
 * (lihat migrasi 2026_08_27_000010_buat_tabel_rincian_output). Dipakai untuk mengisi
 * tabel "Realisasi Volume RO dan Progress Pelaksanaan Kegiatan" di notula — lihat
 * App\Services\NotulaService::kumpulkanDataBagianSatu() dan
 * resources/views/pdf/notula-bagian1-konten.blade.php.
 */
class RincianOutput extends Model
{
    protected $table = 'rincian_output';

    protected $fillable = [
        'kegiatan_id',
        'uraian',
        'volume_ro',
        'progres_persen',
    ];

    protected function casts(): array
    {
        return [
            'progres_persen' => 'decimal:2',
        ];
    }

    public function kegiatan(): BelongsTo
    {
        return $this->belongsTo(Kegiatan::class);
    }
}
