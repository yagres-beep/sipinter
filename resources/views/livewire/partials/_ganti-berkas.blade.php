{{-- Tombol "Ganti" untuk berkas yang ditandai "Tidak Sesuai" Tim SAKIP — berkas
     lama langsung diganti begitu berkas baru dipilih (lihat
     PengisianKegiatan::updatedGantiBerkas()). $berkasId: id berkas lama. --}}
<label class="btn btn-ghost btn-sm" style="cursor:pointer" title="Ganti dengan berkas PDF lain" wire:loading.class="btn-busy" wire:target="gantiBerkas.{{ $berkasId }}">
    🔁 Ganti
    <input type="file" wire:model="gantiBerkas.{{ $berkasId }}" accept="application/pdf" style="display:none">
</label>
@error("gantiBerkas.{$berkasId}")
    <span class="sub" style="color:var(--red)">{{ $message }}</span>
@enderror
