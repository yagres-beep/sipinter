<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Periode extends Model
{
    use HasFactory;

    protected $table = 'periode';

    protected $fillable = [
        'tahun',
        'triwulan',
        'bulan',
        'bulan_ke',
        'flag_bulan_terlewat',
    ];

    protected function casts(): array
    {
        return [
            'flag_bulan_terlewat' => 'boolean',
        ];
    }

    public function kegiatan(): HasMany
    {
        return $this->hasMany(Kegiatan::class);
    }

    public function kendalaSolusi(): HasMany
    {
        return $this->hasMany(KendalaSolusi::class);
    }

    public function rtlEvaluasi(): HasMany
    {
        return $this->hasMany(RtlEvaluasi::class);
    }

    public function notula(): HasOne
    {
        return $this->hasOne(Notula::class);
    }
}
