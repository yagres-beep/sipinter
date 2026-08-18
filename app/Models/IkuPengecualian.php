<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengecualian penanggung jawab OTOMATIS (via tim) untuk satu IKU — lihat
 * MasterIku::penanggungJawabOtomatis(). Mengecualikan seseorang di sini tidak
 * mengeluarkannya dari keanggotaan tim (UserTim), hanya menyembunyikannya dari
 * daftar penanggung jawab IKU ini saja.
 */
class IkuPengecualian extends Model
{
    use HasFactory;

    protected $table = 'iku_pengecualian';

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
