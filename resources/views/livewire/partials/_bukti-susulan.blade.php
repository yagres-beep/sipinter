{{-- Zona "Tambah bukti dukung" untuk bagian yang SUDAH diverifikasi Tim SAKIP —
     langsung tersimpan & diunggah ke Drive begitu dipilih/diseret (lihat
     PengisianKegiatan::updatedBuktiSusulan()). $kunciSusulan: "kegiatan-<id>" / "rtl-<id>". --}}
<div style="margin-top:8px">
    <x-zona-unggah :model="'buktiSusulan.'.$kunciSusulan" label="Seret PDF bukti dukung baru ke sini atau klik untuk memilih"
        keterangan="Langsung tersimpan dengan nama berkas aslinya dan diperiksa lagi oleh Tim SAKIP. Bukti yang sudah diverifikasi tidak berubah." />
    @error("buktiSusulan.{$kunciSusulan}")
        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
    @enderror
</div>
