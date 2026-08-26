<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

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
        // Server & database menyimpan waktu dalam UTC (config('app.timezone')),
        // tapi Tim SAKIP berada di zona WITA — dipakai di mana pun waktu jam
        // (bukan cuma tanggal) ditampilkan ke pengguna, mis. riwayat status &
        // riwayat pengiriman email.
        Carbon::macro('wita', function () {
            /** @var Carbon $this */
            return $this->copy()->setTimezone('Asia/Makassar');
        });
    }
}
