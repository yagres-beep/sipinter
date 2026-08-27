<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu item pembentuk Penyebut (Y)/Pembilang (X) milik satu MasterIku pada satu
 * tahun -- lihat migrasi 2026_08_27_000014_buat_tabel_rincian_n untuk aturan
 * lengkapnya. Dipakai App\Livewire\TargetTahunan (kelola daftar N + hitung Alokasi
 * Y) dan App\Livewire\VerifikasiCapaian (pilih item yang direalisasikan per
 * triwulan, menggantikan input manual Realisasi X).
 */
class RincianN extends Model
{
    protected $table = 'rincian_n';

    protected $fillable = [
        'iku_id',
        'tahun',
        'uraian',
        'triwulan_realisasi',
    ];

    public function masterIku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }
}
