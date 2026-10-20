<?php

use App\Http\Middleware\EnsureEmailIsComplete;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

        // Token CSRF basi (halaman login dibiarkan terbuka melewati umur sesi, tab dipulihkan
        // browser HP, dsb.) semula berujung halaman "419 Page Expired" yang membingungkan.
        // Untuk form biasa, kembalikan ke halaman sebelumnya — yang otomatis membawa token baru —
        // lengkap dengan isian lama (kecuali sandi) supaya pengguna tinggal menekan tombol lagi.
        // Request Livewire/JSON dibiarkan: Livewire sudah punya penanganan 419 sendiri.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return null;
            }

            if ($request->routeIs('logout')) {
                return redirect()->route('login');
            }

            // back() memakai header Referer apa adanya, termasuk domain luar — POST lintas
            // situs dengan token basi tidak boleh memantul ke sana atau menitipkan isiannya.
            $kembali = url()->previous();
            if (parse_url($kembali, PHP_URL_HOST) !== $request->getHost()) {
                return redirect()->route('login');
            }

            return redirect()->to($kembali)
                ->withInput($request->except(['_token', 'password', 'password_confirmation', 'current_password']))
                ->withErrors(['sesi' => 'Halaman sudah terlalu lama terbuka sehingga sesinya kedaluwarsa. Silakan coba lagi.']);
        });
    })->create();
