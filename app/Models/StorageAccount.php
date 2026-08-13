<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Satu baris = satu akun Gmail institusi yang dipakai sebagai penyimpanan Drive (RF-10a).
 *
 * Kenapa bisa lebih dari satu akun? Karena akun Gmail gratis hanya punya kuota ±15 GB
 * (lihat SRS §5.3). Saat kuota "storage aktif" mendekati/mencapai penuh, Tim SAKIP
 * menyiapkan akun institusi baru dan menjadikannya "storage aktif" berikutnya —
 * TANPA memindahkan berkas lama (lihat RF-10b: setiap berkas mencatat storage_account_id
 * miliknya sendiri, jadi tetap bisa dibuka dari akun asalnya).
 */
class StorageAccount extends Model
{
    use HasFactory;

    protected $table = 'storage_account';

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_PENUH = 'penuh';

    /**
     * Ambang batas peringatan kuota (RF-10c). Di atas 90% terpakai, tampilkan
     * peringatan "siapkan akun berikutnya" ke Admin/Tim SAKIP.
     */
    public const AMBANG_PERINGATAN_PERSEN = 90;

    protected $fillable = [
        'email_gmail_institusi',
        'status',
        'kuota_terpakai',
        'kuota_total',
        'drive_folder_id',
        'google_access_token',
        'google_refresh_token',
        'google_token_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'kuota_terpakai' => 'decimal:2',
            'kuota_total' => 'decimal:2',
            // Refresh token adalah kredensial jangka panjang (tidak kedaluwarsa sampai
            // dicabut) — dienkripsi saat disimpan (Laravel encrypted cast, pakai APP_KEY)
            // supaya tidak tersimpan polos di database bila database bocor.
            'google_refresh_token' => 'encrypted',
            'google_token_expires_at' => 'datetime',
        ];
    }

    /**
     * True bila akun ini sudah login lewat OAuth (RF baru — lihat GoogleOAuthController)
     * dan bisa dipakai GoogleDriveService untuk mengunggah SEBAGAI akun ini sendiri,
     * bukan lewat Service Account yang tidak lagi bisa menulis ke Gmail biasa.
     */
    public function googleTerhubung(): bool
    {
        return filled($this->google_refresh_token);
    }

    public function berkas(): HasMany
    {
        return $this->hasMany(Berkas::class);
    }

    /**
     * Akun yang sedang dipakai untuk menampung UNGGAHAN BARU saat ini.
     * Dipakai oleh alur unggah berkas untuk tahu ke akun/folder Drive mana file harus disimpan.
     */
    public static function aktif(): ?self
    {
        return static::where('status', self::STATUS_AKTIF)->first();
    }

    /**
     * Jadikan akun ini "storage aktif" satu-satunya (RF-10a: "menetapkan SATU akun
     * sebagai storage aktif" — jadi akun lain yang tadinya aktif harus otomatis
     * dinonaktifkan). Dibungkus transaction supaya tidak pernah ada dua akun aktif
     * sekaligus meski terjadi race condition.
     */
    public function jadikanAktif(): void
    {
        DB::transaction(function () {
            static::where('id', '!=', $this->id)
                ->where('status', self::STATUS_AKTIF)
                ->update(['status' => self::STATUS_PENUH]);

            $this->update(['status' => self::STATUS_AKTIF]);
        });
    }

    /**
     * Persentase kuota terpakai, dibulatkan 1 desimal. Dipakai untuk progress bar
     * indikator kuota (RF-10c). Dijaga tidak melebihi 100 meski data kuota_terpakai
     * sempat tercatat lebih besar dari kuota_total.
     */
    public function persentaseTerpakai(): float
    {
        if ((float) $this->kuota_total <= 0) {
            return 0.0;
        }

        $persen = ((float) $this->kuota_terpakai / (float) $this->kuota_total) * 100;

        return round(min($persen, 100), 1);
    }

    /**
     * True bila kuota sudah menyentuh ambang peringatan (RF-10c) — sinyal bagi
     * Admin/Tim SAKIP untuk mulai menyiapkan akun institusi berikutnya.
     */
    public function mendekatiPenuh(): bool
    {
        return $this->persentaseTerpakai() >= self::AMBANG_PERINGATAN_PERSEN;
    }

    /**
     * Tambah catatan kuota terpakai setelah berkas baru diunggah ke akun ini.
     * $ukuranBytes datang langsung dari respons Google Drive API (ukuran asli di
     * server Drive), dikonversi ke GB agar sepadan dengan kolom kuota_total.
     */
    public function tambahKuotaTerpakai(int $ukuranBytes): void
    {
        $gb = $ukuranBytes / 1024 / 1024 / 1024;

        $this->increment('kuota_terpakai', round($gb, 4));
    }
}
