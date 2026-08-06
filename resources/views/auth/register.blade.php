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

            <form method="POST" action="{{ route('register') }}">
                @csrf
                <div class="field">
                    <label>Nama Lengkap <span class="req">*</span></label>
                    <input class="inp filled" type="text" name="nama" value="{{ old('nama') }}" placeholder="Nama sesuai kepegawaian" required autofocus>
                </div>
                <div class="row2">
                    <div class="field">
                        <label>Email <span class="req">*</span></label>
                        <input class="inp filled" type="email" name="email" value="{{ old('email') }}" placeholder="nama@bps.go.id" required>
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
                        <input class="inp filled" type="password" name="password" placeholder="Minimal 8 karakter" required>
                    </div>
                </div>
                <div class="field">
                    <label>Konfirmasi Kata Sandi <span class="req">*</span></label>
                    <div class="pw-wrap">
                        <input class="inp filled" type="password" name="password_confirmation" placeholder="Ulangi kata sandi" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary auth-btn">Daftar →</button>
            </form>

            <div class="auth-alt">Sudah punya akun? <a href="{{ route('login') }}">Masuk di sini</a></div>
        </div>
    </div>
@endsection
