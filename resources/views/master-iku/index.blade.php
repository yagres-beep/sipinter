@extends('layouts.app')

@section('title', 'Data Master & Konfigurasi — SIPINTER')

@section('breadcrumb', 'Data Master & Konfigurasi')

@section('content')
    <div class="tabs-page" x-data="{ tab: 'iku' }">
        <div class="subtabs">
            <button type="button" class="subtab" :class="tab === 'iku' ? 'on' : ''" @click="tab = 'iku'">📊 Master IKU</button>
            <button type="button" class="subtab" :class="tab === 'folder' ? 'on' : ''" @click="tab = 'folder'">📁 Struktur Folder</button>
            <button type="button" class="subtab" :class="tab === 'bagian' ? 'on' : ''" @click="tab = 'bagian'">🧩 Bagian Kustom</button>
            <button type="button" class="subtab" :class="tab === 'rumus' ? 'on' : ''" @click="tab = 'rumus'">🧮 Rumus Capaian</button>
        </div>

        <div x-show="tab === 'iku'">
            <livewire:master-iku />
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
