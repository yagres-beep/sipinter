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
                <button type="button" class="btn btn-red" wire:click="bukaKonfirmasiReset">
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
            <p style="color:var(--muted);font-size:12.5px;margin:8px 0 0">Kalau gateway pakai hosting gratis (mis. Render), server bisa "tidur" setelah lama tidak dipakai dan butuh sampai ±1 menit untuk bangun lagi. Klik "Coba Sambungkan Ulang" dulu untuk mencoba membangunkannya. Kalau nomornya baru saja di-logout dari HP (bukan lewat web), pakai "Putus Tautan / Ganti Nomor" supaya sesi lama dihapus dan QR baru bisa discan.</p>

            <div class="btn-row" style="margin-top:10px">
                <button type="button" class="btn btn-primary" wire:click="sambungkanUlang" wire:loading.attr="disabled" wire:target="sambungkanUlang">
                    <span wire:loading.remove wire:target="sambungkanUlang">🔄 Coba Sambungkan Ulang</span>
                    <span wire:loading wire:target="sambungkanUlang"><i class="spin"></i> Menyambungkan (bisa sampai 1 menit)…</span>
                </button>
                <button type="button" class="btn btn-red" wire:click="bukaKonfirmasiReset">
                    🔌 Putus Tautan / Ganti Nomor
                </button>
            </div>
        @else
            <div class="info">⏳ Menyambungkan ke gateway, mohon tunggu… (status: <code>{{ $gatewayStatus }}</code>)</div>
            <p style="color:var(--muted);font-size:12.5px;margin:8px 0 0">Kalau statusnya bolak-balik terus & tidak kunjung <code>connected</code>/<code>waiting_for_qr</code> — biasanya karena nomornya baru di-logout dari HP (bukan lewat web) sehingga sesi lama tersimpan sudah tidak valid. Pakai "Putus Tautan / Ganti Nomor" supaya sesi lama dihapus dan QR baru bisa discan.</p>

            <div class="btn-row" style="margin-top:10px">
                <button type="button" class="btn btn-primary" wire:click="sambungkanUlang" wire:loading.attr="disabled" wire:target="sambungkanUlang">
                    <span wire:loading.remove wire:target="sambungkanUlang">🔄 Coba Sambungkan Ulang</span>
                    <span wire:loading wire:target="sambungkanUlang"><i class="spin"></i> Menyambungkan…</span>
                </button>
                <button type="button" class="btn btn-red" wire:click="bukaKonfirmasiReset">
                    🔌 Putus Tautan / Ganti Nomor
                </button>
            </div>
        @endif

        <x-confirm-modal :show="$showResetConfirm" title="Putus Tautan?"
            message="Putuskan nomor yang sedang tertaut & hapus sesi lama? Pengingat WA berhenti terkirim sampai nomor baru discan.">
            <button type="button" class="btn btn-ghost" wire:click="batalkanReset" wire:loading.attr="disabled" wire:target="resetSesi">Batal</button>
            <button type="button" class="btn btn-red" wire:click="resetSesi" wire:loading.attr="disabled" wire:target="resetSesi">
                <span wire:loading.remove wire:target="resetSesi">Putus Tautan</span>
                <span wire:loading wire:target="resetSesi"><i class="spin"></i> Memutus…</span>
            </button>
        </x-confirm-modal>
    </div>

    <div class="card">
        <div class="card-h">🧪 Kirim Pesan Tes</div>
        <div class="info">ℹ️ Kirim satu pesan langsung (tidak lewat antrean pengingat) untuk memastikan nomor yang tertaut benar-benar bisa mengirim.</div>

        <div class="field" style="max-width:260px">
            <label>Nomor Telepon Tujuan <span class="req">*</span></label>
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
                <span wire:loading.remove wire:target="kirimTes">📤 Kirim Tes</span>
                <span wire:loading wire:target="kirimTes"><i class="spin"></i> Mengirim…</span>
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-h">📜 Riwayat Pengiriman</div>
        <div class="info">ℹ️ 20 pengiriman terakhir (tes maupun pengingat otomatis), dengan alasan kalau gagal. Halaman ini otomatis memperbarui diri tiap beberapa detik.</div>

        @if ($riwayat->isEmpty())
            <p class="muted" style="font-size:12.5px">Belum ada pengiriman.</p>
        @else
            <div class="table-scroll" style="max-height:360px">
                <table>
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Nomor</th>
                            <th>Pesan</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($riwayat as $baris)
                            <tr wire:key="riwayat-wa-{{ $baris->id }}">
                                <td class="muted" style="white-space:nowrap">{{ $baris->created_at->format('d/m/y H:i') }}</td>
                                <td>{{ $baris->nomor_telepon }}</td>
                                <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{{ $baris->pesan }}">{{ $baris->pesan }}</td>
                                <td>
                                    @if ($baris->berhasil)
                                        <span class="badge b-approve">✓ Berhasil</span>
                                    @else
                                        <span class="badge b-tolak" title="{{ $baris->alasan_gagal }}">✕ Gagal</span>
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

    <livewire:pengaturan-pengingat />

    <livewire:pengaturan-penerima-pengingat />

    <livewire:pengaturan-template-pengingat />
</div>
