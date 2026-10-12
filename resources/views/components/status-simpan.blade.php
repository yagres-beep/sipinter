{{--
    Indikator status simpan + penjaga "jangan sampai perubahan hilang", dipakai di
    dalam komponen Livewire yang punya properti publik $adaPerubahan (bool) dan
    $terakhirDisimpan (?string jam "HH:MM"), lihat PengisianKegiatan & VerifikasiCapaian.

    Dua lapis deteksi:
    - $wire.adaPerubahan  : perubahan yang SUDAH terkirim ke server (kolom ditinggalkan /
                            berkas dipilih) tapi BELUM disimpan ke database.
    - mengetik (Alpine)   : sedang mengetik di sebuah kolom, belum ditinggalkan — belum
                            sampai ke server sama sekali.
    Selama salah satunya aktif: bilah bawah tampil, menutup/memuat ulang tab memunculkan
    peringatan browser, dan pindah menu (wire:navigate) meminta konfirmasi dulu.
    Elemen ber-atribut data-abaikan-perubahan (mis. pilihan IKU/periode, filter) tidak
    dihitung sebagai perubahan isian.
--}}
@props([
    'aksi' => null,
    'labelAksi' => '💾 Simpan sekarang',
    'pesanBelum' => 'Ada perubahan yang belum disimpan ke database.',
])

<div
    x-data="{
        mengetik: false,
        get kotor() { return $wire.adaPerubahan || this.mengetik },
        init() {
            const akar = this.$el.closest('[wire\\:id]') ?? document;
            akar.addEventListener('input', (e) => {
                if (! e.target.closest('[data-abaikan-perubahan]')) this.mengetik = true;
            });
            akar.addEventListener('focusout', () => { this.mengetik = false; });
        },
    }"
    x-on:beforeunload.window="if (kotor) { $event.preventDefault(); $event.returnValue = ''; }"
    x-on:livewire:navigate.document="if (kotor && ! confirm('Ada perubahan yang belum disimpan. Tetap tinggalkan halaman ini? Perubahan tersebut akan hilang.')) $event.preventDefault()"
    x-on:perubahan-tersimpan.window="mengetik = false"
    class="status-simpan"
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
