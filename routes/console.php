<?php

use App\Models\PengaturanPengingat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pengingat email otomatis (lihat app/Console/Commands/Pengingat*.php) — server
// yang menjalankan aplikasi ini butuh sesuatu yang memanggil `php artisan
// schedule:run` tiap menit (lihat program "scheduler" di docker/supervisord.conf),
// jadwal di bawah ini sendiri TIDAK berjalan tanpa itu.
//
// Jam kirimnya diambil dari App\Models\PengaturanPengingat (bisa diubah Tim SAKIP
// lewat halaman Pengingat) bukan dikodekan langsung — file ini di-load ulang
// tiap kali `artisan` dijalankan (termasuk tiap tick schedule:run), jadi cukup
// query biasa di sini, tidak perlu closure. Dibungkus try/catch supaya PERINTAH
// ARTISAN LAIN (mis. `migrate` saat deploy pertama, sebelum tabelnya ada) tidak
// ikut gagal hanya karena baris ini.
try {
    $jamPengingat = PengaturanPengingat::ambil()->jamKirimFormat();
} catch (\Throwable $e) {
    $jamPengingat = '08:00';
}

// Jam yang diisi Tim SAKIP di halaman Pengingat adalah jam WITA (lihat
// App\Livewire\PengaturanPengingat::konversiJam), sedangkan server berjalan
// di UTC — ->timezone() di sini yang menerjemahkannya, bukan jam mentahnya.
Schedule::command('pengingat:deadline-iku')->dailyAt($jamPengingat)->timezone('Asia/Makassar');
Schedule::command('pengingat:iku-lengkap')->dailyAt($jamPengingat)->timezone('Asia/Makassar');
Schedule::command('pengingat:google-reconnect')->dailyAt($jamPengingat)->timezone('Asia/Makassar');
