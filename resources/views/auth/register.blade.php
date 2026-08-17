@extends('layouts.auth')

@section('title', 'Daftar Akun — SIPINTER')

@section('content')
    <div class="auth">
        <div class="auth-visual">
            <div class="av-logo">📊</div>
            <h1>Bergabung</h1>
            <p class="avp">Daftarkan akun untuk mulai melaporkan kinerja</p>
            <div class="av-points">
                <div class="av-pt">✓ Satu akun untuk seluruh proses pelaporan</div>
                <div class="av-pt">✓ Akses sesuai peran Anda di tim</div>
                <div class="av-pt">✓ Data tersimpan aman &amp; terpusat</div>
            </div>
            <div class="av-foot">📍 BPS Kabupaten Buton Utara · Sulawesi Tenggara</div>
        </div>

        <div class="auth-form">
            <div class="amini">📊 SIPINTER</div>
            <h2>Buat akun baru</h2>
            <p class="asub">Akun akan aktif setelah disetujui Tim SAKIP.</p>

            @if ($errors->any())
                <div class="badge b-tunggu" style="display:block;margin-bottom:14px">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('register') }}" x-data="{ showPw: false, showConfirm: false }">
                @csrf
                <div class="field">
                    <label>Nama Lengkap <span class="req">*</span></label>
                    <input class="inp filled" type="text" name="nama" value="{{ old('nama') }}" placeholder="Nama sesuai kepegawaian" required autofocus>
                </div>
                <div class="row2">
                    <div class="field">
                        <label>Username <span class="req">*</span></label>
                        <input class="inp filled" type="text" name="username" value="{{ old('username') }}" placeholder="username tanpa spasi" required>
                    </div>
                    <div class="field">
                        <label>Peran yang Diajukan <span class="req">*</span></label>
                        <select class="inp sel filled" name="role_id" required>
                            <option value="">— Pilih Peran —</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>{{ $role->nama }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label>Kata Sandi <span class="req">*</span></label>
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
                        <input class="inp filled" :type="showConfirm ? 'text' : 'password'" name="password_confirmation" placeholder="Ulangi kata sandi" required>
                        <button type="button" class="eye" @click="showConfirm = !showConfirm" tabindex="-1">
                            <span x-text="showConfirm ? '🙈' : '👁'"></span>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary auth-btn">Daftar →</button>
            </form>

            <div class="auth-alt">Sudah punya akun? <a wire:navigate href="{{ route('login') }}">Masuk di sini</a></div>
        </div>
    </div>
@endsection
