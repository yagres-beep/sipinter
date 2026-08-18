@extends('layouts.app')

@section('title', 'Data Master & Konfigurasi — SIPINTER')

@section('breadcrumb', 'Data Master & Konfigurasi')

@section('content')
    <div x-data="{ tab: 'iku' }">
        <div class="subtabs">
            <button type="button" class="subtab" :class="tab === 'iku' ? 'on' : ''" @click="tab = 'iku'">📊 Master IKU</button>
            <button type="button" class="subtab" :class="tab === 'folder' ? 'on' : ''" @click="tab = 'folder'">📁 Struktur Folder</button>
            <button type="button" class="subtab" :class="tab === 'bagian' ? 'on' : ''" @click="tab = 'bagian'">🧩 Bagian Kustom</button>
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
    </div>
@endsection
