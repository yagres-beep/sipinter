@if (session('status'))
    <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
@endif

<div class="card">
    <div class="sec"><span>👥 Penerima Tiap Jenis Pengingat</span> <span class="badge b-tunggu">bisa diubah</span></div>
    <div class="info">ℹ️ Tentukan siapa yang menerima tiap jenis pengingat email. Kalau tidak ada yang dicentang pada satu jenis, pengingat jenis itu tidak dikirim ke siapa pun.</div>

    @foreach ($jenisList as $jenis => $meta)
        <div class="field" style="border-bottom:1px solid var(--line2);padding-bottom:14px;margin-bottom:14px">
            <label style="margin-bottom:2px">{{ $meta['label'] }}</label>
            <div class="muted" style="font-size:11.5px;margin-bottom:9px">{{ $meta['deskripsi'] }}</div>
            <div style="display:flex;flex-wrap:wrap;gap:10px">
                @foreach ($meta['opsi'] as $role)
                    <label class="fl-row" style="width:auto;cursor:pointer;font-weight:400;margin-bottom:0;padding:8px 12px">
                        <input type="checkbox" wire:model="pilihan.{{ $jenis }}" value="{{ $role }}">
                        <span>{{ $roleLabel[$role] }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    @endforeach

    <div class="btn-row">
        <button type="button" class="btn btn-primary" wire:click="simpan" wire:loading.attr="disabled" wire:target="simpan">
            <span wire:loading.remove wire:target="simpan">Simpan Pengaturan</span>
            <span wire:loading wire:target="simpan"><i class="spin"></i> Menyimpan…</span>
        </button>
    </div>
</div>
