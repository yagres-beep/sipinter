@extends('layouts.app')

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
    $ruteTab = request()->route('tab');
    $tabAktif = $tabMapping[$ruteTab] ?? ($tabAktif ?? 'iku');
    $judulSub = match ($tabAktif) {
        'target' => 'Target Tahunan',
        'rumus' => 'Rumus Capaian',
        'folder' => 'Struktur Folder',
        'bagian' => 'Pengelolaan Bagian',
        'variabel' => 'Katalog Variabel',
        default => 'Master IKU',
    };
@endphp

@section('title', $judulSub . ' — SIPINTER')

@section('breadcrumb')
    Data Master &amp; Form › <b>{{ $judulSub }}</b>
@endsection

@section('content')
    <div class="subtabs" style="margin-bottom:16px">
        <a wire:navigate href="{{ route('master-iku.index', ['tab' => 'iku']) }}" class="subtab {{ $tabAktif === 'iku' ? 'on' : '' }}">
            <span>📊 Master IKU</span>
        </a>
        <a wire:navigate href="{{ route('master-iku.index', ['tab' => 'target']) }}" class="subtab {{ $tabAktif === 'target' ? 'on' : '' }}">
            <span>🎯 Target Tahunan</span>
        </a>
        <a wire:navigate href="{{ route('master-iku.index', ['tab' => 'rumus']) }}" class="subtab {{ $tabAktif === 'rumus' ? 'on' : '' }}">
            <span>📐 Rumus Capaian</span>
        </a>
        <a wire:navigate href="{{ route('master-iku.index', ['tab' => 'folder']) }}" class="subtab {{ $tabAktif === 'folder' ? 'on' : '' }}">
            <span>📁 Struktur Folder</span>
        </a>
        <a wire:navigate href="{{ route('master-iku.index', ['tab' => 'bagian']) }}" class="subtab {{ $tabAktif === 'bagian' ? 'on' : '' }}">
            <span>📑 Pengelolaan Bagian</span>
        </a>
        <a wire:navigate href="{{ route('master-iku.index', ['tab' => 'variabel']) }}" class="subtab {{ $tabAktif === 'variabel' ? 'on' : '' }}">
            <span>📚 Katalog Variabel</span>
        </a>
    </div>

    @if ($tabAktif === 'iku')
        <livewire:master-iku />
    @elseif ($tabAktif === 'target')
        <livewire:target-tahunan />
    @elseif ($tabAktif === 'rumus')
        <livewire:pengaturan-capaian />
    @elseif ($tabAktif === 'folder')
        <livewire:folder-config-manager />
    @elseif ($tabAktif === 'bagian')
        {{-- Pusat pengelolaan: daftar seluruh bagian (bawaan + kustom), lalu editor Bagian Kustom. --}}
        <livewire:daftar-bagian-sipinter />
        <div style="margin-top:20px"></div>
        <livewire:bagian-kustom-manager />
    @elseif ($tabAktif === 'variabel')
        <livewire:variabel-katalog-manager />
    @endif
@endsection
