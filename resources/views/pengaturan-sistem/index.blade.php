@extends('layouts.app')

@php
    $tabAktif = $tabAktif ?? (request()->route('tab') ?? 'storage');
    if (!in_array($tabAktif, ['storage', 'pengingat', 'backup', 'log'])) {
        $tabAktif = 'storage';
    }
    $judulSub = match ($tabAktif) {
        'pengingat' => 'Pengingat & Notifikasi',
        'backup' => 'Cadangan Data',
        'log' => 'Log Aktivitas',
        default => 'Akun & Storage',
    };
@endphp

@section('title', $judulSub . ' — SIPINTER')

@section('breadcrumb')
    Sistem &amp; Integrasi › <b>{{ $judulSub }}</b>
@endsection

@section('content')
    <div class="page-head" style="margin-bottom:18px">
        <div class="page-title">{{ $judulSub }}</div>
        <div class="page-sub">
            @if ($tabAktif === 'storage')
                Pengaturan akun penyimpanan Google Drive dan folder sinkronisasi.
            @elseif ($tabAktif === 'pengingat')
                Pengingat otomatis dikirim lewat email, dan juga lewat WhatsApp kantor bagi pengguna yang menyetujuinya.
            @elseif ($tabAktif === 'backup')
                Cadangan basis data berkala untuk keamanan data SIPINTER.
            @elseif ($tabAktif === 'log')
                Riwayat catatan aktivitas sistem pengguna.
            @endif
        </div>
    </div>

    @if ($tabAktif === 'storage')
        <livewire:storage-accounts />
    @elseif ($tabAktif === 'pengingat')
        <livewire:pengingat-email />
        <livewire:pengaturan-whats-app />
        <livewire:pengaturan-pengingat />
        <livewire:pengaturan-penerima-pengingat />
        <livewire:pengaturan-template-pengingat />
    @elseif ($tabAktif === 'backup')
        <livewire:backup-database />
    @elseif ($tabAktif === 'log')
        <livewire:log-aktivitas-viewer lazy />
    @endif
@endsection
