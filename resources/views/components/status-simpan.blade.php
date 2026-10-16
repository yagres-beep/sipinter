{{--
    Indikator status simpan + penjaga "jangan sampai perubahan hilang", dipakai di
    dalam komponen Livewire yang punya properti publik $adaPerubahan (bool) dan
    $terakhirDisimpan (?string jam "HH:MM"), lihat PengisianKegiatan & VerifikasiCapaian.

    Dua lapis deteksi:
    - $wire.adaPerubahan  : perubahan yang SUDAH diterima server (kolom ditinggalkan /
                            berkas dipilih) tapi BELUM disimpan ke database.
    - mengetik (Alpine)   : kolom yang diubah tapi BELUM dikonfirmasi server — sedang
                            diketik, atau sudah ditinggalkan dan request-nya masih jalan,
                            atau berkasnya masih diunggah. Baru dilepas setelah request
                            yang membawanya sukses (hook "commit" di resources/js/app.js),
                            jadi langsung klik menu setelah mengetik uraian tidak lolos
                            dari konfirmasi.
    Selama salah satunya aktif: bilah bawah tampil dan elemen ini diberi atribut
    data-kotor, yang dibaca penjaga global di resources/js/app.js — pindah menu, tautan,
    Keluar, maupun F5/Ctrl+R meminta konfirmasi lewat dialog bertema SIPINTER dulu.
    Elemen ber-atribut data-abaikan-perubahan (mis. pilihan IKU/periode, filter) tidak
    dihitung sebagai perubahan isian.
--}}
@props([
    'aksi' => null,
    'labelAksi' => '💾 Simpan sekarang',
    'pesanBelum' => 'Ada perubahan yang belum disimpan ke database.',
    // Isi dialog konfirmasi saat halaman hendak ditinggalkan (null = pesan umum di app.js).
    'pesanKeluar' => null,
])

<div
    x-data="{
        mengetik: false,
        get kotor() { return $wire.adaPerubahan || this.mengetik },
        init() {
            const akar = this.$el.closest('[wire\\:id]') ?? document;
            const kotor = new Set();    // kolom/berkas yang belum dikonfirmasi server
            const terkirim = new Set(); // ...yang sudah ditinggalkan, ikut request berikutnya
            const segarkan = () => { this.mengetik = kotor.size > 0; };
            const diabaikan = (el) => el.closest?.('[data-abaikan-perubahan]');
            const lepas = (el) => { kotor.delete(el); terkirim.delete(el); segarkan(); };

            akar.addEventListener('input', (e) => {
                if (diabaikan(e.target) || e.target.type === 'file') return;
                kotor.add(e.target);
                terkirim.delete(e.target);
                segarkan();
            });
            akar.addEventListener('focusout', (e) => { if (kotor.has(e.target)) terkirim.add(e.target); });
            akar.addEventListener('change', (e) => { if (kotor.has(e.target)) terkirim.add(e.target); });
            akar.addEventListener('livewire-upload-start', (e) => {
                if (! diabaikan(e.target)) { kotor.add(e.target); segarkan(); }
            });
            ['livewire-upload-finish', 'livewire-upload-error', 'livewire-upload-cancel']
                .forEach((jenis) => akar.addEventListener(jenis, (e) => lepas(e.target)));

            // Dipanggil hook 'commit' Livewire (app.js) tiap request komponen ini.
            this.komitMulai = (succeed) => {
                const ikut = [...terkirim];
                if (ikut.length === 0) return;
                succeed(() => {
                    ikut.forEach((el) => { if (terkirim.delete(el)) kotor.delete(el); });
                    segarkan();
                });
            };
            this.lupakan = () => { kotor.clear(); terkirim.clear(); segarkan(); };
        },
    }"
    x-bind:data-kotor="kotor ? 'ya' : false"
    x-on:perubahan-tersimpan.window="lupakan()"
    class="status-simpan"
    @if ($pesanKeluar) data-pesan-keluar="{{ $pesanKeluar }}" @endif
    style="position:sticky;bottom:10px;z-index:30;margin-top:14px"
>
    <div x-show="kotor" x-cloak
        style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--amber-soft);border:1px solid color-mix(in srgb, var(--amber) 55%, transparent);color:var(--amber);border-radius:11px;padding:9px 13px;font-size:12px;box-shadow:0 4px 14px rgba(0,0,0,.08)">
        <span x-show="mengetik && ! $wire.adaPerubahan">✎ Sedang mengetik — perubahan dikirim saat kolom ditinggalkan, lalu perlu disimpan.</span>
        <span x-show="$wire.adaPerubahan">● {{ $pesanBelum }}</span>
        @if ($aksi)
            <button type="button" class="btn btn-sm btn-primary" style="margin-left:auto" wire:click="{{ $aksi }}" wire:loading.attr="disabled" wire:target="{{ $aksi }}">
                <span wire:loading.remove wire:target="{{ $aksi }}">{{ $labelAksi }}</span>
                <span wire:loading wire:target="{{ $aksi }}">Menyimpan…</span>
            </button>
        @endif
    </div>
    <div x-show="! kotor && $wire.terakhirDisimpan" x-cloak style="font-size:11.5px;color:var(--teal)">
        ✓ Semua perubahan tersimpan<span x-show="$wire.terakhirDisimpan"> (pukul <span x-text="$wire.terakhirDisimpan"></span>)</span>.
    </div>
</div>
