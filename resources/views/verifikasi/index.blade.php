@extends('layouts.app')

@section('title', 'Verifikasi — SIPINTER')

@section('breadcrumb', 'Verifikasi & Notula')

@section('content')
    <div class="tabs-page" x-data="{ tab: 'verifikasi' }">
        <div class="subtabs">
            <button type="button" class="subtab" :class="tab === 'verifikasi' ? 'on' : ''" @click="tab = 'verifikasi'">✅ Verifikasi</button>
            <button type="button" class="subtab" :class="tab === 'notula' ? 'on' : ''" @click="tab = 'notula'">📄 Kompilasi Notula</button>
            <button type="button" class="subtab" :class="tab === 'template' ? 'on' : ''" @click="tab = 'template'">🗎 Template Notula</button>
        </div>

        <div x-show="tab === 'verifikasi'">
            <livewire:verifikasi-list />
        </div>
        <div x-show="tab === 'notula'" x-cloak>
            <livewire:kompilasi-notula />
        </div>
        <div x-show="tab === 'template'" x-cloak>
            <livewire:template-notula />
        </div>
    </div>
@endsection
