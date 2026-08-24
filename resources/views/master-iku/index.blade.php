@extends('layouts.app')

@section('title', 'Data Master & Konfigurasi — SIPINTER')

@section('breadcrumb', 'Data Master & Konfigurasi')

@section('content')
    {{-- Tab awal ikut #hash di URL kalau ada (dipakai tautan dari halaman lain,
        mis. Verifikasi -> "Ubah di Target Tahunan") supaya langsung terbuka di
        tab yang dimaksud, bukan selalu jatuh ke tab Master IKU.

        Urutan tab SENGAJA: Master IKU, Target Tahunan, Rumus Capaian bersebelahan
        — ketiganya sama-sama "seputar Capaian" (definisi IKU -> target tahunannya
        -> rumus/plafon yang dipakai menilainya), baru disusul Struktur Folder &
        Bagian Kustom yang topiknya beda. Target Tahunan & Rumus Capaian sengaja
        TIDAK digabung jadi satu tab meski berhubungan erat — Rumus Capaian itu
        pengaturan organisasi-wide yang jarang diubah (2-3 angka plafon), sedangkan
        Target Tahunan itu data operasional per-IKU yang diisi ulang tiap tahun
        (tabel besar) — digabung akan membuat yang satu "menumpang" di tab yang
        ritme pemakaiannya beda. Cukup ditautkan silang (lihat masing-masing view). --}}
    <div class="tabs-page" x-data="{ tab: ['iku', 'target', 'rumus', 'folder', 'bagian'].includes(window.location.hash.slice(1)) ? window.location.hash.slice(1) : 'iku' }">
        <div class="subtabs">
            <button type="button" class="subtab" :class="tab === 'iku' ? 'on' : ''" @click="tab = 'iku'; window.location.hash = 'iku'">📊 Master IKU</button>
            <button type="button" class="subtab" :class="tab === 'target' ? 'on' : ''" @click="tab = 'target'; window.location.hash = 'target'">🎯 Target Tahunan</button>
            <button type="button" class="subtab" :class="tab === 'rumus' ? 'on' : ''" @click="tab = 'rumus'; window.location.hash = 'rumus'">🧮 Rumus Capaian</button>
            <button type="button" class="subtab" :class="tab === 'folder' ? 'on' : ''" @click="tab = 'folder'; window.location.hash = 'folder'">📁 Struktur Folder</button>
            <button type="button" class="subtab" :class="tab === 'bagian' ? 'on' : ''" @click="tab = 'bagian'; window.location.hash = 'bagian'">🧩 Bagian Kustom</button>
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
            <livewire:bagian-kustom-manager />
        </div>
    </div>
@endsection
