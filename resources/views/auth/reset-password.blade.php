@extends('layouts.auth')

@section('title', 'Buat Kata Sandi Baru — SIPINTER')

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
            <h2>Buat kata sandi baru</h2>
            <p class="asub">Masukkan kata sandi baru untuk akun Anda.</p>

            @if ($errors->any())
                <div class="badge b-tunggu" style="display:block;margin-bottom:14px">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('password.update') }}" x-data="{ showPw: false, showConfirm: false }">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="field">
                    <label>Email <span class="req">*</span></label>
                    <input class="inp filled" type="email" name="email" value="{{ old('email', $email) }}" placeholder="email akun Anda" required autofocus>
                </div>
                <div class="field">
                    <label>Kata Sandi Baru <span class="req">*</span></label>
                    <div class="pw-wrap">
                        <input class="inp filled" :type="showPw ? 'text' : 'password'" name="password" placeholder="Minimal 8 karakter" required>
                        <button type="button" class="eye" @click="showPw = !showPw" tabindex="-1">
                            <span x-text="showPw ? '🙈' : '👁'"></span>
                        </button>
                    </div>
                </div>
                <div class="field">
                    <label>Konfirmasi Kata Sandi <span class="req">*</span></label>
                    <div class="pw-wrap">
                        <input class="inp filled" :type="showConfirm ? 'text' : 'password'" name="password_confirmation" placeholder="Ulangi kata sandi baru" required>
                        <button type="button" class="eye" @click="showConfirm = !showConfirm" tabindex="-1">
                            <span x-text="showConfirm ? '🙈' : '👁'"></span>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary auth-btn">Ubah Kata Sandi →</button>
            </form>

            <div class="auth-alt"><a wire:navigate href="{{ route('login') }}">← Kembali ke halaman masuk</a></div>
        </div>
    </div>
@endsection
