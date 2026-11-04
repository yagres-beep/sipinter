@extends('layouts.app')

@php
    $tabAktif = $tabAktif ?? (request()->route('tab') === 'penugasan' ? 'penugasan' : 'verifikasi');
    $judulSub = $tabAktif === 'penugasan' ? 'Penugasan IKU' : 'Verifikasi & Akun';
@endphp

@section('title', $judulSub . ' — SIPINTER')

@section('breadcrumb')
    Pengguna &amp; Tim › <b>{{ $judulSub }}</b>
@endsection

@section('content')
    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="page-head" style="margin-bottom:18px">
        <div class="page-title">{{ $judulSub }}</div>
        <div class="page-sub">
            @if ($tabAktif === 'penugasan')
                Penugasan indikator kinerja utama (IKU) ke tim kerja dan penanggung jawab.
            @else
                Verifikasi pendaftaran akun baru, persetujuan peran, dan daftar akun aktif.
            @endif
        </div>
    </div>

    @if ($tabAktif === 'verifikasi')
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
    @elseif ($tabAktif === 'penugasan')
        <livewire:penugasan-iku />
    @endif
@endsection
