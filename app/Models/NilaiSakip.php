<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Nilai SAKIP dari Inspektorat — satu angka per TAHUN untuk SELURUH organisasi
 * (bukan per-IKU), diisi Tim SAKIP di halaman Dasbor Capaian. Menentukan Predikat
 * SAKIP (lihat Capaian::predikatSakip()) yang dipakai rumus Penilaian Kinerja
 * Organisasi (PKO) — lihat App\Livewire\DasborCapaian::hitungPko().
 */
class NilaiSakip extends Model
{
    use HasFactory;

    protected $table = 'nilai_sakip';

    protected $fillable = [
        'tahun',
        'nilai',
    ];

    protected function casts(): array
    {
        return [
            'nilai' => 'decimal:2',
        ];
    }
}
