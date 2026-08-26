<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Catatan tiap percobaan kirim email pengingat (tes maupun sungguhan) — dibuat
 * oleh App\Services\EmailPengingatService::catat(), ditampilkan sebagai kartu
 * "Riwayat Pengiriman Email". Baris di sini tidak pernah diubah setelah dibuat,
 * jadi tidak ada kolom updated_at.
 */
class RiwayatPengirimanEmail extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $table = 'riwayat_pengiriman_email';

    protected $fillable = [
        'email',
        'subjek',
        'pesan',
        'berhasil',
        'alasan_gagal',
    ];

    protected function casts(): array
    {
        return [
            'berhasil' => 'boolean',
        ];
    }
}
