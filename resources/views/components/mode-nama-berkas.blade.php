{{--
    Pilihan cara menamai berkas bukti yang BARU dipilih (belum tersimpan): nama asli
    dari komputer (bawaan) atau rekomendasi sistem. Terikat ke
    PengisianKegiatan::$modeNamaBerkas, jadi berlaku untuk semua unggahan di form ini.
    Nama yang diganti manual lewat ✏️ tetap diutamakan apa pun pilihannya.
--}}
@props(['mode' => 'asli'])

<div class="pills" style="margin-bottom:8px;align-items:center;gap:6px;flex-wrap:wrap">
    <span style="font-size:11.5px;color:var(--muted)">Nama berkas:</span>
    <span class="pill {{ $mode === 'asli' ? 'on' : '' }}" wire:click="$set('modeNamaBerkas', 'asli')">Nama asli</span>
    <span class="pill {{ $mode === 'sistem' ? 'on' : '' }}" wire:click="$set('modeNamaBerkas', 'sistem')">Rekomendasi sistem</span>
</div>
