@extends('layouts.auth')

@section('title', 'Lupa Sandi — SIPINTER')

@section('content')
    <div class="auth">
        <div class="auth-visual">
            <div class="av-logo">📊</div>
            <h1>SIPINTER</h1>
            <p class="avp">Sistem Informasi Pelaporan Kinerja Terpadu</p>
            <div class="av-points">
                <div class="av-pt">✓ Catat capaian kinerja SAKIP triwulanan</div>
                <div class="av-pt">✓ Kelola &amp; verifikasi bukti dukung otomatis</div>
                <div class="av-pt">✓ Susun notula &amp; pantau lewat dasbor</div>
            </div>
            <div class="av-foot">📍 BPS Kabupaten Buton Utara · Sulawesi Tenggara</div>
        </div>

        <div class="auth-form">
            <div class="amini">📊 SIPINTER</div>
            <h2>Lupa kata sandi?</h2>
            <p class="asub">Masukkan email akun Anda, kami akan mengirimkan tautan untuk membuat kata sandi baru.</p>

            @if (session('status'))
                <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="badge b-tunggu" style="display:block;margin-bottom:14px">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('password.email') }}">
                @csrf
                <div class="field">
                    <label>Email <span class="req">*</span></label>
                    <input class="inp filled" type="email" name="email" value="{{ old('email') }}" placeholder="email akun Anda" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary auth-btn">Kirim Tautan Reset →</button>
            </form>

            <div class="auth-alt"><a wire:navigate href="{{ route('login') }}">← Kembali ke halaman masuk</a></div>
        </div>
    </div>
@endsection
