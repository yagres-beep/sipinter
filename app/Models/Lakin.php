<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dokumen LAKIN (Laporan Kinerja) satu TAHUN — dibentuk Tim SAKIP dari data capaian
 * yang sudah terverifikasi (lihat LakinBuilderService), lalu bisa disesuaikan bebas
 * (LakinBaris) karena format LAKIN tiap instansi bisa berbeda. Ketua Tim & Kepala
 * hanya bisa melihat (read-only), tidak bisa membentuk/mengubah.
 */
class Lakin extends Model
{
    use HasFactory;

    protected $table = 'lakin';

    protected $fillable = [
        'tahun',
    ];

    public function baris(): HasMany
    {
        return $this->hasMany(LakinBaris::class)->orderBy('urutan');
    }
}
