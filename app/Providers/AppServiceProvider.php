<?php

namespace App\Providers;

use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Lapisan pengaman tambahan selain trustProxies (bootstrap/app.php): di produksi
        // semua URL yang di-generate (form, aset, Livewire, tautan email) selalu https,
        // bahkan bila suatu saat header X-Forwarded-Proto dari proxy tidak terkirim.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Server & database menyimpan waktu dalam UTC (config('app.timezone')),
        // tapi Tim SAKIP berada di zona WITA — dipakai di mana pun waktu jam
        // (bukan cuma tanggal) ditampilkan ke pengguna, mis. riwayat status &
        // riwayat pengiriman email.
        Carbon::macro('wita', function () {
            /** @var Carbon $this */
            return $this->copy()->setTimezone('Asia/Makassar');
        });

        // Middleware peran rute (role:...) ikut dijalankan ulang di SETIAP request
        // Livewire (klik, simpan, unggah), bukan hanya saat halaman dibuka — jadi
        // pengguna yang perannya dicabut saat halaman masih terbuka langsung ditolak
        // di aksi berikutnya, di komponen mana pun.
        Livewire::addPersistentMiddleware([EnsureUserHasRole::class]);
    }
}
