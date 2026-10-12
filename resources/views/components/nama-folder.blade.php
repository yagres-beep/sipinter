{{--
    Nama folder Drive satu kegiatan yang bisa diganti langsung dari web (klik ✏️) —
    pasangan <x-nama-berkas> untuk folder (tanpa ekstensi). Nama kosong = kembali ke
    rekomendasi sistem. aksi: method Livewire (id, namaBaru) yang mengembalikan
    {ok, nama_folder|pesan}, mis. VerifikasiCapaian::gantiNamaFolderKegiatan().
--}}
@props(['id', 'nama', 'kustom' => false, 'aksi' => 'gantiNamaFolderKegiatan'])

<span class="nama-berkas foldername" wire:ignore wire:key="nama-folder-{{ $id }}-{{ md5($nama) }}"
    style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap"
    x-data="{
        nama: @js($nama),
        kustom: @js((bool) $kustom),
        draf: '',
        edit: false,
        simpan: false,
        galat: '',
        mulai() {
            this.draf = this.kustom ? this.nama : '';
            this.galat = '';
            this.edit = true;
            this.$nextTick(() => { this.$refs.input.focus(); this.$refs.input.select(); });
        },
        async kirim() {
            if (this.simpan) return;
            this.simpan = true;
            const hasil = await this.$wire.{{ $aksi }}(@js($id), this.draf);
            this.simpan = false;
            if (hasil && hasil.ok) { this.nama = hasil.nama_folder; this.kustom = this.draf.trim() !== ''; this.edit = false; }
            else { this.galat = (hasil && hasil.pesan) || 'Gagal mengganti nama folder.'; }
        },
    }"
    @click.stop
>
    <span x-show="!edit">📁 <span x-text="nama">{{ $nama }}</span>
        <span x-show="!kustom" style="color:var(--muted);font-size:11px">(rekomendasi sistem)</span>
        <button type="button" class="btn-ubah-nama" title="Ganti nama folder" @click.stop.prevent="mulai()">✏️</button>
    </span>
    <span x-show="edit" x-cloak class="ubah-nama-form">
        <input type="text" x-ref="input" x-model="draf" maxlength="150" class="inp" :placeholder="nama"
            @keydown.enter.prevent="kirim()" @keydown.escape.prevent="edit = false">
        <button type="button" class="btn btn-primary btn-sm" @click.stop.prevent="kirim()" :disabled="simpan" x-text="simpan ? '…' : 'Simpan'"></button>
        <button type="button" class="btn btn-ghost btn-sm" @click.stop.prevent="edit = false">Batal</button>
        <span class="sub" style="color:var(--muted)">Kosongkan untuk rekomendasi sistem.</span>
        <span class="sub" style="color:var(--red)" x-show="galat" x-text="galat"></span>
    </span>
</span>
