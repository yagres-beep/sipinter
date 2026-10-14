@extends('layouts.auth')

{{-- Halaman publik (tanpa login) — tautannya dipasang di layar persetujuan OAuth
     Google (Google Auth Platform > Branding), yang mewajibkan keduanya saat aplikasi
     ber-logo dipublikasikan ke status "In production". --}}
@section('content')
    <article class="legal">
        <div class="amini">📊 SIPINTER</div>
        <h1>@yield('judul')</h1>
        <p class="legal-meta">Berlaku sejak 14 Oktober 2026 · BPS Kabupaten Buton Utara</p>

        @yield('isi')

        <div class="auth-alt">
            <a href="{{ route('kebijakan-privasi') }}">Kebijakan Privasi</a> ·
            <a href="{{ route('ketentuan-layanan') }}">Ketentuan Layanan</a> ·
            <a href="{{ route('login') }}">Masuk ke SIPINTER</a>
        </div>
    </article>
@endsection
