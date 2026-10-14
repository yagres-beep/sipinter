{{-- Tombol "Tambah bukti dukung" untuk bagian yang SUDAH diverifikasi Tim SAKIP —
     langsung tersimpan & diunggah ke Drive begitu dipilih (lihat
     PengisianKegiatan::updatedBuktiSusulan()). $kunciSusulan: "kegiatan-<id>" / "rtl-<id>". --}}
<div x-data="{ namaSusulan: [] }" style="margin-top:8px">
    <label class="btn btn-ghost btn-sm" style="cursor:pointer">
        📤 Tambah bukti dukung baru (PDF)
        <input type="file" wire:model="buktiSusulan.{{ $kunciSusulan }}" multiple accept="application/pdf" style="display:none"
            @change="namaSusulan = Array.from($event.target.files).map(f => f.name)">
    </label>
    <div class="fhint">Langsung tersimpan dengan nama berkas aslinya dan diperiksa lagi oleh Tim SAKIP. Bukti yang sudah diverifikasi tidak berubah.</div>
    <div wire:loading wire:target="buktiSusulan.{{ $kunciSusulan }}" style="font-size:11.5px;color:var(--muted);margin-top:6px">
        <template x-for="nama in namaSusulan" :key="nama">
            <div>📄 <span x-text="nama"></span> — mengunggah…</div>
        </template>
    </div>
    @error("buktiSusulan.{$kunciSusulan}")
        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
    @enderror
</div>
