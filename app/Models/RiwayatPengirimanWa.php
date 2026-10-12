<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris = satu pesan WhatsApp ke satu penerima. Baris dibuat saat
 * pengingat diantrekan (status 'antre'), lalu diperbarui oleh
 * App\Services\WhatsAppPengingatService sesuai status yang dilaporkan gateway.
 * Baris berstatus 'arsip' berasal dari gateway lama dan tidak diproses lagi.
 */
class RiwayatPengirimanWa extends Model
{
    public const STATUS_ANTRE = 'antre';

    public const STATUS_DI_GATEWAY = 'di_gateway';

    public const STATUS_TERKIRIM = 'terkirim';

    public const STATUS_DITERIMA = 'diterima';

    public const STATUS_GAGAL = 'gagal';

    public const STATUS_KEDALUWARSA = 'kedaluwarsa';

    public const STATUS_TIDAK_PASTI = 'tidak_pasti';

    public const STATUS_ARSIP = 'arsip';

    public const LABEL_STATUS = [
        self::STATUS_ANTRE => 'Menunggu diserahkan ke gateway',
        self::STATUS_DI_GATEWAY => 'Dalam antrean gateway',
        self::STATUS_TERKIRIM => 'Diterima server WhatsApp',
        self::STATUS_DITERIMA => 'Sampai di HP penerima',
        self::STATUS_GAGAL => 'Gagal',
        self::STATUS_KEDALUWARSA => 'Kedaluwarsa',
        self::STATUS_TIDAK_PASTI => 'Tidak pasti',
        self::STATUS_ARSIP => 'Arsip gateway lama',
    ];

    protected $table = 'riwayat_pengiriman_wa';

    protected $fillable = [
        'user_id',
        'jenis',
        'idempotency_key',
        'nomor_telepon',
        'pesan',
        'berhasil',
        'status',
        'percobaan_kirim',
        'alasan_gagal',
        'diserahkan_ke_gateway_at',
        'terkirim_at',
        'diterima_at',
    ];

    protected function casts(): array
    {
        return [
            'berhasil' => 'boolean',
            'percobaan_kirim' => 'integer',
            'diserahkan_ke_gateway_at' => 'datetime',
            'terkirim_at' => 'datetime',
            'diterima_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function labelStatus(): string
    {
        if ($this->status === self::STATUS_ARSIP || $this->status === null) {
            return $this->berhasil ? 'Berhasil (gateway lama)' : 'Gagal (gateway lama)';
        }

        return self::LABEL_STATUS[$this->status] ?? $this->status;
    }
}
