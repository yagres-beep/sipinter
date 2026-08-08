<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'SIPINTER')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <div class="app">
        <aside class="sidebar">
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

                <nav class="nav">
                    <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}"><span class="ic">🏠</span> Dasbor</a>

                    @if ($role === 'Ketua Tim')
                        <a href="{{ route('pengisian.index') }}" class="{{ request()->routeIs('pengisian.*') ? 'active' : '' }}"><span class="ic">📝</span> Isian Kegiatan</a>
                        <a href="{{ route('notula-riwayat.index') }}" class="{{ request()->routeIs('notula-riwayat.*') ? 'active' : '' }}"><span class="ic">📄</span> Notula</a>
                    @elseif ($role === 'Tim SAKIP')
                        <a href="{{ route('verifikasi.index') }}" class="{{ request()->routeIs('verifikasi.*', 'notula.*') ? 'active' : '' }}"><span class="ic">✅</span> Verifikasi &amp; Notula</a>
                        <a href="{{ route('master-iku.index') }}" class="{{ request()->routeIs('master-iku.*', 'folder-config.*', 'template-notula.*') ? 'active' : '' }}"><span class="ic">📊</span> Master IKU</a>
                        <a href="{{ route('verifikasi-akun.index') }}" class="{{ request()->routeIs('verifikasi-akun.*', 'storage-accounts.*') ? 'active' : '' }}"><span class="ic">👥</span> Kelola Pengguna</a>
                    @elseif ($role === 'Kepala')
                        <a href="{{ route('persetujuan.index') }}" class="{{ request()->routeIs('persetujuan.*') ? 'active' : '' }}"><span class="ic">✍️</span> Persetujuan</a>
                        <a href="{{ route('dasbor-kinerja.index') }}" class="{{ request()->routeIs('dasbor-kinerja.*') ? 'active' : '' }}"><span class="ic">📊</span> Dasbor Kinerja</a>
                        <a href="{{ route('rekapitulasi.index') }}" class="{{ request()->routeIs('rekapitulasi.*') ? 'active' : '' }}"><span class="ic">📈</span> Rekapitulasi</a>
                        <a href="{{ route('notula-riwayat.index') }}" class="{{ request()->routeIs('notula-riwayat.*') ? 'active' : '' }}"><span class="ic">📄</span> Notula</a>
                    @endif

                    <a href="{{ route('lakin.index') }}" class="{{ request()->routeIs('lakin.*') ? 'active' : '' }}"><span class="ic">📈</span> LAKIN</a>
                    <a href="{{ route('profile.edit') }}" class="{{ request()->routeIs('profile.*') ? 'active' : '' }}"><span class="ic">⚙️</span> Pengaturan Akun</a>
                </nav>

                <div class="sidebar-foot">
                    <span class="sf-version">v1.0 · Aktualisasi 2026</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit">↪ Keluar</button>
                    </form>
                </div>
            @endauth
        </aside>

        <div class="main">
            <div class="topbar">
                <div class="crumb">
                    @auth
                        {{ $roleCrumb }} ›
                    @endauth
                    <b>@yield('breadcrumb')</b>
                </div>

                @auth
                    <div class="user">
                        <span>{{ $role }}</span>
                        <a href="{{ route('profile.edit') }}"><div class="avatar {{ $avatarKelas }}">{{ $avatarSingkatan }}</div></a>
                    </div>
                @endauth
            </div>

            <div class="content">
                @yield('content')
            </div>
        </div>
    </div>

    <button type="button" class="back-to-top" x-data="{ show: false }" :class="{ show: show }"
        x-init="window.addEventListener('scroll', () => show = window.scrollY > 300)"
        @click="window.scrollTo({ top: 0, behavior: 'smooth' })" title="Kembali ke atas">
        ↑
    </button>

    @livewireScripts
</body>
</html>
