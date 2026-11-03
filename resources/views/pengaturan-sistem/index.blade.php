@extends('layouts.app')

@section('title', 'Sistem & Integrasi — SIPINTER')

@section('breadcrumb', 'Sistem & Integrasi')

@section('content')
    @php
        $tabAktif = $tabAktif ?? (request()->route('tab') ?? 'storage');
        if (!in_array($tabAktif, ['storage', 'pengingat', 'backup', 'log'])) {
            $tabAktif = 'storage';
        }
    @endphp

    <div class="tabs-page-after-title" x-data="{
        tab: @js($tabAktif),
        pilihTab(t) {
            this.tab = t;
            history.replaceState(null, '', '{{ url('/pengaturan-sistem') }}/' + t);
        }
    }">
        <div class="page-head-group">
            <div class="page-head">
                <div class="page-title">Sistem &amp; Integrasi</div>
                <div class="page-sub">Pengaturan akun penyimpanan Google Drive, otomasi pengingat, cadangan basis data, dan riwayat log aktivitas.</div>
            </div>
            <div class="subtabs">
                <button type="button" class="subtab" :class="tab === 'storage' ? 'on' : ''" @click="pilihTab('storage')">☁️ Akun &amp; Storage</button>
                <button type="button" class="subtab" :class="tab === 'pengingat' ? 'on' : ''" @click="pilihTab('pengingat')">📧 Pengingat &amp; Notifikasi</button>
                <button type="button" class="subtab" :class="tab === 'backup' ? 'on' : ''" @click="pilihTab('backup')">💾 Cadangan Data</button>
                <button type="button" class="subtab" :class="tab === 'log' ? 'on' : ''" @click="pilihTab('log')">📜 Log Aktivitas</button>
            </div>
        </div>

        <div x-show="tab === 'storage'">
            <livewire:storage-accounts />
        </div>

        <div x-show="tab === 'pengingat'" x-cloak>
            <div class="page-head" style="position:static;background:none">
                <div class="page-title">Pengingat &amp; Notifikasi</div>
                <div class="page-sub">Pengingat otomatis (tenggat IKU, verifikasi, notula, dsb) dikirim lewat email, dan juga lewat WhatsApp kantor bagi pengguna yang menyetujuinya.</div>
            </div>

            <livewire:pengingat-email />

            <livewire:pengaturan-whats-app />

            <livewire:pengaturan-pengingat />

            <livewire:pengaturan-penerima-pengingat />

            <livewire:pengaturan-template-pengingat />
        </div>

        <div x-show="tab === 'backup'" x-cloak>
            <livewire:backup-database />
        </div>

        <div x-show="tab === 'log'" x-cloak>
            <livewire:log-aktivitas-viewer lazy />
        </div>
    </div>
@endsection
