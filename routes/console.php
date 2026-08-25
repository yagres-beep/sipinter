<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pengingat WhatsApp otomatis (lihat app/Console/Commands/Pengingat*.php) — server
// yang menjalankan aplikasi ini butuh sesuatu yang memanggil `php artisan
// schedule:run` tiap menit (Windows Task Scheduler / cron job platform hosting),
// jadwal di bawah ini sendiri TIDAK berjalan tanpa itu.
Schedule::command('pengingat:deadline-iku')->dailyAt('08:00');
Schedule::command('pengingat:iku-lengkap')->dailyAt('08:00');
Schedule::command('pengingat:google-reconnect')->dailyAt('08:00');
