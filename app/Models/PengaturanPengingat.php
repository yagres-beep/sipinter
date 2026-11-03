<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Waktu pengingat terjadwal (satu baris, id=1) yang bisa diubah Tim SAKIP lewat
 * halaman Pengingat — dipakai routes/console.php (jam_kirim, jadwal harian
 * pengingat:deadline-iku/iku-lengkap/google-reconnect) dan
 * PengingatDeadlineIkuCommand (deadline_h_minus, mulai berapa hari sebelum tenggat
 * pengajuan IKU diingatkan) supaya tidak dikodekan langsung.
 */
class PengaturanPengingat extends Model
{
    use HasFactory;

    protected $table = 'pengaturan_pengingat';

    protected $fillable = [
        'jam_kirim',
        'deadline_h_minus',
        'wa_pengingat_aktif',
        'wa_gateway_url',
        'wa_gateway_token',
    ];

    protected function casts(): array
    {
        return [
            'wa_pengingat_aktif' => 'boolean',
            'wa_gateway_token' => 'encrypted',
        ];
    }

    /**
     * Baris pengaturan tunggal, di-cache permanen (nilainya jarang berubah) — mirip
     * PengaturanCapaian::ambil(). Dibuat otomatis dengan nilai default bila baris
     * id=1 belum ada.
     */
    public static function ambil(): self
    {
        return Cache::rememberForever(
            'pengaturan-pengingat',
            fn () => static::firstOrCreate(['id' => 1], [
                'jam_kirim' => '08:00:00',
                'deadline_h_minus' => 3,
            ])
        );
    }

    public static function ambilAman(): ?self
    {
        try {
            return static::ambil();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function waPengingatAktif(): bool
    {
        $pengaturan = static::ambilAman();
        if ($pengaturan && $pengaturan->wa_pengingat_aktif !== null) {
            return (bool) $pengaturan->wa_pengingat_aktif;
        }

        return (bool) config('services.whatsapp.pengingat_aktif', false);
    }

    public static function lupakanCache(): void
    {
        Cache::forget('pengaturan-pengingat');
    }

    /** Format "HH:MM" (bukan "HH:MM:SS" dari kolom time), dipakai Schedule::dailyAt(). */
    public function jamKirimFormat(): string
    {
        return substr((string) $this->jam_kirim, 0, 5);
    }
}
