@extends('layouts.app')

@section('title', 'Dasbor — SIPINTER')

@section('breadcrumb', 'Dasbor')

@section('content')
    @if (auth()->user()->namaRole() === 'Tim SAKIP')
        <div x-data="{ tab: 'utama' }">
            <div class="subtabs">
                <button type="button" class="subtab" :class="tab === 'utama' ? 'on' : ''" @click="tab = 'utama'">🏠 Dasbor</button>
                <button type="button" class="subtab" :class="tab === 'kinerja' ? 'on' : ''" @click="tab = 'kinerja'">📊 Dasbor Kinerja</button>
                <button type="button" class="subtab" :class="tab === 'rekap' ? 'on' : ''" @click="tab = 'rekap'">📈 Rekapitulasi</button>
            </div>

            <div x-show="tab === 'utama'">
                <livewire:dasbor-utama />
            </div>
            <div x-show="tab === 'kinerja'" x-cloak>
                <livewire:dasbor-capaian lazy />
            </div>
            <div x-show="tab === 'rekap'" x-cloak>
                <livewire:rekapitulasi lazy />
            </div>
        </div>
    @else
        <livewire:dasbor-utama />
    @endif
@endsection
