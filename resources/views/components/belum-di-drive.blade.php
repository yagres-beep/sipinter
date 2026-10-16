{{--
    Penanda berkas yang SUDAH tersimpan tapi BELUM berhasil diunggah ke Google Drive
    (drive_file_id kosong) — mis. akun Google Drive belum disambungkan. Salinannya
    masih di server dan dicoba diunggah ulang tiap kali menyimpan (lihat
    PengisianKegiatan::susulkanBerkasBelumDiDrive()). Bila $berkasId diisi, tampil
    tombol "Coba lagi" untuk berkas ini saja (PengisianKegiatan::cobaLagiDrive()).
--}}
@props(['diDrive' => true, 'berkasId' => null])

@unless ($diDrive)
    <span class="sub" style="color:var(--red)" title="Berkas tersimpan di server tapi belum masuk Google Drive. Akan dicoba diunggah ulang otomatis setiap kali Anda menyimpan.">⚠️ Belum berhasil diunggah ke Google Drive
        @if ($berkasId)
            <button type="button" class="btn-ubah-nama" style="color:var(--blue-600);font-weight:600" wire:click="cobaLagiDrive({{ $berkasId }})"
                wire:loading.attr="disabled" wire:target="cobaLagiDrive({{ $berkasId }})">
                <span wire:loading.remove wire:target="cobaLagiDrive({{ $berkasId }})">🔁 Coba lagi</span>
                <span wire:loading wire:target="cobaLagiDrive({{ $berkasId }})">mengunggah…</span>
            </button>
        @endif
    </span>
@endunless
