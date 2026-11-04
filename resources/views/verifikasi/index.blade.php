@extends('layouts.app')

@php
    $tabAktif = $tabAktif ?? (request()->route('tab') ?? 'verifikasi');
    if (!in_array($tabAktif, ['verifikasi', 'kompilasi', 'template'])) {
        $tabAktif = 'verifikasi';
    }
    $judulSub = match ($tabAktif) {
        'kompilasi' => 'Kompilasi Notula',
        'template' => 'Template Notula',
        default => 'Verifikasi Capaian',
    };
@endphp

@section('title', $judulSub . ' — SIPINTER')

@section('breadcrumb')
    Verifikasi &amp; Notula › <b>{{ $judulSub }}</b>
@endsection

@section('content')
    <div class="subtabs" style="margin-bottom:16px">
        <a wire:navigate href="{{ route('verifikasi.index', ['tab' => 'verifikasi']) }}" class="subtab {{ $tabAktif === 'verifikasi' ? 'on' : '' }}">
            <span>✅ Verifikasi Capaian</span>
        </a>
        <a wire:navigate href="{{ route('verifikasi.index', ['tab' => 'kompilasi']) }}" class="subtab {{ $tabAktif === 'kompilasi' ? 'on' : '' }}">
            <span>📝 Kompilasi Notula</span>
        </a>
        <a wire:navigate href="{{ route('verifikasi.index', ['tab' => 'template']) }}" class="subtab {{ $tabAktif === 'template' ? 'on' : '' }}">
            <span>📋 Template Notula</span>
        </a>
    </div>

    @if ($tabAktif === 'verifikasi')
        <livewire:verifikasi-list />
    @elseif ($tabAktif === 'kompilasi')
        <livewire:kompilasi-notula />
    @elseif ($tabAktif === 'template')
        <livewire:template-notula />
    @endif
@endsection
