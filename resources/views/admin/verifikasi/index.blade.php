@extends('layouts.app')

@section('title', 'Pengguna & Tim — SIPINTER')

@section('breadcrumb', 'Pengguna & Tim')

@section('content')
    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    @php
        $tabAktif = $tabAktif ?? (request()->route('tab') === 'penugasan' ? 'penugasan' : 'verifikasi');
    @endphp

    <div class="tabs-page-after-title" x-data="{
        tab: @js($tabAktif),
        pilihTab(t) {
            this.tab = t;
            history.replaceState(null, '', '{{ url('/kelola-pengguna') }}/' + t);
        }
    }">
        <div class="page-head-group">
            <div class="page-head">
                <div class="page-title">Pengguna &amp; Tim</div>
                <div class="page-sub">Verifikasi pendaftaran akun baru, akun aktif, keanggotaan tim kerja, dan penugasan IKU.</div>
            </div>
            <div class="subtabs">
                <button type="button" class="subtab" :class="tab === 'verifikasi' ? 'on' : ''" @click="pilihTab('verifikasi')">✅ Verifikasi &amp; Akun</button>
                <button type="button" class="subtab" :class="tab === 'penugasan' ? 'on' : ''" @click="pilihTab('penugasan')">📋 Penugasan IKU</button>
            </div>
        </div>

        <div x-show="tab === 'verifikasi'">
            <div class="info">ℹ️ Akun yang baru mendaftar berstatus menunggu. Tim SAKIP memeriksa lalu menyetujui/menolak. Akun hanya bisa dipakai setelah disetujui.</div>

            <div class="card">
                <div class="sec"><span>Menunggu Persetujuan</span> <span class="badge b-tunggu">{{ $pending->count() }}</span></div>
                <table>
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Username</th>
                            <th>Peran Diajukan</th>
                            <th>Tim Diajukan</th>
                            <th style="text-align:right">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pending as $user)
                            <tr>
                                <td><b>{{ $user->nama }}</b></td>
                                <td class="muted">{{ $user->username }}</td>
                                <td><span class="badge b-ajukan">{{ $user->role->nama }}</span></td>
                                <td class="muted">
                                    @forelse ($user->timList as $anggota)
                                        <span class="chip chip-tim" style="margin:2px">{{ $anggota->tim }}</span>
                                    @empty
                                        —
                                    @endforelse
                                </td>
                                <td style="text-align:right">
                                    <div class="btn-row" style="margin-top:0;justify-content:flex-end">
                                        <form method="POST" action="{{ route('verifikasi-akun.approve', $user) }}" style="display:inline">
                                            @csrf
                                            <button type="submit" class="btn btn-teal btn-sm">✓ Setujui</button>
                                        </form>
                                        <form method="POST" action="{{ route('verifikasi-akun.reject', $user) }}" style="display:inline">
                                            @csrf
                                            <button type="submit" class="btn btn-red btn-sm">✕ Tolak</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" style="color:var(--muted)">Tidak ada pendaftaran yang menunggu.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="card" style="margin-top:16px">
                <div class="sec"><span>Akun Aktif</span></div>
                <livewire:akun-aktif />
            </div>
        </div>

        <div x-show="tab === 'penugasan'" x-cloak>
            <livewire:penugasan-iku />
        </div>
    </div>
@endsection
