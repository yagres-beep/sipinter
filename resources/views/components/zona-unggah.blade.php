{{--
    Zona unggah bersama: seret berkas dari File Explorer ATAU klik/Enter untuk memilih.
    Berkas tetap dikirim lewat input wire:model="$model" biasa, jadi logika server
    yang sudah ada (validasi, simpan otomatis, unggah ke Drive) tidak berubah — lihat
    Alpine.data('zonaUnggah') di resources/js/app.js. Daftar di bawahnya menunjukkan
    status pengiriman ke server tiap berkas (mengunggah/terunggah/gagal + coba lagi);
    status masuk Google Drive tampil di daftar bukti tersimpan & notifikasi.
--}}
@props([
    'model',
    'multiple' => true,
    'accept' => 'application/pdf,.pdf',
    'ekstensi' => ['pdf'],
    'label' => 'Seret PDF ke sini atau klik untuk memilih',
    'keterangan' => null,
    'ringkas' => false,
])

<div x-data="zonaUnggah({ ekstensi: @js($ekstensi), banyak: @js((bool) $multiple) })"
    data-zona-unggah
    {{ $attributes->class(['zona-unggah', 'ringkas' => $ringkas]) }}
    :class="{ 'diseret': diseret, 'sibuk': sibuk }"
    role="button" tabindex="0" aria-label="{{ $label }}"
    x-on:click="pilih($event)"
    x-on:keydown.enter.prevent="pilih($event)"
    x-on:keydown.space.prevent="pilih($event)"
    x-on:dragenter.prevent="masuk($event)"
    x-on:dragover.prevent="masuk($event)"
    x-on:dragleave="keluar($event)"
    x-on:drop.prevent="jatuhkan($event)"
    x-on:livewire-upload-start="mulai()"
    x-on:livewire-upload-progress="progres = $event.detail.progress"
    x-on:livewire-upload-finish="selesai()"
    x-on:livewire-upload-error="gagal()"
    x-on:livewire-upload-cancel="dibatalkan()">
    <input type="file" x-ref="input" wire:model="{{ $model }}" @if ($multiple) multiple @endif accept="{{ $accept }}" hidden>

    <div class="zu-ajak"><span aria-hidden="true">📤</span> <span x-text="diseret ? 'Lepaskan untuk mengunggah' : @js($label)">{{ $label }}</span></div>
    @unless ($ringkas)
        <div class="zu-sub">{{ $keterangan ?? ($multiple ? 'Boleh banyak berkas sekaligus, dan boleh ditambah lagi kapan saja.' : 'Satu berkas.') }}</div>
    @endunless

    <div class="zu-progres" x-show="sibuk" x-cloak><div :style="`width:${progres}%`"></div></div>

    <ul class="zu-antrean" x-show="antrean.length" x-cloak x-on:click.stop>
        <template x-for="(b, i) in antrean" :key="b.id">
            <li :class="b.status">
                <span class="zu-nama" x-text="b.nama" :title="b.nama"></span>
                <span class="zu-ukuran" x-text="ukuran(b.ukuran)"></span>
                <span class="zu-status" x-text="labelStatus(b)"></span>
                <button type="button" x-show="b.status === 'mengunggah'" x-on:click="batal()">Batalkan</button>
                <button type="button" x-show="b.status === 'gagal'" x-on:click="cobaLagi()">🔁 Coba lagi</button>
                <button type="button" x-show="b.status !== 'mengunggah'" x-on:click="buang(i)" title="Sembunyikan dari daftar ini (berkas yang sudah tersimpan TIDAK dihapus)" aria-label="Sembunyikan">✕</button>
            </li>
        </template>
    </ul>
    <div class="zu-ringkasan" x-show="ringkasan" x-cloak x-text="ringkasan"></div>
</div>
