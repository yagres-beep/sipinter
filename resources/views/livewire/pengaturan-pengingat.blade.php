<div class="card">
    <div class="sec"><span>⏰ Waktu Pengingat Terjadwal</span> <span class="badge b-tunggu">bisa diubah</span></div>
    <div class="info">ℹ️ Berlaku untuk pengingat yang dicek otomatis tiap hari: tenggat pengajuan IKU, IKU lengkap siap disusun jadi Notula, dan akun Google yang perlu disambungkan ulang. Pengingat saat status diajukan/dikembalikan/disetujui terkirim langsung saat itu juga, tidak dipengaruhi pengaturan ini.</div>

    <div class="field" style="max-width:220px">
        <label>Jam Pengecekan Harian <span class="req">*</span></label>
        <input type="time" class="inp filled" style="width:100%" wire:model="jamKirim">
        @error('jamKirim')
            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
        @enderror
    </div>

    <div class="field" style="max-width:280px;margin-bottom:4px">
        <label>Ingatkan Tenggat IKU Mulai H- <span class="req">*</span></label>
        <input type="number" min="0" max="27" class="inp filled" style="width:100%" wire:model="deadlineHMinus">
        <div class="fhint">Jumlah hari sebelum akhir bulan (tenggat pengajuan IKU) mulai dikirim pengingat, lalu diulang tiap hari sampai IKU-nya diajukan.</div>
        @error('deadlineHMinus')
            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
        @enderror
    </div>

    <div class="btn-row">
        <button type="button" class="btn btn-primary" wire:click="simpan" wire:loading.attr="disabled" wire:target="simpan">
            <span wire:loading.remove wire:target="simpan">Simpan Pengaturan</span>
            <span wire:loading wire:target="simpan"><i class="spin"></i> Menyimpan…</span>
        </button>
    </div>
</div>
