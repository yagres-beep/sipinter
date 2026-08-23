@extends('layouts.app')

@section('title', 'Rekap Kinerja Tahunan '.$lakin->tahun.' — SIPINTER')

@section('breadcrumb', 'Rekap Kinerja Tahunan '.$lakin->tahun)

@section('content')
    <livewire:lakin-detail :lakin="$lakin" />
@endsection
