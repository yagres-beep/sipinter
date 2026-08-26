<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Nilai SAKIP dari Inspektorat — satu angka per TAHUN untuk SELURUH organisasi
 * (bukan per-IKU), diisi Tim SAKIP di halaman Dasbor Capaian. Menentukan Predikat
 * SAKIP (Rumus 2.1, lihat Capaian::predikatSakip()) yang ditampilkan sebagai info
 * header saja — TIDAK dipakai dalam perhitungan capaian apa pun.
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
