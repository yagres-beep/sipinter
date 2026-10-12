@php
    $label = [
        'starting' => ['⏳', 'Gateway baru menyala'],
        'waiting_lock' => ['⏳', 'Menunggu instance gateway lama berhenti'],
        'no_session' => ['⚪', 'Belum terhubung'],
        'connecting' => ['⏳', 'Menghubungkan'],
        'waiting_qr' => ['📷', 'Menunggu QR dipindai'],
        'connected' => ['✅', 'Terhubung'],
        'reconnecting' => ['🔄', 'Menghubungkan ulang'],
        'logged_out' => ['⚠️', 'Perangkat dikeluarkan — perlu pindai QR ulang'],
        'conflict' => ['⚠️', 'Sesi dipakai di tempat lain'],
        'failed' => ['❌', 'Gagal tersambung'],
        'stopped' => ['⏹️', 'Gateway berhenti'],
    ];
    [$ikon, $teksStatus] = $label[$status] ?? ['❔', $status ?? 'Belum diperiksa'];
    $bisaHubungkan = in_array($status, ['no_session', 'logged_out', 'failed', 'conflict', 'stopped'], true);
@endphp

<div x-data x-intersect.once="$wire.saatTabTerlihat()"
     @if ($perluPolling) wire:poll.3s="segarkan" @else wire:poll.30s.visible @endif>
    @if (session('status-wa'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status-wa') }}</div>
    @endif
    @if (session('error-wa'))
        <div class="info red" style="margin-bottom:14px">⚠️ {{ session('error-wa') }}</div>
    @endif

    <div class="card">
        <div class="card-h">📱 Koneksi WhatsApp Kantor</div>

        <div class="info">
            ℹ️ Pengingat dikirim dari <b>satu nomor WhatsApp kantor</b> lewat gateway tidak resmi (Baileys) di Render Free.
            Gateway bisa tidur saat tidak dipakai; pesan menunggu di antrean dan terkirim setelah gateway bangun.
            WhatsApp dapat memutus atau membatasi nomor yang memakai cara ini, jadi email tetap menjadi jalur utama.
        </div>

        @unless ($terkonfigurasi)
            <div class="info red">⚠️ <code>WHATSAPP_GATEWAY_URL</code>/<code>WHATSAPP_GATEWAY_TOKEN</code> belum diisi di environment server SIPINTER.</div>
        @endunless

        <div style="display:flex;flex-wrap:wrap;gap:18px;margin:10px 0 4px;font-size:13px">
            <div>Status: <b>{{ $ikon }} {{ $teksStatus }}</b></div>
            @if ($statusGateway['me'] ?? null)
                <div>Nomor tertaut: <b>{{ $statusGateway['me'] }}</b></div>
            @endif
            <div>Pengingat otomatis: <b>{{ $pengingatAktif ? 'aktif' : 'nonaktif (WHATSAPP_PENGINGAT_AKTIF=false)' }}</b></div>
            <div>Pengguna yang setuju menerima WA: <b>{{ $jumlahSetuju }}</b></div>
        </div>

        @if ($statusGateway['outbox'] ?? null)
            <div class="muted" style="font-size:12px">Antrean gateway: {{ $statusGateway['outbox']['queued'] }} menunggu, {{ $statusGateway['outbox']['unknown'] }} tidak pasti.</div>
        @endif

        @if ($galatGateway)
            <div class="info red" style="margin-top:10px">⚠️ {{ $galatGateway }}</div>
        @endif

        @if (($statusGateway['last_disconnect']['reason'] ?? null) && $status !== 'connected')
            <div class="muted" style="font-size:12px;margin-top:6px">Terakhir terputus: {{ $statusGateway['last_disconnect']['reason'] }}</div>
        @endif

        @if ($status === 'reconnecting' && ($statusGateway['next_retry_at'] ?? null))
            <div class="muted" style="font-size:12px;margin-top:6px">Percobaan ke-{{ $statusGateway['reconnect_attempts'] }}; dicoba lagi otomatis {{ \Illuminate\Support\Carbon::parse($statusGateway['next_retry_at'])->wita()->format('H:i:s') }} WITA.</div>
        @endif

        @if ($status === 'waiting_qr' && $qrDataUrl)
            <div class="info" style="margin-top:12px">📷 Di HP nomor kantor: <b>WhatsApp → Setelan → Perangkat tertaut → Tautkan perangkat</b>, lalu pindai QR ini. QR diperbarui otomatis; jangan bagikan QR ini.</div>
            <div style="display:flex;justify-content:center;margin:12px 0">
                <img src="{{ $qrDataUrl }}" alt="QR WhatsApp" style="max-width:260px;width:100%;border:1px solid var(--border,#e5e7eb);border-radius:8px;background:#fff">
            </div>
        @elseif ($status === 'waiting_qr')
            <div class="info" style="margin-top:12px">⏳ Menunggu QR dari WhatsApp…</div>
        @endif

        @if ($kodePairing)
            <div class="info" style="margin-top:12px">🔢 Kode pairing: <b style="font-size:18px;letter-spacing:2px">{{ $kodePairing }}</b><br>
                Di HP nomor kantor: <b>Perangkat tertaut → Tautkan perangkat → Tautkan dengan nomor telepon</b>, lalu masukkan kode ini dalam ±2 menit.</div>
        @endif

        <div class="btn-row" style="margin-top:12px;flex-wrap:wrap">
            <button type="button" class="btn btn-ghost" wire:click="periksa" wire:loading.attr="disabled" wire:target="periksa,saatTabTerlihat">
                <span wire:loading.remove wire:target="periksa,saatTabTerlihat">🔍 Periksa Koneksi</span>
                <span wire:loading wire:target="periksa,saatTabTerlihat"><i class="spin"></i> Memeriksa (bisa ±1 menit bila gateway tidur)…</span>
            </button>

            @if ($bisaHubungkan || ! $sudahDiperiksa || $galatGateway)
                <button type="button" class="btn btn-primary" wire:click="hubungkan" wire:loading.attr="disabled" wire:target="hubungkan">
                    <span wire:loading.remove wire:target="hubungkan">🔗 {{ ($statusGateway['session_present'] ?? false) ? 'Hubungkan Ulang' : 'Hubungkan WhatsApp / Tampilkan QR' }}</span>
                    <span wire:loading wire:target="hubungkan"><i class="spin"></i> Menghubungkan…</span>
                </button>
            @endif

            @if ($status === 'connected' || ($statusGateway['session_present'] ?? false) || in_array($status, ['failed', 'conflict'], true))
                <button type="button" class="btn btn-red" wire:click="mintaPutus">🔌 Putuskan Koneksi</button>
            @endif
        </div>

        @if (in_array($status, ['no_session', 'logged_out', 'waiting_qr'], true))
            <details style="margin-top:12px">
                <summary style="cursor:pointer;font-size:12.5px">QR sulit dipindai? Pakai kode pairing</summary>
                <div class="field" style="max-width:280px;margin-top:8px">
                    <label>Nomor WhatsApp kantor</label>
                    <input type="text" class="inp filled" style="width:100%" wire:model="nomorPairing" placeholder="08xxxxxxxxxx">
                    @error('nomorPairing')
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="mintaKodePairing" wire:loading.attr="disabled" wire:target="mintaKodePairing">
                    <span wire:loading.remove wire:target="mintaKodePairing">Minta Kode Pairing</span>
                    <span wire:loading wire:target="mintaKodePairing"><i class="spin"></i> Meminta…</span>
                </button>
            </details>
        @endif

        <x-confirm-modal :show="$konfirmasiPutus" title="Putuskan WhatsApp?"
            message="Nomor kantor akan dikeluarkan dari gateway dan sesi dihapus. Pengingat WhatsApp berhenti sampai nomor dipindai ulang.">
            <button type="button" class="btn btn-ghost" wire:click="batalPutus">Batal</button>
            <button type="button" class="btn btn-red" wire:click="putuskan" wire:loading.attr="disabled" wire:target="putuskan">
                <span wire:loading.remove wire:target="putuskan">Putuskan</span>
                <span wire:loading wire:target="putuskan"><i class="spin"></i> Memutus…</span>
            </button>
        </x-confirm-modal>
    </div>

    <div class="card">
        <div class="card-h">🧪 Kirim Pesan Uji WhatsApp</div>
        <div class="info">ℹ️ Nomor tujuan harus milik pengguna SIPINTER yang sudah mencentang persetujuan WhatsApp di halaman Profil.</div>

        <div class="field" style="max-width:260px">
            <label>Nomor Tujuan <span class="req">*</span></label>
            <input type="text" class="inp filled" style="width:100%" wire:model="nomorTes" placeholder="08xxxxxxxxxx">
            @error('nomorTes')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>

        <div class="field" style="max-width:420px;margin-bottom:4px">
            <label>Isi Pesan <span class="req">*</span></label>
            <textarea class="inp filled" style="width:100%;min-height:70px" wire:model="pesanTes"></textarea>
            @error('pesanTes')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>

        <div class="btn-row">
            <button type="button" class="btn btn-primary" wire:click="kirimTes" wire:loading.attr="disabled" wire:target="kirimTes">
                <span wire:loading.remove wire:target="kirimTes">📤 Kirim Uji</span>
                <span wire:loading wire:target="kirimTes"><i class="spin"></i> Mengirim (bisa ±1 menit bila gateway tidur)…</span>
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-h">📜 Riwayat Pengiriman WhatsApp</div>
        <div class="info">ℹ️ 20 pesan terakhir. "Diterima server WhatsApp" belum berarti sampai di HP; "Sampai di HP penerima" muncul bila WhatsApp mengirim tanda terima.</div>

        @if ($riwayat->isEmpty())
            <p class="muted" style="font-size:12.5px">Belum ada pengiriman.</p>
        @else
            <div class="table-scroll" style="max-height:360px">
                <table>
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Penerima</th>
                            <th>Jenis</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($riwayat as $baris)
                            <tr wire:key="riwayat-wa-{{ $baris->id }}">
                                <td class="muted" style="white-space:nowrap">{{ $baris->created_at?->wita()->format('d/m/y H:i') }} WITA</td>
                                <td>
                                    {{ $baris->user?->nama ?? '—' }}
                                    <div class="muted" style="font-size:11px">{{ \App\Support\NomorWhatsApp::samarkan($baris->nomor_telepon) }}</div>
                                </td>
                                <td class="muted" title="{{ $baris->pesan }}">{{ $baris->jenis ?? '—' }}</td>
                                <td>
                                    @php $berhasil = in_array($baris->status, ['terkirim', 'diterima'], true) || ($baris->status === 'arsip' && $baris->berhasil); @endphp
                                    <span class="badge {{ $berhasil ? 'b-approve' : (in_array($baris->status, ['antre', 'di_gateway'], true) ? 'b-tunggu' : 'b-tolak') }}">{{ $baris->labelStatus() }}</span>
                                    @if ($baris->alasan_gagal && ! $berhasil)
                                        <div class="muted" style="font-size:11px;margin-top:3px">{{ $baris->alasan_gagal }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
