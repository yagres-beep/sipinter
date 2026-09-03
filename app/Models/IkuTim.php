<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tim penanggung jawab satu IKU -- satu IKU boleh punya lebih dari satu tim
 * (mis. IKU lintas fungsi yang ditangani bersama Statistik Produksi & Statistik
 * Distribusi). Pola sama persis dengan App\Models\UserTim (keanggotaan tim
 * pengguna), lihat App\Models\MasterIku::timList()/namaTimList().
 */
class IkuTim extends Model
{
    use HasFactory;

    protected $table = 'iku_tim';

    protected $fillable = ['iku_id', 'tim'];

    public function iku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }
}
