@extends('layouts.app')

@section('title', 'Data Master & Konfigurasi — SIPINTER')

@section('breadcrumb', 'Data Master & Konfigurasi')

@section('content')
    {{-- Tab awal ikut #hash di URL kalau ada (dipakai tautan dari halaman lain,
        mis. Verifikasi -> "Ubah di Target Tahunan") supaya langsung terbuka di
        tab yang dimaksud, bukan selalu jatuh ke tab Master IKU. --}}
    <div class="tabs-page" x-data="{ tab: ['iku', 'target', 'folder', 'bagian', 'rumus'].includes(window.location.hash.slice(1)) ? window.location.hash.slice(1) : 'iku' }">
        <div class="subtabs">
            <button type="button" class="subtab" :class="tab === 'iku' ? 'on' : ''" @click="tab = 'iku'; window.location.hash = 'iku'">📊 Master IKU</button>
            <button type="button" class="subtab" :class="tab === 'target' ? 'on' : ''" @click="tab = 'target'; window.location.hash = 'target'">🎯 Target Tahunan</button>
            <button type="button" class="subtab" :class="tab === 'folder' ? 'on' : ''" @click="tab = 'folder'; window.location.hash = 'folder'">📁 Struktur Folder</button>
            <button type="button" class="subtab" :class="tab === 'bagian' ? 'on' : ''" @click="tab = 'bagian'; window.location.hash = 'bagian'">🧩 Bagian Kustom</button>
            <button type="button" class="subtab" :class="tab === 'rumus' ? 'on' : ''" @click="tab = 'rumus'; window.location.hash = 'rumus'">🧮 Rumus Capaian</button>
        </div>

        <div x-show="tab === 'iku'">
            <livewire:master-iku />
        </div>
        <div x-show="tab === 'target'" x-cloak>
            <livewire:target-tahunan />
        </div>
        <div x-show="tab === 'folder'" x-cloak>
            <livewire:folder-config-manager />
        </div>
        <div x-show="tab === 'bagian'" x-cloak>
            <livewire:bagian-kustom-manager />
        </div>
        <div x-show="tab === 'rumus'" x-cloak>
            <livewire:pengaturan-capaian />
        </div>
    </div>
@endsection
