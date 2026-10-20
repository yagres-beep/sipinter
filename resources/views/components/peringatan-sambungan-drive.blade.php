{{--
    Banner untuk SEMUA role saat akun storage AKTIF tidak tersambung ke Google Drive
    (lihat StorageAccount::masalahSambunganAktif()). Tim SAKIP mendapat tombol
    langsung ke menu Akun & Storage; role lain diminta mengingatkan Tim SAKIP.
--}}
@php
    $masalahDrive = \App\Models\StorageAccount::masalahSambunganAktif();
    $timSakip = auth()->user()?->namaRole() === 'Tim SAKIP';
@endphp

@if ($masalahDrive)
    @php
        $akunDrive = $masalahDrive['akun'];
        $judulDrive = match (true) {
            $akunDrive === null => 'Belum ada akun Google Drive aktif',
            $masalahDrive['status'] === \App\Models\StorageAccount::GOOGLE_BELUM => "Akun Google Drive {$akunDrive->email_gmail_institusi} belum disambungkan",
            default => "Sambungan Google Drive ({$akunDrive->email_gmail_institusi}) terputus",
        };
    @endphp
    <div class="info red" role="alert" style="margin-bottom:16px;align-items:center;flex-wrap:wrap">
        <span style="font-size:16px">⛔</span>
        <div style="flex:1;min-width:220px">
            <b>{{ $judulDrive }}.</b>
            Unggahan bukti dukung ke Google Drive sedang terhenti — berkas yang diunggah tetap tersimpan sementara
            dan dikirim ke Drive otomatis setelah sambungan pulih.
            @unless ($timSakip)
                <b>Mohon ingatkan Tim SAKIP untuk menyambungkan ulang akun Google di menu Akun &amp; Storage.</b>
            @endunless
        </div>
        @if ($timSakip && ! request()->routeIs('storage-accounts.*'))
            <a wire:navigate href="{{ route('storage-accounts.index') }}" class="btn btn-primary btn-sm">🔗 Sambungkan ulang</a>
        @endif
    </div>
@endif
