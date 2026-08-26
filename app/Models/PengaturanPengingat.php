<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Waktu pengingat WA terjadwal (satu baris, id=1) yang bisa diubah Tim SAKIP lewat
 * halaman Pengingat WA — dipakai routes/console.php (jam_kirim, jadwal harian
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
    ];

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
