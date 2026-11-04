@extends('layouts.app')

@php
    $ruteTab = request()->route('tab');
    $isKinerja = in_array($ruteTab, ['kinerja', 'capaian-kinerja']);
    $judulSub = $isKinerja ? 'Capaian Kinerja' : 'Pemantauan Pengisian';
@endphp

@section('title', 'Dasbor ' . (in_array(auth()->user()->namaRole(), ['Tim SAKIP', 'Kepala']) ? $judulSub . ' — ' : '— ') . 'SIPINTER')

@section('breadcrumb')
    Dasbor @if (in_array(auth()->user()->namaRole(), ['Tim SAKIP', 'Kepala'])) › <b>{{ $judulSub }}</b> @endif
@endsection

@section('content')
    @if (in_array(auth()->user()->namaRole(), ['Tim SAKIP', 'Kepala']))
        @if ($isKinerja)
            <livewire:dasbor-capaian />
        @else
            <livewire:dasbor-utama />
        @endif
    @else
        <livewire:dasbor-utama />
    @endif
@endsection
