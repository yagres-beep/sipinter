<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penugasan IKU MANUAL — penambahan/override penanggung jawab di luar penugasan
 * otomatis via keanggotaan tim (lihat MasterIku::penanggungJawabOtomatis()).
 */
class IkuPenugasan extends Model
{
    use HasFactory;

    protected $table = 'iku_penugasan';

    protected $fillable = ['iku_id', 'user_id'];

    public function masterIku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
