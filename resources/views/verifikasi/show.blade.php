@extends('layouts.app')

@section('title', 'Verifikasi Capaian — SIPINTER')

@section('breadcrumb', 'Detail Verifikasi')

@section('content')
    <livewire:verifikasi-capaian :capaian="$capaian" />
@endsection
