@extends('layouts.app')

@section('title', 'RTL & Evaluasi — SIPINTER')

@section('breadcrumb')
    Isian Kegiatan › <b>RTL &amp; Evaluasi</b>
@endsection

@section('content')
    <div class="subtabs" style="margin-bottom:16px">
        <a wire:navigate href="{{ route('pengisian.index') }}" class="subtab">
            <span>📝 Isian Capaian</span>
        </a>
        <a wire:navigate href="{{ route('rtl-evaluasi.index') }}" class="subtab on">
            <span>📋 RTL &amp; Evaluasi</span>
        </a>
    </div>

    <livewire:rtl-evaluasi />
@endsection
