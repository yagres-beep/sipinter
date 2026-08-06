<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class KendalaSolusi extends Model
{
    use HasFactory;

    protected $table = 'kendala_solusi';

    protected $fillable = [
        'iku_id',
        'periode_id',
        'kendala',
        'solusi',
    ];

    public function masterIku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class, 'periode_id');
    }

    /**
     * Bukti dukung solusi (RF-27), disimpan di Drive kategori "Solusi".
     * Wajib ada bila kolom solusi diisi — divalidasi di KendalaSolusiForm, bukan di sini.
     */
    public function berkas(): MorphMany
    {
        return $this->morphMany(Berkas::class, 'ref', 'ref_type', 'ref_id');
    }
}
