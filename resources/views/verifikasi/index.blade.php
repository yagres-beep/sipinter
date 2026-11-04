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
    @if ($tabAktif === 'verifikasi')
        <livewire:verifikasi-list />
    @elseif ($tabAktif === 'kompilasi')
        <livewire:kompilasi-notula />
    @elseif ($tabAktif === 'template')
        <livewire:template-notula />
    @endif
@endsection
