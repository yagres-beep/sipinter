<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris tabel LAKIN (sasaran, indikator, target, realisasi, capaian %) — biasanya
 * mengikuti satu Master IKU (auto-terisi saat dibentuk, lihat LakinBuilderService),
 * tapi iku_id boleh kosong untuk baris custom yang ditambah Tim SAKIP secara manual.
 */
class LakinBaris extends Model
{
    use HasFactory;

    protected $table = 'lakin_baris';

    protected $fillable = [
        'lakin_id',
        'iku_id',
        'sasaran',
        'indikator',
        'target',
        'realisasi',
        'capaian_persen',
        'urutan',
    ];

    protected function casts(): array
    {
        return [
            'target' => 'decimal:2',
            'realisasi' => 'decimal:2',
            'capaian_persen' => 'decimal:2',
        ];
    }

    public function lakin(): BelongsTo
    {
        return $this->belongsTo(Lakin::class);
    }

    public function masterIku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }
}
