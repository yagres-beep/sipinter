<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
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
        'is_master',
        'google_access_token',
        'google_refresh_token',
        'google_token_expires_at',
        'google_terhubung_pada',
        'google_dicek_pada',
        'google_galat_pada',
        'google_galat',
    ];

    /** Nilai statusGoogle() — lihat method tersebut. */
    public const GOOGLE_TERHUBUNG = 'terhubung';

    public const GOOGLE_TERPUTUS = 'terputus';

    public const GOOGLE_TOKEN_RUSAK = 'token_rusak';

    public const GOOGLE_BELUM = 'belum';

    protected function casts(): array
    {
        return [
            'kuota_terpakai' => 'decimal:2',
            'kuota_total' => 'decimal:2',
            'is_master' => 'boolean',
            // Refresh token adalah kredensial jangka panjang (tidak kedaluwarsa sampai
            // dicabut) — dienkripsi saat disimpan (Laravel encrypted cast, pakai APP_KEY)
            // supaya tidak tersimpan polos di database bila database bocor.
            'google_refresh_token' => 'encrypted',
            'google_token_expires_at' => 'datetime',
            'google_terhubung_pada' => 'datetime',
            'google_dicek_pada' => 'datetime',
            'google_galat_pada' => 'datetime',
        ];
    }

    /**
     * Refresh token dalam bentuk terbaca, atau null bila belum pernah login OAuth
     * ATAU token tersimpan tidak bisa lagi didekripsi.
     *
     * Kasus "tidak bisa didekripsi" nyata terjadi bila APP_KEY di .env diganti
     * (mis. `php artisan key:generate` dijalankan ulang setelah akun terhubung):
     * cast 'encrypted' memakai APP_KEY, jadi token lama jadi sampah dan Laravel
     * melempar DecryptException "The MAC is invalid". Ditelan jadi null di sini —
     * BUKAN dibiarkan melempar — karena satu-satunya jalan pulih adalah membuka
     * halaman Akun & Storage lalu menekan "Hubungkan ulang", padahal halaman itu
     * sendiri memanggil googleTerhubung() untuk menggambar tombolnya. Kalau
     * melempar, halaman pemulihannya ikut mati dan tidak ada jalan keluar sama sekali.
     */
    public function googleRefreshToken(): ?string
    {
        try {
            return $this->google_refresh_token;
        } catch (DecryptException $e) {
            return null;
        }
    }

    /**
     * True bila akun ini sudah login lewat OAuth (RF baru — lihat GoogleOAuthController)
     * dan bisa dipakai GoogleDriveService untuk mengunggah SEBAGAI akun ini sendiri,
     * bukan lewat Service Account yang tidak lagi bisa menulis ke Gmail biasa.
     */
    public function googleTerhubung(): bool
    {
        return filled($this->googleRefreshToken());
    }

    /**
     * True bila akun ini PERNAH terhubung (ada token tersimpan di kolomnya) tapi
     * tokennya tidak bisa didekripsi lagi — lihat googleRefreshToken().
     */
    public function googleTokenRusak(): bool
    {
        return filled($this->getRawOriginal('google_refresh_token')) && ! $this->googleTerhubung();
    }

    /**
     * True bila token tersimpan masih terbaca tapi DITOLAK Google saat terakhir
     * dipakai (invalid_grant — kedaluwarsa 7 hari selama consent screen "Testing",
     * dicabut pemilik akun, dst.). Dicatat oleh GoogleDriveService::clientOAuthUntukAkun().
     */
    public function googleTerputus(): bool
    {
        return $this->googleTerhubung() && $this->google_galat_pada !== null;
    }

    /**
     * True bila unggahan ke Drive lewat akun ini sedang MATI dan Tim SAKIP harus
     * menghubungkan ulang — dibedakan dari "belum pernah terhubung" (googleTerhubung()
     * false tanpa token sama sekali), yang artinya memang belum disiapkan.
     */
    public function googlePerluHubungUlang(): bool
    {
        return $this->googleTokenRusak() || $this->googleTerputus();
    }

    /**
     * Satu dari konstanta GOOGLE_* — dipakai tampilan (menu Akun & Storage, banner
     * peringatan di semua halaman) supaya "Terhubung" berarti benar-benar diterima
     * Google, bukan sekadar ada token tersimpan.
     */
    public function statusGoogle(): string
    {
        return match (true) {
            $this->googleTokenRusak() => self::GOOGLE_TOKEN_RUSAK,
            $this->googleTerputus() => self::GOOGLE_TERPUTUS,
            $this->googleTerhubung() => self::GOOGLE_TERHUBUNG,
            default => self::GOOGLE_BELUM,
        };
    }

    /**
     * Akun storage AKTIF yang sambungan Google-nya bermasalah (atau belum ada akun
     * aktif sama sekali) — sumber banner peringatan di layout untuk SEMUA role.
     * null = aman. Satu query kecil per halaman, sengaja tidak di-cache supaya
     * banner langsung hilang begitu Tim SAKIP selesai menghubungkan ulang.
     *
     * @return array{akun: ?self, status: string}|null
     */
    public static function masalahSambunganAktif(): ?array
    {
        $akun = static::aktif();

        if (! $akun) {
            return ['akun' => null, 'status' => self::GOOGLE_BELUM];
        }

        $status = $akun->statusGoogle();

        return $status === self::GOOGLE_TERHUBUNG ? null : ['akun' => $akun, 'status' => $status];
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
     * Akun master folder saat ini (lihat GoogleOAuthController::callback() &
     * GoogleDriveService::bagikanFolder()) — akun BUKAN-master yang terhubung lewat
     * OAuth akan otomatis ditulisi drive_folder_id yang sama dengan akun ini alih-alih
     * membuat folder root sendiri, supaya seluruh akun institusi menulis ke SATU
     * struktur folder yang sama.
     *
     * Diutamakan dari kolom is_master (bisa diubah lewat menu Akun & Storage → "Jadikan
     * Master"); jatuh ke GOOGLE_DRIVE_MASTER_ACCOUNT_EMAIL di .env HANYA selama belum
     * ada satu pun baris is_master = true (mis. instalasi lama yang belum sempat pakai
     * menu ini) supaya pengaturan .env lama tidak mendadak berhenti berlaku.
     */
    public static function master(): ?self
    {
        $eksplisit = static::where('is_master', true)->first();

        if ($eksplisit) {
            return $eksplisit;
        }

        $emailEnv = config('services.google_drive.master_account_email');

        return $emailEnv ? static::whereRaw('LOWER(email_gmail_institusi) = ?', [strtolower($emailEnv)])->first() : null;
    }

    /**
     * Jadikan akun ini SATU-satunya akun master folder (menonaktifkan is_master akun
     * lain), sama seperti jadikanAktif() menjaga hanya ada satu storage aktif.
     */
    public function jadikanMaster(): void
    {
        DB::transaction(function () {
            static::where('id', '!=', $this->id)
                ->where('is_master', true)
                ->update(['is_master' => false]);

            $this->update(['is_master' => true]);
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

    /**
     * Timpa kuota_terpakai (dan kuota_total bila Google melaporkan batas tetap)
     * dengan angka SEBENARNYA dari Google Drive (GoogleDriveService::ambilKuota())
     * — beda dari tambahKuotaTerpakai() yang hanya menambah berdasarkan unggahan
     * lewat aplikasi ini, method ini MENGGANTI angka lama supaya tersinkron ulang
     * dengan kondisi asli di Drive (mis. ada berkas lain di akun yang sama, atau
     * berkas yang sudah dihapus manual di Drive).
     *
     * @param  array{usage_bytes: int, limit_bytes: ?int}  $kuota
     */
    public function sinkronKuota(array $kuota): void
    {
        $data = ['kuota_terpakai' => round($kuota['usage_bytes'] / 1024 / 1024 / 1024, 4)];

        if ($kuota['limit_bytes'] !== null) {
            $data['kuota_total'] = round($kuota['limit_bytes'] / 1024 / 1024 / 1024, 4);
        }

        $this->update($data);
    }
}
