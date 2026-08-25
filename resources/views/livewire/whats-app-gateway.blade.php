<div wire:poll.3s>
    <div class="page-head">
        <div class="page-title">Pengingat WhatsApp</div>
        <div class="page-sub">Kelola nomor WhatsApp yang dipakai gateway untuk mengirim pengingat otomatis (tenggat IKU, verifikasi, notula, dsb).</div>
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="info red" style="margin-bottom:14px">⚠️ {{ session('error') }}</div>
    @endif

    <div class="card">
        <div class="card-h">📱 Status Tautan</div>

        @if ($gatewayStatus === 'connected')
            <div class="info" style="border-color:var(--teal, #0d9488)">
                ✅ Gateway <b>terhubung</b> — pengingat WA aktif dikirim lewat nomor yang sedang tertaut.
            </div>

            <div class="btn-row">
                <button type="button" class="btn btn-red" wire:click="resetSesi"
                        wire:confirm="Putuskan nomor yang sedang tertaut? Pengingat WA berhenti terkirim sampai nomor baru discan.">
                    🔌 Putus Tautan / Ganti Nomor
                </button>
            </div>
        @elseif ($gatewayStatus === 'waiting_for_qr' && $qrDataUrl)
            <div class="info">📷 Scan QR ini pakai WhatsApp di HP yang ingin dipakai mengirim pengingat: <b>Setelan → Perangkat Tertaut → Tautkan Perangkat</b>.</div>

            <div style="display:flex;justify-content:center;margin:16px 0">
                <img src="{{ $qrDataUrl }}" alt="QR WhatsApp" style="max-width:260px;width:100%;border:1px solid var(--border,#e5e7eb);border-radius:8px">
            </div>

            <p style="color:var(--muted);font-size:12.5px;text-align:center;margin:0">Halaman ini otomatis memperbarui diri begitu berhasil discan.</p>
        @elseif ($gatewayStatus === 'error')
            <div class="info red">
                ⚠️ Tidak bisa menghubungi gateway WhatsApp. Pastikan <code>WHATSAPP_API_URL</code>/<code>WHATSAPP_API_TOKEN</code> sudah diisi benar di konfigurasi server dan gateway sedang menyala.
            </div>
        @else
            <div class="info">⏳ Menyambungkan ke gateway, mohon tunggu… (status: <code>{{ $gatewayStatus }}</code>)</div>
        @endif
    </div>
</div>
