{{--
    Nama berkas bukti yang bisa diganti langsung dari web (klik ✏️). Nama bawaan
    (otomatis) tetap tampil lebih dulu; hanya pengunggahnya sendiri atau Tim SAKIP
    yang melihat tombol ubah — dicek ulang di server lewat Berkas::bisaDiubahNamaOleh(),
    tombol di sini sekadar penanda. Komponen Livewire induknya WAJIB memakai trait
    App\Livewire\Concerns\GantiNamaBerkas.

    wire:ignore + wire:key berisi nama: nama baru hasil Alpine tidak ditimpa nama lama
    saat induk re-render, tapi bila nama dari server memang berubah elemen dibuat ulang.
--}}
@props(['id', 'nama', 'diunggahOleh' => null, 'aksi' => 'gantiNamaBerkas', 'bisaUbah' => null])

{{--
    aksi: method Livewire yang dipanggil (id, namaBaru) dan mengembalikan
    {ok, nama_file|pesan}. Bawaan gantiNamaBerkas (berkas yang sudah tersimpan);
    form Isian Kegiatan memakai aturNamaBuktiBaru untuk berkas yang BARU dipilih
    (belum tersimpan), dengan bisaUbah=true karena itu berkas milik pengisi sendiri.
--}}
@php
    $user = auth()->user();
    $bisaUbah ??= $user && (
        $user->namaRole() === 'Tim SAKIP'
        || $diunggahOleh === null
        || (int) $diunggahOleh === (int) $user->id
    );
@endphp

<span class="nama-berkas" wire:ignore wire:key="nama-berkas-{{ $id }}-{{ md5($nama) }}"
    x-data="{
        nama: @js($nama),
        draf: '',
        edit: false,
        simpan: false,
        galat: '',
        ekstensi() { const m = this.nama.match(/\.[^.]+$/); return m ? m[0] : ''; },
        mulai() {
            this.draf = this.nama.slice(0, this.nama.length - this.ekstensi().length);
            this.galat = '';
            this.edit = true;
            this.$nextTick(() => { this.$refs.input.focus(); this.$refs.input.select(); });
        },
        async kirim() {
            if (this.simpan) return;
            if (this.draf.trim() === '') { this.galat = 'Nama tidak boleh kosong.'; return; }
            this.simpan = true;
            const hasil = await this.$wire.{{ $aksi }}(@js($id), this.draf);
            this.simpan = false;
            if (hasil && hasil.ok) { this.nama = hasil.nama_file; this.edit = false; }
            else { this.galat = (hasil && hasil.pesan) || 'Gagal mengganti nama berkas.'; }
        },
    }"
    :class="{ 'sedang-ubah': edit }"
    @click.stop
>
    <span x-show="!edit">📄 <span x-text="nama">{{ $nama }}</span>
        @if ($bisaUbah)
            <button type="button" class="btn-ubah-nama" title="Ganti nama berkas" @click.stop.prevent="mulai()">✏️</button>
        @endif
    </span>
    @if ($bisaUbah)
        <span x-show="edit" x-cloak class="ubah-nama-form">
            <input type="text" x-ref="input" x-model="draf" maxlength="150" class="inp"
                @keydown.enter.prevent="kirim()" @keydown.escape.prevent="edit = false">
            <span class="ext" x-text="ekstensi()"></span>
            <button type="button" class="btn btn-primary btn-sm" @click.stop.prevent="kirim()" :disabled="simpan" x-text="simpan ? '…' : 'Simpan'"></button>
            <button type="button" class="btn btn-ghost btn-sm" @click.stop.prevent="edit = false">Batal</button>
            <span class="sub" style="color:var(--red)" x-show="galat" x-text="galat"></span>
        </span>
    @endif
</span>
