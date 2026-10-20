<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'SIPINTER')</title>
    <x-theme-init-script />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <div class="auth-page">
        @yield('content')
    </div>
    @livewireScripts
    <script>
        // Token CSRF di form ikut kedaluwarsa bersama sesi. Bila halaman dipulihkan dari cache
        // tombol Back, atau tab dibuka lagi setelah lama ditinggal, muat ulang supaya tokennya baru.
        // Skrip ini ikut dijalankan ulang tiap pindah halaman lewat wire:navigate — pasang
        // listener sekali saja, cukup perbarui waktu muatnya.
        window.__authDimuat = Date.now();
        if (! window.__authSegarkanToken) {
            window.__authSegarkanToken = true;
            var batas = ({{ (int) config('session.lifetime') }} - 5) * 60 * 1000;
            window.addEventListener('pageshow', function (e) {
                if (e.persisted) location.reload();
            });
            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'visible' && Date.now() - window.__authDimuat > batas) location.reload();
            });
        }
    </script>
</body>
</html>
