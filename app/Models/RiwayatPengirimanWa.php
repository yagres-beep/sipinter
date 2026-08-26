<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Catatan tiap percobaan kirim WA (tes maupun pengingat sungguhan) — dibuat oleh
 * App\Services\WhatsAppService::catat(), ditampilkan sebagai kartu "Riwayat
 * Pengiriman" di halaman Pengingat WA. Baris di sini tidak pernah diubah setelah
 * dibuat, jadi tidak ada kolom updated_at.
 */
class RiwayatPengirimanWa extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $table = 'riwayat_pengiriman_wa';

    protected $fillable = [
        'nomor_telepon',
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
