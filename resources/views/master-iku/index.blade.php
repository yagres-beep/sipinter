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
