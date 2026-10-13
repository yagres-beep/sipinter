<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris Indikator Proksi milik satu IKU pada satu triwulan — ditambahkan Tim SAKIP
 * sendiri di Detail Verifikasi (App\Livewire\VerifikasiCapaian) bila IKU tsb memang
 * diukur lewat proksi. Mengisi tabel "Indikator Proksi | Target s.d Triwulan |
 * Realisasi s.d Triwulan" ({{blok_proksi}}) di notula, lihat
 * App\Services\NotulaBagian1DocxService. Tidak terikat jenis indikator tertentu.
 */
class IndikatorProksi extends Model
{
    protected $table = 'indikator_proksi';

    protected $fillable = [
        'iku_id',
        'tahun',
        'triwulan',
        'uraian',
        'target',
        'realisasi',
    ];

    public function masterIku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }
}
