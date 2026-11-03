@extends('layouts.app')

@section('title', 'Verifikasi & Notula — SIPINTER')

@section('breadcrumb', 'Verifikasi & Notula')

@section('content')
    @php
        $tabAktif = $tabAktif ?? (request()->route('tab') ?? 'verifikasi');
        if (!in_array($tabAktif, ['verifikasi', 'kompilasi', 'template'])) {
            $tabAktif = 'verifikasi';
        }
    @endphp
    <div class="tabs-page" x-data="{
        tab: @js($tabAktif),
        pilihTab(t) {
            this.tab = t;
            history.replaceState(null, '', '{{ url('/verifikasi-notula') }}/' + t);
        }
    }">
        <div class="subtabs">
            <button type="button" class="subtab" :class="tab === 'verifikasi' ? 'on' : ''" @click="pilihTab('verifikasi')">✅ Verifikasi Capaian</button>
            <button type="button" class="subtab" :class="tab === 'kompilasi' ? 'on' : ''" @click="pilihTab('kompilasi')">📄 Kompilasi Notula</button>
            <button type="button" class="subtab" :class="tab === 'template' ? 'on' : ''" @click="pilihTab('template')">🗎 Template Notula</button>
        </div>

        <div x-show="tab === 'verifikasi'">
            <livewire:verifikasi-list />
        </div>
        <div x-show="tab === 'kompilasi'" x-cloak>
            <livewire:kompilasi-notula />
        </div>
        <div x-show="tab === 'template'" x-cloak>
            <livewire:template-notula />
        </div>
    </div>
@endsection
