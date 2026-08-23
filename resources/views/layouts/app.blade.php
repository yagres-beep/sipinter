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
    <div class="app" x-data="{ sidebarOpen: false }">
        <aside class="sidebar" :class="{ open: sidebarOpen }">
            <div class="brand">
                <div class="logo">📊</div>
                <div>
                    <h2>SIPINTER</h2>
                    <span>BPS Buton Utara</span>
                </div>
            </div>

            @auth
                @php
                    $role = auth()->user()->namaRole();
                    $avatarKelas = match ($role) {
                        'Ketua Tim' => 'av-k',
                        'Tim SAKIP' => 'av-s',
                        'Kepala' => 'av-p',
                        default => 'av-k',
                    };
                    $avatarSingkatan = match ($role) {
                        'Ketua Tim' => 'KT',
                        'Tim SAKIP' => 'TS',
                        'Kepala' => 'KP',
                        default => '??',
                    };
                    $roleCrumb = match ($role) {
                        'Ketua Tim' => 'Ketua',
                        'Tim SAKIP' => 'Tim',
                        'Kepala' => 'Kepala',
                        default => $role,
                    };
                @endphp

                <nav class="nav" @click="sidebarOpen = false">
                    <a wire:navigate href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}"><span class="ic">🏠</span> Dasbor</a>

                    @if ($role === 'Ketua Tim')
                        <a wire:navigate href="{{ route('pengisian.index') }}" class="{{ request()->routeIs('pengisian.*') ? 'active' : '' }}"><span class="ic">📝</span> Isian Kegiatan</a>
                        <a wire:navigate href="{{ route('notula-riwayat.index') }}" class="{{ request()->routeIs('notula-riwayat.*') ? 'active' : '' }}"><span class="ic">📄</span> Notula</a>
                    @elseif ($role === 'Tim SAKIP')
                        <a wire:navigate href="{{ route('verifikasi.index') }}" class="{{ request()->routeIs('verifikasi.*', 'notula.*', 'template-notula.*') ? 'active' : '' }}"><span class="ic">✅</span> Verifikasi &amp; Notula</a>
                        <a wire:navigate href="{{ route('master-iku.index') }}" class="{{ request()->routeIs('master-iku.*', 'folder-config.*') ? 'active' : '' }}"><span class="ic">📊</span> Data Master &amp; Konfigurasi</a>
                        <a wire:navigate href="{{ route('verifikasi-akun.index') }}" class="{{ request()->routeIs('verifikasi-akun.*', 'storage-accounts.*') ? 'active' : '' }}"><span class="ic">👥</span> Kelola Pengguna</a>
                    @elseif ($role === 'Kepala')
                        <a wire:navigate href="{{ route('persetujuan.index') }}" class="{{ request()->routeIs('persetujuan.*') ? 'active' : '' }}"><span class="ic">✍️</span> Persetujuan</a>
                        <a wire:navigate href="{{ route('dasbor-kinerja.index') }}" class="{{ request()->routeIs('dasbor-kinerja.*') ? 'active' : '' }}"><span class="ic">📊</span> Dasbor Kinerja</a>
                        <a wire:navigate href="{{ route('notula-riwayat.index') }}" class="{{ request()->routeIs('notula-riwayat.*') ? 'active' : '' }}"><span class="ic">📄</span> Notula</a>
                    @endif

                    <a wire:navigate href="{{ route('lakin.index') }}" class="{{ request()->routeIs('lakin.*') ? 'active' : '' }}"><span class="ic">📈</span> Rekap Kinerja Tahunan</a>
                </nav>

                <div class="sidebar-foot">
                    <span class="sf-version">v1.0 · Aktualisasi 2026</span>
                </div>
            @endauth
        </aside>

        <div class="sidebar-backdrop" :class="{ show: sidebarOpen }" x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"></div>

        <div class="main">
            <div class="topbar">
                <div class="crumb">
                    @auth
                        <button type="button" class="menu-toggle" @click="sidebarOpen = !sidebarOpen" aria-label="Buka menu">☰</button>
                        {{ $roleCrumb }} ›
                    @endauth
                    <b>@yield('breadcrumb')</b>
                </div>

                @auth
                    <div class="user-menu" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                        <button type="button" class="user" :aria-expanded="open" @click="open = !open">
                            <span>{{ $role }}</span>
                            <div class="avatar {{ $avatarKelas }}">{{ $avatarSingkatan }}</div>
                        </button>

                        <div class="user-menu-panel" x-show="open" x-cloak x-transition @click="open = false">
                            <div class="ump-head">
                                <div class="avatar {{ $avatarKelas }}">{{ $avatarSingkatan }}</div>
                                <div>
                                    <div class="ump-name">{{ auth()->user()->nama }}</div>
                                    <div class="ump-role">{{ $role }}</div>
                                </div>
                            </div>
                            <a wire:navigate href="{{ route('profile.edit') }}" class="ump-item"><span class="ic">⚙️</span> Pengaturan Akun</a>
                            <form method="POST" action="{{ route('logout') }}" class="ump-item ump-logout">
                                @csrf
                                <button type="submit"><span class="ic">↪</span> Keluar</button>
                            </form>
                        </div>
                    </div>
                @endauth
            </div>

            <div class="content">
                @yield('content')
            </div>
        </div>
    </div>

    @auth
        <div class="theme-switch-float" x-data="{
            open: false,
            tema: localStorage.getItem('sipinter-tema') || 'system',
            aturTema(t) {
                this.tema = t;
                this.open = false;
                if (t === 'system') {
                    localStorage.removeItem('sipinter-tema');
                    document.documentElement.removeAttribute('data-theme');
                } else {
                    localStorage.setItem('sipinter-tema', t);
                    document.documentElement.setAttribute('data-theme', t);
                }
            },
            ikon() { return this.tema === 'light' ? '☀️' : (this.tema === 'dark' ? '🌙' : '🖥️'); }
        }" @click.outside="open = false" @keydown.escape.window="open = false">
            <div class="theme-switch-panel" x-show="open" x-cloak x-transition role="group" aria-label="Pilih tema tampilan">
                <button type="button" :class="{ on: tema === 'system' }" @click="aturTema('system')" title="Ikut tema perangkat">🖥️</button>
                <button type="button" :class="{ on: tema === 'light' }" @click="aturTema('light')" title="Mode terang">☀️</button>
                <button type="button" :class="{ on: tema === 'dark' }" @click="aturTema('dark')" title="Mode gelap">🌙</button>
            </div>
            <button type="button" class="theme-switch-toggle" @click="open = !open" :aria-expanded="open" x-text="ikon()" title="Pilih tema tampilan"></button>
        </div>
    @endauth

    <button type="button" class="back-to-top" x-data="{ show: false }" :class="{ show: show }"
        x-init="window.addEventListener('scroll', () => show = window.scrollY > 120)"
        @click="window.scrollTo({ top: 0, behavior: 'smooth' })" title="Kembali ke atas">
        ↑
    </button>

    @livewireScripts
</body>
</html>
