{{--
    Dialog konfirmasi global bertema SIPINTER (dipasang sekali di layout), pengganti
    confirm() bawaan browser untuk konfirmasi yang terjadi murni di sisi klien —
    mis. ganti periode/IKU atau meninggalkan halaman saat masih ada perubahan.
    Dipanggil lewat window.sipinterKonfirmasi({...}) yang mengembalikan Promise<bool>
    (lihat resources/js/app.js). Untuk konfirmasi aksi Livewire, pakai <x-confirm-modal>.
--}}
<div x-data x-show="$store.dialog.buka" x-cloak class="modal-overlay" style="z-index:90"
    x-on:keydown.escape.window="$store.dialog.buka && $store.dialog.jawab(false)"
    x-on:click.self="$store.dialog.jawab(false)">
    <div class="modal" style="max-width:440px;height:auto" role="alertdialog" aria-modal="true"
        x-show="$store.dialog.buka" x-transition.opacity.duration.120ms>
        <div class="modal-top">
            <div class="mt-t"><span x-text="$store.dialog.bahaya ? '⚠️' : 'ℹ️'"></span> <span x-text="$store.dialog.judul"></span></div>
        </div>
        <div style="padding:18px">
            <p style="font-size:13px;color:var(--ink);line-height:1.6;margin:0 0 18px;white-space:pre-line" x-text="$store.dialog.pesan"></p>
            <div class="btn-row" style="margin-top:0;justify-content:flex-end">
                <button type="button" class="btn btn-ghost" x-on:click="$store.dialog.jawab(false)" x-text="$store.dialog.labelBatal"></button>
                <button type="button" class="btn" data-dialog-ok
                    :class="$store.dialog.bahaya ? 'btn-red' : 'btn-primary'"
                    x-on:click="$store.dialog.jawab(true)" x-text="$store.dialog.labelOk"></button>
            </div>
        </div>
    </div>
</div>
