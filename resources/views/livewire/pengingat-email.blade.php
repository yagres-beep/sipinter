<div wire:poll.10s>
    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="info red" style="margin-bottom:14px">⚠️ {{ session('error') }}</div>
    @endif

    <div class="card">
        <div class="card-h">📧 Status Pengingat Email</div>
        <div class="info" style="border-color:var(--teal, #0d9488)">
            ✅ Pengingat otomatis (tenggat IKU, verifikasi, notula, dsb) dikirim lewat <b>email</b> ke alamat email pengguna, menggantikan WhatsApp yang sering tidur/putus di hosting gratis.
        </div>
    </div>

    <div class="card">
        <div class="card-h">🧪 Kirim Email Tes</div>
        <div class="info">ℹ️ Kirim satu email langsung (tidak lewat antrean pengingat) untuk memastikan alamat email tujuan benar-benar bisa menerima.</div>

        <div class="field" style="max-width:320px">
            <label>Alamat Email Tujuan <span class="req">*</span></label>
            <input type="text" class="inp filled" style="width:100%" wire:model="emailTes" placeholder="nama@contoh.go.id">
            @error('emailTes')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>

        <div class="field" style="max-width:420px">
            <label>Subjek <span class="req">*</span></label>
            <input type="text" class="inp filled" style="width:100%" wire:model="subjekTes">
            @error('subjekTes')
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
        <div class="card-h">📜 Riwayat Pengiriman Email</div>
        <div class="info">ℹ️ 20 pengiriman terakhir (tes maupun pengingat otomatis), dengan alasan kalau gagal.</div>

        @if ($riwayat->isEmpty())
            <p class="muted" style="font-size:12.5px">Belum ada pengiriman.</p>
        @else
            <div class="table-scroll" style="max-height:360px">
                <table>
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Email</th>
                            <th>Subjek</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($riwayat as $baris)
                            <tr wire:key="riwayat-email-{{ $baris->id }}">
                                <td class="muted" style="white-space:nowrap">{{ $baris->created_at->wita()->format('d/m/y H:i') }} WITA</td>
                                <td>{{ $baris->email }}</td>
                                <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{{ $baris->pesan }}">{{ $baris->subjek }}</td>
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
</div>
