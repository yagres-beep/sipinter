<?php

use App\Http\Middleware\EnsureEmailIsComplete;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'profile.complete' => EnsureEmailIsComplete::class,
        ]);

        // Render (dan platform sejenis) meneruskan koneksi HTTPS ke container secara HTTP
        // biasa lewat reverse proxy. Tanpa ini, Laravel tidak tahu request aslinya HTTPS,
        // sehingga semua URL yang di-generate (aset, form, Livewire) memakai skema http://
        // dan diblokir browser sebagai mixed content di halaman https://.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Jaring pengaman: kunci per-sesi yang basi (lihat PengisianKegiatan::boot()) semestinya
        // sudah ditangani di sumbernya, tapi kalau ada pemakaian lain yang lolos, jangan sampai
        // pengguna melihat halaman 500 kosong — kembalikan saja ke halaman sebelumnya.
        $exceptions->render(function (LockTimeoutException $e, $request) {
            return back()->with('error', 'Sistem sedang sibuk memproses aksi sebelumnya — silakan coba lagi sebentar.');
        });
    })->create();
