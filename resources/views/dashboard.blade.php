@extends('layouts.app')

@section('title', 'Dasbor — SIPINTER')

@section('breadcrumb', 'Dasbor')

@section('content')
    @if (in_array(auth()->user()->namaRole(), ['Tim SAKIP', 'Kepala']))
        <div class="tabs-page" x-data="{ tab: 'utama' }">
            <div class="subtabs">
                <button type="button" class="subtab" :class="tab === 'utama' ? 'on' : ''" @click="tab = 'utama'">🏠 Dasbor</button>
                <button type="button" class="subtab" :class="tab === 'kinerja' ? 'on' : ''" @click="tab = 'kinerja'">📊 Dasbor Kinerja</button>
            </div>

            <div x-show="tab === 'utama'">
                <livewire:dasbor-utama />
            </div>
            <div x-show="tab === 'kinerja'" x-cloak>
                <livewire:dasbor-capaian />
            </div>
        </div>
    @else
        <livewire:dasbor-utama />
    @endif
@endsection
