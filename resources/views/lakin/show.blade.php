@extends('layouts.app')

@section('title', 'LAKIN '.$lakin->tahun.' — SIPINTER')

@section('breadcrumb', 'LAKIN '.$lakin->tahun)

@section('content')
    <livewire:lakin-detail :lakin="$lakin" />
@endsection
