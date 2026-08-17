@extends('layouts.app')

@section('title', 'Profil Saya — SIPINTER')

@section('breadcrumb', 'Pengaturan Akun')

@section('content')
    <div class="page-title">Pengaturan Akun</div>
    <div class="page-sub">Kelola profil dan kata sandi Anda.</div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="grid grid-2">
        <div class="card">
            <div class="sec"><span>👤 Profil</span></div>

            @if ($errors->updateProfile->any())
                <div class="badge b-tunggu" style="display:block;margin-bottom:14px">{{ $errors->updateProfile->first() }}</div>
            @endif

            <form method="POST" action="{{ route('profile.update') }}">
                @csrf
                @method('PATCH')
                <div class="field">
                    <label>Nama Lengkap <span class="req">*</span></label>
                    <input class="inp filled" type="text" name="nama" value="{{ old('nama', $user->nama) }}" required>
                </div>
                <div class="field">
                    <label>Username <span class="req">*</span></label>
                    <input class="inp filled" type="text" name="username" value="{{ old('username', $user->username) }}" required>
                </div>
                <div class="field">
                    <label>Peran</label>
                    <div class="inp ro">{{ $user->role->nama }}</div>
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn btn-primary">💾 Simpan Profil</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="sec"><span>🔒 Ubah Kata Sandi</span></div>

            @if ($errors->updatePassword->any())
                <div class="badge b-tunggu" style="display:block;margin-bottom:14px">{{ $errors->updatePassword->first() }}</div>
            @endif

            <form method="POST" action="{{ route('profile.password') }}" x-data="{ showCurrent: false, showNew: false, showConfirm: false }">
                @csrf
                @method('PUT')
                <div class="field">
                    <label>Sandi Saat Ini <span class="req">*</span></label>
                    <div class="pw-wrap">
                        <input class="inp filled" :type="showCurrent ? 'text' : 'password'" name="current_password" placeholder="Masukkan sandi lama" required>
                        <button type="button" class="eye" @click="showCurrent = !showCurrent" tabindex="-1">
                            <span x-text="showCurrent ? '🙈' : '👁'"></span>
                        </button>
                    </div>
                </div>
                <div class="field">
                    <label>Sandi Baru <span class="req">*</span></label>
                    <div class="pw-wrap">
                        <input class="inp filled" :type="showNew ? 'text' : 'password'" name="password" placeholder="Minimal 8 karakter" required>
                        <button type="button" class="eye" @click="showNew = !showNew" tabindex="-1">
                            <span x-text="showNew ? '🙈' : '👁'"></span>
                        </button>
                    </div>
                </div>
                <div class="field">
                    <label>Ulangi Sandi Baru <span class="req">*</span></label>
                    <div class="pw-wrap">
                        <input class="inp filled" :type="showConfirm ? 'text' : 'password'" name="password_confirmation" placeholder="Ketik ulang sandi baru" required>
                        <button type="button" class="eye" @click="showConfirm = !showConfirm" tabindex="-1">
                            <span x-text="showConfirm ? '🙈' : '👁'"></span>
                        </button>
                    </div>
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn btn-navy">🔒 Perbarui Sandi</button>
                </div>
            </form>
        </div>
    </div>
@endsection
