@extends('layouts.app')

@section('title', 'Isian Capaian — SIPINTER')

@section('breadcrumb')
    Isian Kegiatan › <b>Isian Capaian</b>
@endsection

@section('content')
    <div class="subtabs" style="margin-bottom:16px">
        <a wire:navigate href="{{ route('pengisian.index') }}" class="subtab on">
            <span>📝 Isian Capaian</span>
        </a>
        <a wire:navigate href="{{ route('rtl-evaluasi.index') }}" class="subtab">
            <span>📋 RTL &amp; Evaluasi</span>
        </a>
    </div>

    <livewire:pengisian-kegiatan />
@endsection
