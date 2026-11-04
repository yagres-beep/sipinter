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

                <nav class="nav" @click="if ($event.target.closest('a')) sidebarOpen = false">
                    @if ($role === 'Ketua Tim')
                        <a wire:navigate href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard*') ? 'active' : '' }}"><span class="ic">🏠</span> Dasbor</a>

                        @php
                            $isPengisianRoute = request()->routeIs('pengisian.*', 'rtl-evaluasi.*');
                        @endphp
                        <div class="nav-collapsible" x-data="{ open: @js($isPengisianRoute) }">
                            <div class="nav-parent {{ $isPengisianRoute ? 'parent-active' : '' }}" @click="open = !open">
                                <span class="nav-parent-text"><span class="ic">📝</span> Isian Kegiatan</span>
                                <span class="nav-chevron" :class="{ 'open': open }">›</span>
                            </div>
                            <div class="nav-sublist" x-show="open" x-cloak>
                                <a wire:navigate href="{{ route('pengisian.index') }}" class="nav-subitem {{ request()->routeIs('pengisian.*') ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Isian Capaian
                                </a>
                                <a wire:navigate href="{{ route('rtl-evaluasi.index') }}" class="nav-subitem {{ request()->routeIs('rtl-evaluasi.*') ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> RTL &amp; Evaluasi
                                </a>
                            </div>
                        </div>

                        <a wire:navigate href="{{ route('notula-riwayat.index') }}" class="{{ request()->routeIs('notula-riwayat.*') ? 'active' : '' }}"><span class="ic">📄</span> Riwayat Notula</a>
                    @elseif ($role === 'Tim SAKIP')
                        @php
                            $tabDasbor = request()->route('tab');
                            $isDasborActive = request()->routeIs('dashboard*');
                            $isPemantauanActive = $isDasborActive && ($tabDasbor === 'pemantauan' || empty($tabDasbor));
                            $isKinerjaActive = $isDasborActive && in_array($tabDasbor, ['kinerja', 'capaian-kinerja']);
                        @endphp
                        <div class="nav-collapsible" x-data="{ open: @js($isDasborActive) }">
                            <div class="nav-parent {{ $isDasborActive ? 'parent-active' : '' }}" @click="open = !open">
                                <span class="nav-parent-text"><span class="ic">🏠</span> Dasbor</span>
                                <span class="nav-chevron" :class="{ 'open': open }">›</span>
                            </div>
                            <div class="nav-sublist" x-show="open" x-cloak>
                                <a wire:navigate href="{{ route('dashboard', ['tab' => 'pemantauan']) }}" class="nav-subitem {{ $isPemantauanActive ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Pemantauan Pengisian
                                </a>
                                <a wire:navigate href="{{ route('dashboard', ['tab' => 'capaian-kinerja']) }}" class="nav-subitem {{ $isKinerjaActive ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Capaian Kinerja
                                </a>
                            </div>
                        </div>

                        @php
                            $isVerifRoute = request()->routeIs('verifikasi.*', 'notula.*', 'template-notula.*');
                            $tabVerif = request()->route('tab');
                            $isSubVerif = $isVerifRoute && ($tabVerif === 'verifikasi' || (empty($tabVerif) && !request()->routeIs('notula.*', 'template-notula.*')));
                            $isSubKompilasi = $isVerifRoute && (in_array($tabVerif, ['kompilasi', 'notula']) || request()->routeIs('notula.*'));
                            $isSubTemplate = $isVerifRoute && (in_array($tabVerif, ['template', 'template-notula']) || request()->routeIs('template-notula.*'));
                        @endphp
                        <div class="nav-collapsible" x-data="{ open: @js($isVerifRoute) }">
                            <div class="nav-parent {{ $isVerifRoute ? 'parent-active' : '' }}" @click="open = !open">
                                <span class="nav-parent-text"><span class="ic">✅</span> Verifikasi &amp; Notula</span>
                                <span class="nav-chevron" :class="{ 'open': open }">›</span>
                            </div>
                            <div class="nav-sublist" x-show="open" x-cloak>
                                <a wire:navigate href="{{ route('verifikasi.index', ['tab' => 'verifikasi']) }}" class="nav-subitem {{ $isSubVerif ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Verifikasi Capaian
                                </a>
                                <a wire:navigate href="{{ route('verifikasi.index', ['tab' => 'kompilasi']) }}" class="nav-subitem {{ $isSubKompilasi ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Kompilasi Notula
                                </a>
                                <a wire:navigate href="{{ route('verifikasi.index', ['tab' => 'template']) }}" class="nav-subitem {{ $isSubTemplate ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Template Notula
                                </a>
                            </div>
                        </div>

                        @php
                            $isMasterRoute = request()->routeIs('master-iku.*', 'folder-config.*');
                            $tabMaster = request()->route('tab');
                            $isSubIku = $isMasterRoute && ($tabMaster === 'iku' || empty($tabMaster));
                            $isSubTarget = $isMasterRoute && in_array($tabMaster, ['target', 'target-tahunan']);
                            $isSubRumus = $isMasterRoute && in_array($tabMaster, ['rumus', 'rumus-capaian']);
                            $isSubFolder = $isMasterRoute && in_array($tabMaster, ['folder', 'struktur-folder']);
                            $isSubBagian = $isMasterRoute && in_array($tabMaster, ['bagian', 'pengelolaan-bagian']);
                            $isSubVariabel = $isMasterRoute && in_array($tabMaster, ['variabel', 'katalog-variabel']);
                        @endphp
                        <div class="nav-collapsible" x-data="{ open: @js($isMasterRoute) }">
                            <div class="nav-parent {{ $isMasterRoute ? 'parent-active' : '' }}" @click="open = !open">
                                <span class="nav-parent-text"><span class="ic">📊</span> Data Master &amp; Form</span>
                                <span class="nav-chevron" :class="{ 'open': open }">›</span>
                            </div>
                            <div class="nav-sublist" x-show="open" x-cloak>
                                <a wire:navigate href="{{ route('master-iku.index', ['tab' => 'iku']) }}" class="nav-subitem {{ $isSubIku ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Master IKU
                                </a>
                                <a wire:navigate href="{{ route('master-iku.index', ['tab' => 'target']) }}" class="nav-subitem {{ $isSubTarget ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Target Tahunan
                                </a>
                                <a wire:navigate href="{{ route('master-iku.index', ['tab' => 'rumus']) }}" class="nav-subitem {{ $isSubRumus ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Rumus Capaian
                                </a>
                                <a wire:navigate href="{{ route('master-iku.index', ['tab' => 'folder']) }}" class="nav-subitem {{ $isSubFolder ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Struktur Folder
                                </a>
                                <a wire:navigate href="{{ route('master-iku.index', ['tab' => 'bagian']) }}" class="nav-subitem {{ $isSubBagian ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Pengelolaan Bagian
                                </a>
                                <a wire:navigate href="{{ route('master-iku.index', ['tab' => 'variabel']) }}" class="nav-subitem {{ $isSubVariabel ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Katalog Variabel
                                </a>
                            </div>
                        </div>

                        <a wire:navigate href="{{ route('bagian-kustom.index') }}" class="{{ request()->routeIs('bagian-kustom.*') ? 'active' : '' }}"><span class="ic">🧩</span> Isian Bagian Kustom</a>

                        @php
                            $isUserRoute = request()->routeIs('verifikasi-akun.*');
                            $tabUser = request()->route('tab');
                            $isSubVerifAkun = $isUserRoute && ($tabUser === 'verifikasi' || empty($tabUser));
                            $isSubPenugasan = $isUserRoute && $tabUser === 'penugasan';
                        @endphp
                        <div class="nav-collapsible" x-data="{ open: @js($isUserRoute) }">
                            <div class="nav-parent {{ $isUserRoute ? 'parent-active' : '' }}" @click="open = !open">
                                <span class="nav-parent-text"><span class="ic">👥</span> Pengguna &amp; Tim</span>
                                <span class="nav-chevron" :class="{ 'open': open }">›</span>
                            </div>
                            <div class="nav-sublist" x-show="open" x-cloak>
                                <a wire:navigate href="{{ route('verifikasi-akun.index', ['tab' => 'verifikasi']) }}" class="nav-subitem {{ $isSubVerifAkun ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Verifikasi &amp; Akun
                                </a>
                                <a wire:navigate href="{{ route('verifikasi-akun.index', ['tab' => 'penugasan']) }}" class="nav-subitem {{ $isSubPenugasan ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Penugasan IKU
                                </a>
                            </div>
                        </div>

                        @php
                            $isSistemRoute = request()->routeIs('pengaturan-sistem.*', 'storage-accounts.*');
                            $tabSistem = request()->route('tab');
                            $isSubStorage = $isSistemRoute && (in_array($tabSistem, ['storage', 'akun-storage']) || empty($tabSistem));
                            $isSubPengingat = $isSistemRoute && in_array($tabSistem, ['pengingat', 'whatsapp']);
                            $isSubBackup = $isSistemRoute && in_array($tabSistem, ['backup', 'cadangan']);
                            $isSubLog = $isSistemRoute && in_array($tabSistem, ['log', 'log-aktivitas']);
                        @endphp
                        <div class="nav-collapsible" x-data="{ open: @js($isSistemRoute) }">
                            <div class="nav-parent {{ $isSistemRoute ? 'parent-active' : '' }}" @click="open = !open">
                                <span class="nav-parent-text"><span class="ic">⚙️</span> Sistem &amp; Integrasi</span>
                                <span class="nav-chevron" :class="{ 'open': open }">›</span>
                            </div>
                            <div class="nav-sublist" x-show="open" x-cloak>
                                <a wire:navigate href="{{ route('pengaturan-sistem.index', ['tab' => 'storage']) }}" class="nav-subitem {{ $isSubStorage ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Akun &amp; Storage
                                </a>
                                <a wire:navigate href="{{ route('pengaturan-sistem.index', ['tab' => 'pengingat']) }}" class="nav-subitem {{ $isSubPengingat ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Pengingat &amp; Notifikasi
                                </a>
                                <a wire:navigate href="{{ route('pengaturan-sistem.index', ['tab' => 'backup']) }}" class="nav-subitem {{ $isSubBackup ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Cadangan Data
                                </a>
                                <a wire:navigate href="{{ route('pengaturan-sistem.index', ['tab' => 'log']) }}" class="nav-subitem {{ $isSubLog ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Log Aktivitas
                                </a>
                            </div>
                        </div>
                    @elseif ($role === 'Kepala')
                        @php
                            $tabDasbor = request()->route('tab');
                            $isDasborActive = request()->routeIs('dashboard*');
                            $isPemantauanActive = $isDasborActive && ($tabDasbor === 'pemantauan' || empty($tabDasbor));
                            $isKinerjaActive = $isDasborActive && in_array($tabDasbor, ['kinerja', 'capaian-kinerja']);
                        @endphp
                        <div class="nav-collapsible" x-data="{ open: @js($isDasborActive) }">
                            <div class="nav-parent {{ $isDasborActive ? 'parent-active' : '' }}" @click="open = !open">
                                <span class="nav-parent-text"><span class="ic">🏠</span> Dasbor</span>
                                <span class="nav-chevron" :class="{ 'open': open }">›</span>
                            </div>
                            <div class="nav-sublist" x-show="open" x-cloak>
                                <a wire:navigate href="{{ route('dashboard', ['tab' => 'pemantauan']) }}" class="nav-subitem {{ $isPemantauanActive ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Pemantauan Pengisian
                                </a>
                                <a wire:navigate href="{{ route('dashboard', ['tab' => 'capaian-kinerja']) }}" class="nav-subitem {{ $isKinerjaActive ? 'active' : '' }}">
                                    <span class="sub-bullet"></span> Capaian Kinerja
                                </a>
                            </div>
                        </div>

                        <a wire:navigate href="{{ route('persetujuan.index') }}" class="{{ request()->routeIs('persetujuan.*') ? 'active' : '' }}"><span class="ic">✍️</span> Persetujuan</a>
                        <a wire:navigate href="{{ route('bagian-kustom.index') }}" class="{{ request()->routeIs('bagian-kustom.*') ? 'active' : '' }}"><span class="ic">🧩</span> Isian Bagian Kustom</a>
                        <a wire:navigate href="{{ route('notula-riwayat.index') }}" class="{{ request()->routeIs('notula-riwayat.*') ? 'active' : '' }}"><span class="ic">📄</span> Riwayat Notula</a>
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
                @auth
                    <x-peringatan-sambungan-drive />
                @endauth
                @yield('content')
                {{-- Bagian kustom yang ditempatkan Tim SAKIP di menu ini, tampil di bawah halaman. --}}
                @php $menuBagianKustom = \App\Support\MenuBagianKustom::dariRoute(request()->route()?->getName()); @endphp
                @if ($menuBagianKustom && $menuBagianKustom !== \App\Support\MenuBagianKustom::PENGISIAN)
                    <livewire:isian-bagian-kustom :menu="$menuBagianKustom" :key="'bagian-kustom-menu-'.$menuBagianKustom" />
                @endif
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

    <x-toast-stack />
    <x-dialog-konfirmasi />

    @livewireScripts
</body>
</html>
