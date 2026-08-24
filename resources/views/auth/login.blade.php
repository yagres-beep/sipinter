@extends('layouts.auth')

@section('title', 'Masuk — SIPINTER')

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
            <h2>Masuk ke akun Anda</h2>
            <p class="asub">Selamat datang kembali. Silakan masuk untuk melanjutkan.</p>

            @if (session('status'))
                <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="badge b-tunggu" style="display:block;margin-bottom:14px">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login') }}" x-data="{ showPw: false }">
                @csrf
                <div class="field">
                    <label>Username <span class="req">*</span></label>
                    <input class="inp filled" type="text" name="username" value="{{ old('username') }}" placeholder="username Anda" required autofocus>
                </div>
                <div class="field">
                    <label>Kata Sandi <span class="req">*</span></label>
                    <div class="pw-wrap">
                        <input class="inp filled" :type="showPw ? 'text' : 'password'" name="password" placeholder="••••••••" required>
                        <button type="button" class="eye" @click="showPw = !showPw" tabindex="-1">
                            <span x-text="showPw ? '🙈' : '👁'"></span>
                        </button>
                    </div>
                </div>
                <div class="auth-row">
                    <label class="chk"><input type="checkbox" name="remember" style="width:auto"> Ingat saya</label>
                    <a wire:navigate href="{{ route('password.request') }}">Lupa sandi?</a>
                </div>
                <button type="submit" class="btn btn-primary auth-btn">Masuk →</button>
            </form>

            <div class="auth-alt">Belum punya akun? <a wire:navigate href="{{ route('register') }}">Daftar di sini</a></div>
        </div>
    </div>
@endsection
