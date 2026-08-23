<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KendalaSolusi extends Model
{
    use HasFactory;

    protected $table = 'kendala_solusi';

    protected $fillable = [
        'iku_id',
        'periode_id',
        'kendala',
        'solusi',
        'status_verifikasi',
        'catatan',
    ];

    public function masterIku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class, 'periode_id');
    }
}
