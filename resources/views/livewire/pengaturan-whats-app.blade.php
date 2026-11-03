@php
    $label = [
        'starting' => ['⏳', 'Gateway baru menyala'],
        'waiting_lock' => ['⏳', 'Menunggu instance gateway lama berhenti'],
        'no_session' => ['⚪', 'Belum terhubung'],
        'connecting' => ['⏳', 'Menghubungkan'],
        'waiting_qr' => ['📷', 'Menunggu QR dipindai'],
        'connected' => ['✅', 'Terhubung'],
        'reconnecting' => ['🔄', 'Menghubungkan ulang'],
        'logged_out' => ['⚠️', 'Perangkat dikeluarkan — perlu tautkan ulang'],
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
        <div class="card-h" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
            <span>📱 Koneksi WhatsApp Kantor</span>
            <span class="badge {{ $status === 'connected' ? 'b-approve' : ($status === 'waiting_qr' ? 'b-tunggu' : 'b-ajukan') }}">
                {{ $ikon }} {{ $teksStatus }}
            </span>
        </div>

        <div class="info">
            ℹ️ Tautkan <b>satu nomor WhatsApp kantor</b> langsung dari halaman ini (bisa lewat <b>Scan QR</b> atau <b>Kode Pairing Nomor HP</b>). Email tetap menjadi jalur pengingat utama.
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin:12px 0;padding:12px;border:1px solid var(--border,#e5e7eb);border-radius:10px;background:var(--bg-soft,#f8fafc);font-size:13px">
            <div>
                <div class="muted" style="font-size:11.5px">Status Koneksi</div>
                <div style="margin-top:2px"><b>{{ $ikon }} {{ $teksStatus }}</b></div>
                @if ($statusGateway['me'] ?? null)
                    <div class="muted" style="font-size:12px;margin-top:2px">Nomor tertaut: <b>{{ $statusGateway['me'] }}</b></div>
                @endif
            </div>

            <div>
                <div class="muted" style="font-size:11.5px">Pengingat Otomatis WA</div>
                <div style="display:flex;align-items:center;gap:8px;margin-top:3px;flex-wrap:wrap">
                    <span class="badge {{ $pengingatAktif ? 'b-approve' : 'b-tunggu' }}">
                        {{ $pengingatAktif ? '✓ Aktif' : 'Nonaktif' }}
                    </span>
                    <button type="button" class="btn btn-ghost btn-sm" style="padding:3px 10px;font-size:11.5px"
                            wire:click="togglePengingatOtomatis" wire:loading.attr="disabled" wire:target="togglePengingatOtomatis">
                        {{ $pengingatAktif ? 'Matikan' : 'Aktifkan Sekarang' }}
                    </button>
                </div>
            </div>

            <div>
                <div class="muted" style="font-size:11.5px">Penerima Setuju WA</div>
                <div style="margin-top:2px"><b>{{ $jumlahSetuju }} pengguna</b></div>
                @if ($statusGateway['outbox'] ?? null)
                    <div class="muted" style="font-size:11.5px;margin-top:2px">
                        Antrean: {{ $statusGateway['outbox']['queued'] }} menunggu
                    </div>
                @endif
            </div>
        </div>

        @if ($galatGateway)
            <div class="info red" style="margin-top:10px">⚠️ {{ $galatGateway }}</div>
        @endif

        @if (($statusGateway['last_disconnect']['reason'] ?? null) && $status !== 'connected')
            <div class="muted" style="font-size:12px;margin-top:6px">Catatan koneksi terakhir: {{ $statusGateway['last_disconnect']['reason'] }}</div>
        @endif

        @if ($status === 'reconnecting' && ($statusGateway['next_retry_at'] ?? null))
            <div class="muted" style="font-size:12px;margin-top:6px">Percobaan ke-{{ $statusGateway['reconnect_attempts'] }}; dicoba lagi otomatis {{ \Illuminate\Support\Carbon::parse($statusGateway['next_retry_at'])->wita()->format('H:i:s') }} WITA.</div>
        @endif

        @if ($status !== 'connected')
            <div style="margin-top:14px;border:1px solid var(--border,#e5e7eb);border-radius:10px;padding:14px;background:#fff">
                <div style="font-weight:600;font-size:13.5px;margin-bottom:10px">🔗 Pilih Cara Menyambungkan Nomor WhatsApp:</div>

                <div class="subtabs" style="margin-bottom:12px">
                    <button type="button" class="subtab {{ $metodeSambung === 'qr' ? 'on' : '' }}" wire:click="pilihMetode('qr')">
                        📷 Cara 1: Scan Kode QR
                    </button>
                    <button type="button" class="subtab {{ $metodeSambung === 'pairing' ? 'on' : '' }}" wire:click="pilihMetode('pairing')">
                        🔢 Cara 2: Pakai Nomor HP (Kode Pairing)
                    </button>
                </div>

                @if ($metodeSambung === 'qr')
                    <div style="font-size:12.5px;line-height:1.6;color:var(--text,#334155);margin-bottom:12px">
                        1. Klik tombol <b>Hubungkan WhatsApp / Tampilkan QR</b> di bawah.<br>
                        2. Buka WhatsApp di HP kantor → <b>Setelan (atau titik tiga) → Perangkat tertaut → Tautkan perangkat</b>.<br>
                        3. Arahkan kamera HP ke kode QR yang muncul di layar ini.
                    </div>

                    @if ($status === 'waiting_qr' && $qrDataUrl)
                        <div class="info" style="margin-top:8px">📷 <b>QR siap dipindai!</b> QR diperbarui otomatis tiap beberapa detik.</div>
                        <div style="display:flex;justify-content:center;margin:14px 0">
                            <img src="{{ $qrDataUrl }}" alt="QR WhatsApp" style="max-width:260px;width:100%;padding:10px;border:2px solid var(--teal,#0d9488);border-radius:12px;background:#fff;box-shadow:0 4px 12px rgba(0,0,0,0.06)">
                        </div>
                    @elseif ($status === 'waiting_qr' || $status === 'connecting')
                        <div class="info" style="margin-top:8px">⏳ Menyiapkan kode QR dari WhatsApp, mohon tunggu beberapa detik…</div>
                    @endif

                    <div class="btn-row" style="margin-top:10px;flex-wrap:wrap">
                        <button type="button" class="btn btn-primary" wire:click="hubungkan" wire:loading.attr="disabled" wire:target="hubungkan">
                            <span wire:loading.remove wire:target="hubungkan">
                                📷 {{ ($statusGateway['session_present'] ?? false) ? 'Hubungkan Ulang' : ($status === 'waiting_qr' ? 'Muat Ulang QR' : 'Hubungkan WhatsApp / Tampilkan QR') }}
                            </span>
                            <span wire:loading wire:target="hubungkan"><i class="spin"></i> Menyiapkan QR…</span>
                        </button>

                        <button type="button" class="btn btn-ghost" wire:click="periksa" wire:loading.attr="disabled" wire:target="periksa,saatTabTerlihat">
                            <span wire:loading.remove wire:target="periksa,saatTabTerlihat">🔍 Periksa Status</span>
                            <span wire:loading wire:target="periksa,saatTabTerlihat"><i class="spin"></i> Memeriksa…</span>
                        </button>
                    </div>
                @else
                    <div style="font-size:12.5px;line-height:1.6;color:var(--text,#334155);margin-bottom:12px">
                        Cocok bila kamera HP sulit memindai layar atau Anda membuka web ini dari HP yang sama:<br>
                        1. Ketik nomor WhatsApp kantor di bawah lalu klik <b>Minta Kode Pairing</b>.<br>
                        2. Di HP kantor buka <b>WhatsApp → Perangkat tertaut → Tautkan perangkat → Tautkan dengan nomor telepon saja</b>.<br>
                        3. Masukkan 8 karakter kode yang muncul di bawah.
                    </div>

                    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
                        <div class="field" style="max-width:280px;flex:1;margin-bottom:0">
                            <label>Nomor WhatsApp Kantor</label>
                            <input type="text" class="inp filled" style="width:100%" wire:model="nomorPairing" placeholder="Contoh: 085256513989">
                        </div>
                        <button type="button" class="btn btn-primary" wire:click="mintaKodePairing" wire:loading.attr="disabled" wire:target="mintaKodePairing">
                            <span wire:loading.remove wire:target="mintaKodePairing">🔢 Minta Kode Pairing</span>
                            <span wire:loading wire:target="mintaKodePairing"><i class="spin"></i> Meminta Kode…</span>
                        </button>
                        <button type="button" class="btn btn-ghost" wire:click="periksa" wire:loading.attr="disabled" wire:target="periksa,saatTabTerlihat">
                            <span wire:loading.remove wire:target="periksa,saatTabTerlihat">🔍 Periksa Status</span>
                            <span wire:loading wire:target="periksa,saatTabTerlihat"><i class="spin"></i> Memeriksa…</span>
                        </button>
                    </div>
                    @error('nomorPairing')
                        <div style="color:var(--red);font-size:11.5px;margin-top:6px">{{ $message }}</div>
                    @enderror

                    @if ($kodePairing)
                        <div class="info" style="margin-top:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px"
                             x-data="{ disalin: false }">
                            <div>
                                🔢 Kode pairing Anda:
                                <b style="font-size:20px;letter-spacing:3px;padding:4px 10px;background:#fff;border-radius:6px;border:1px solid var(--border,#cbd5e1);margin-left:6px">{{ $kodePairing }}</b>
                                <div style="font-size:12px;margin-top:6px">Masukkan kode ini di WhatsApp HP (menu <b>Tautkan dengan nomor telepon</b>) dalam ±2 menit.</div>
                            </div>
                            <button type="button" class="btn btn-ghost btn-sm"
                                    @click="navigator.clipboard.writeText(@js(str_replace('-', '', $kodePairing))); disalin = true; setTimeout(() => disalin = false, 2000)">
                                <span x-text="disalin ? '✓ Tersalin' : '📋 Salin Kode'"></span>
                            </button>
                        </div>
                    @endif
                @endif
            </div>
        @else
            <div class="info" style="margin-top:10px;border-color:var(--teal,#0d9488)">
                ✅ <b>WhatsApp Kantor sudah terhubung{{ ($statusGateway['me'] ?? null) ? ' ('.$statusGateway['me'].')' : '' }}!</b>
                Anda dapat langsung menguji pengiriman pesan di kartu bawah.
            </div>
        @endif

        <div class="btn-row" style="margin-top:12px;flex-wrap:wrap">
            @if ($status === 'connected')
                <button type="button" class="btn btn-ghost" wire:click="periksa" wire:loading.attr="disabled" wire:target="periksa,saatTabTerlihat">
                    <span wire:loading.remove wire:target="periksa,saatTabTerlihat">🔍 Periksa Koneksi</span>
                    <span wire:loading wire:target="periksa,saatTabTerlihat"><i class="spin"></i> Memeriksa…</span>
                </button>
            @endif

            @if ($status === 'connected' || ($statusGateway['session_present'] ?? false) || in_array($status, ['failed', 'conflict'], true))
                <button type="button" class="btn btn-red" wire:click="mintaPutus">🔌 Putuskan / Ganti Nomor WA</button>
            @endif
        </div>

        <details style="margin-top:14px;border-top:1px dashed var(--border,#e5e7eb);padding-top:10px">
            <summary style="cursor:pointer;font-size:12.5px;color:var(--muted,#64748b)">
                ⚙️ Pengaturan Server Gateway ({{ $memakaiGatewayBawaan ? 'Memakai Gateway Bawaan Otomatis' : 'Gateway Eksternal: '.$urlGatewayAktif }})
            </summary>
            <div style="margin-top:10px;font-size:12.5px">
                <p class="muted" style="margin-bottom:10px">
                    Secara bawaan SIPINTER menjalankan gateway WhatsApp langsung di server yang sama sehingga Anda <b>tidak perlu mengisi URL atau Token di environment server</b>. Isi kolom di bawah hanya bila ingin memakai server gateway terpisah.
                </p>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;max-width:640px">
                    <div class="field" style="margin-bottom:0">
                        <label>URL Gateway Eksternal (opsional)</label>
                        <input type="text" class="inp filled" style="width:100%" wire:model="gatewayUrlInput" placeholder="Kosongkan untuk gateway bawaan">
                        @error('gatewayUrlInput')
                            <div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>Token Gateway Eksternal (opsional)</label>
                        <input type="password" class="inp filled" style="width:100%" wire:model="gatewayTokenInput" placeholder="Isi bila memakai gateway eksternal">
                        @error('gatewayTokenInput')
                            <div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="btn-row" style="margin-top:10px">
                    <button type="button" class="btn btn-primary btn-sm" wire:click="simpanKonfigurasiGateway" wire:loading.attr="disabled" wire:target="simpanKonfigurasiGateway">
                        💾 Simpan Pengaturan Gateway
                    </button>
                    @unless ($memakaiGatewayBawaan)
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="gunakanGatewayBawaan" wire:loading.attr="disabled" wire:target="gunakanGatewayBawaan">
                            ↩️ Gunakan Gateway Bawaan Server
                        </button>
                    @endunless
                </div>
            </div>
        </details>

        <x-confirm-modal :show="$konfirmasiPutus" title="Putuskan WhatsApp?"
            message="Nomor kantor akan dikeluarkan dari gateway dan sesi dihapus. Pengingat WhatsApp berhenti sampai nomor ditautkan ulang.">
            <button type="button" class="btn btn-ghost" wire:click="batalPutus">Batal</button>
            <button type="button" class="btn btn-red" wire:click="putuskan" wire:loading.attr="disabled" wire:target="putuskan">
                <span wire:loading.remove wire:target="putuskan">Putuskan</span>
                <span wire:loading wire:target="putuskan"><i class="spin"></i> Memutus…</span>
            </button>
        </x-confirm-modal>
    </div>

    <div class="card">
        <div class="card-h">🧪 Kirim Pesan Uji WhatsApp</div>
        <div class="info">
            ℹ️ Pilih salah satu pengguna yang sudah menyetujui WhatsApp di bawah, atau gunakan tombol <b>Aktifkan Nomor Saya &amp; Kirim Uji</b> untuk langsung menguji ke nomor Anda sendiri.
        </div>

        @if ($penggunaSetuju->isNotEmpty())
            <div style="margin-bottom:12px">
                <div style="font-size:12px;font-weight:600;margin-bottom:6px;color:var(--muted,#64748b)">Pilih Cepat Penerima yang Sudah Setuju WA:</div>
                <div style="display:flex;flex-wrap:wrap;gap:6px">
                    @foreach ($penggunaSetuju as $p)
                        <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 10px;font-size:12px"
                                wire:click="pilihPenerimaTes('{{ $p->nomor_telepon }}')">
                            👤 {{ $p->nama }} ({{ $p->nomor_telepon }})
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="field" style="max-width:280px">
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

        <div class="btn-row" style="flex-wrap:wrap">
            <button type="button" class="btn btn-primary" wire:click="kirimTes" wire:loading.attr="disabled" wire:target="kirimTes,aktifkanWaSayaDanKirimTes">
                <span wire:loading.remove wire:target="kirimTes">📤 Kirim Uji</span>
                <span wire:loading wire:target="kirimTes"><i class="spin"></i> Mengirim…</span>
            </button>

            <button type="button" class="btn btn-ghost" wire:click="aktifkanWaSayaDanKirimTes" wire:loading.attr="disabled" wire:target="kirimTes,aktifkanWaSayaDanKirimTes"
                    title="Simpan nomor di atas ke profil Anda, aktifkan persetujuan WA, lalu langsung kirim pesan uji">
                <span wire:loading.remove wire:target="aktifkanWaSayaDanKirimTes">✅ Simpan ke Profil Saya &amp; Kirim Uji</span>
                <span wire:loading wire:target="aktifkanWaSayaDanKirimTes"><i class="spin"></i> Menyimpan &amp; Mengirim…</span>
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
