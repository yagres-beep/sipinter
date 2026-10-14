{{--
    Penanda berkas yang SUDAH tersimpan tapi BELUM berhasil diunggah ke Google Drive
    (drive_file_id kosong) — mis. akun Google Drive belum disambungkan. Salinannya
    masih di server dan dicoba diunggah ulang tiap kali menyimpan (lihat
    PengisianKegiatan::susulkanBerkasBelumDiDrive()).
--}}
@props(['diDrive' => true])

@unless ($diDrive)
    <span class="sub" style="color:var(--red)" title="Berkas tersimpan di server tapi belum masuk Google Drive. Akan dicoba diunggah ulang otomatis setiap kali Anda menyimpan.">⚠️ Belum berhasil diunggah ke Google Drive</span>
@endunless
