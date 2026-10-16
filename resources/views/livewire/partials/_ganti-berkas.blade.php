{{-- Tombol "Ganti" untuk berkas yang ditandai "Tidak Sesuai" Tim SAKIP — berkas
     lama langsung diganti (dan dihapus permanen dari Drive) begitu berkas baru
     dipilih (lihat PengisianKegiatan::updatedGantiBerkas()), jadi pemilih berkas
     baru dibuka SETELAH dikonfirmasi lewat dialog pendingHapus. $berkasId: id berkas lama. --}}
<button type="button" class="btn btn-ghost btn-sm" title="Ganti dengan berkas PDF lain" wire:loading.class="btn-busy" wire:target="gantiBerkas.{{ $berkasId }}"
    @click="pendingHapus = { method: 'ganti', input: 'ganti-berkas-{{ $berkasId }}' }">
    🔁 Ganti
</button>
<input type="file" id="ganti-berkas-{{ $berkasId }}" wire:model="gantiBerkas.{{ $berkasId }}" accept="application/pdf" style="display:none">
@error("gantiBerkas.{$berkasId}")
    <span class="sub" style="color:var(--red)">{{ $message }}</span>
@enderror
