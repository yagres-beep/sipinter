@extends('layouts.app')

@section('title', 'Data Master & Form — SIPINTER')

@section('breadcrumb', 'Data Master & Form')

@section('content')
    @php
        $tabMapping = [
            'iku' => 'iku',
            'target' => 'target',
            'target-tahunan' => 'target',
            'rumus' => 'rumus',
            'rumus-capaian' => 'rumus',
            'folder' => 'folder',
            'struktur-folder' => 'folder',
            'bagian' => 'bagian',
            'pengelolaan-bagian' => 'bagian',
            'variabel' => 'variabel',
            'katalog-variabel' => 'variabel',
        ];
        $tabUrlMap = [
            'iku' => 'iku',
            'target' => 'target-tahunan',
            'rumus' => 'rumus-capaian',
            'folder' => 'struktur-folder',
            'bagian' => 'bagian',
            'variabel' => 'variabel',
        ];
        $ruteTab = request()->route('tab');
        $tabAktif = $tabMapping[$ruteTab] ?? ($tabAktif ?? 'iku');
    @endphp
    <div class="tabs-page" x-data="{
        tab: ['iku', 'target', 'rumus', 'folder', 'bagian', 'variabel'].includes(window.location.hash.slice(1))
            ? window.location.hash.slice(1)
            : @js($tabAktif),
        urlMap: @js($tabUrlMap),
        pilihTab(t) {
            this.tab = t;
            const seg = this.urlMap[t] || t;
            history.replaceState(null, '', '{{ url('/master-data') }}/' + seg);
        }
    }">
        <div class="subtabs">
            <button type="button" class="subtab" :class="tab === 'iku' ? 'on' : ''" @click="pilihTab('iku')">📊 Master IKU</button>
            <button type="button" class="subtab" :class="tab === 'target' ? 'on' : ''" @click="pilihTab('target')">🎯 Target Tahunan</button>
            <button type="button" class="subtab" :class="tab === 'rumus' ? 'on' : ''" @click="pilihTab('rumus')">🧮 Rumus Capaian</button>
            <button type="button" class="subtab" :class="tab === 'folder' ? 'on' : ''" @click="pilihTab('folder')">📁 Struktur Folder</button>
            <button type="button" class="subtab" :class="tab === 'bagian' ? 'on' : ''" @click="pilihTab('bagian')">🧩 Pengelolaan Bagian</button>
            <button type="button" class="subtab" :class="tab === 'variabel' ? 'on' : ''" @click="pilihTab('variabel')">🔣 Katalog Variabel</button>
        </div>

        <div x-show="tab === 'iku'">
            <livewire:master-iku />
        </div>
        <div x-show="tab === 'target'" x-cloak>
            <livewire:target-tahunan />
        </div>
        <div x-show="tab === 'rumus'" x-cloak>
            <livewire:pengaturan-capaian />
        </div>
        <div x-show="tab === 'folder'" x-cloak>
            <livewire:folder-config-manager />
        </div>
        <div x-show="tab === 'bagian'" x-cloak>
            {{-- Pusat pengelolaan: daftar seluruh bagian (bawaan + kustom), lalu editor Bagian Kustom. --}}
            <livewire:daftar-bagian-sipinter />
            <div style="margin-top:20px"></div>
            <livewire:bagian-kustom-manager />
        </div>
        <div x-show="tab === 'variabel'" x-cloak>
            <livewire:variabel-katalog-manager />
        </div>
    </div>
@endsection
