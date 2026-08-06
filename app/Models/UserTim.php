<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keanggotaan tim satu Ketua Tim — satu pengguna bisa merangkap lebih dari satu
 * tim (mis. Rina Marlina merangkap Statistik Produksi & Statistik Distribusi).
 */
class UserTim extends Model
{
    use HasFactory;

    protected $table = 'user_tim';

    protected $fillable = ['user_id', 'tim'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
