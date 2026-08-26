<div class="card">
    <div class="sec"><span>✏️ Format Pesan Tiap Jenis Pengingat</span> <span class="badge b-tunggu">bisa diubah</span></div>
    <div class="info">ℹ️ Tulis dalam kurung kurawal seperti <code>{indikator}</code> untuk diisi otomatis saat pesan dikirim — daftar token yang tersedia ada di bawah tiap kotak. Untuk jenis yang punya catatan (IKU/Notula dikembalikan), baris "Catatan: ..." selalu ditambahkan otomatis setelah pesan ini, tidak perlu ditulis manual.</div>

    @foreach ($jenisList as $jenis => $meta)
        <div class="field" style="border-bottom:1px solid var(--line2);padding-bottom:14px;margin-bottom:14px">
            <label style="margin-bottom:2px">{{ $meta['label'] }}</label>
            <div class="muted" style="font-size:11.5px;margin-bottom:8px">{{ $meta['deskripsi'] }}</div>

            <textarea class="inp filled" style="width:100%;min-height:80px;font-family:inherit" wire:model="template.{{ $jenis }}"></textarea>
            @error("template.$jenis")
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror

            <div class="fhint" style="margin-top:8px">
                Token tersedia:
                @foreach ($meta['token'] as $token => $keterangan)
                    <span class="chip chip-auto" style="margin:2px 4px 2px 0" title="{{ $keterangan }}"><code>{{ '{'.$token.'}' }}</code></span>
                @endforeach
            </div>

            <button type="button" class="btn btn-ghost btn-sm" style="margin-top:9px" wire:click="pulihkanBawaan('{{ $jenis }}')">↺ Pulihkan Bawaan</button>
        </div>
    @endforeach

    <div class="btn-row">
        <button type="button" class="btn btn-primary" wire:click="simpan" wire:loading.attr="disabled" wire:target="simpan">
            <span wire:loading.remove wire:target="simpan">Simpan Pengaturan</span>
            <span wire:loading wire:target="simpan"><i class="spin"></i> Menyimpan…</span>
        </button>
    </div>
</div>
