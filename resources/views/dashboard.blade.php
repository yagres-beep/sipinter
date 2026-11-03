@extends('layouts.app')

@section('title', 'Dasbor — SIPINTER')

@section('breadcrumb', 'Dasbor')

@section('content')
    @if (in_array(auth()->user()->namaRole(), ['Tim SAKIP', 'Kepala']))
        @php
            $tabAktif = $tabAktif ?? (request()->route('tab') === 'capaian-kinerja' || request()->route('tab') === 'kinerja' ? 'kinerja' : 'pemantauan');
        @endphp
        <div class="tabs-page" x-data="{
            tab: @js($tabAktif),
            pilihTab(t) {
                this.tab = t;
                const path = t === 'kinerja' ? '{{ url('/dasbor/capaian-kinerja') }}' : '{{ url('/dasbor/pemantauan') }}';
                history.replaceState(null, '', path);
            }
        }">
            <div class="subtabs">
                <button type="button" class="subtab" :class="tab === 'pemantauan' ? 'on' : ''" @click="pilihTab('pemantauan')">📋 Pemantauan Pengisian</button>
                <button type="button" class="subtab" :class="tab === 'kinerja' ? 'on' : ''" @click="pilihTab('kinerja')">📊 Capaian Kinerja</button>
            </div>

            <div x-show="tab === 'pemantauan'">
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
