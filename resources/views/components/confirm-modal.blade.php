{{--
    Dialog konfirmasi bertema SIPINTER, pengganti confirm() bawaan browser (yang tidak
    ikut light/dark mode). Pola: tombol "Hapus" dsb. tidak langsung memanggil aksinya —
    ia memanggil method Livewire yang hanya MENYIMPAN target (mis. $pendingDeleteId),
    lalu modal ini muncul (dikontrol lewat prop $show yang dihitung dari state itu).
    Tombol OK di modal baru memanggil aksi sungguhan; tombol Batal mengosongkan state.
--}}
@props([
    'show' => false,
    'title' => 'Konfirmasi',
    'message' => '',
    'danger' => true,
])

@if ($show)
    <div class="modal-overlay" style="z-index:70">
        <div class="modal" style="max-width:420px;height:auto">
            <div class="modal-top">
                <div class="mt-t">{{ $danger ? '⚠️' : 'ℹ️' }} {{ $title }}</div>
            </div>
            <div style="padding:18px">
                <p style="font-size:13px;color:var(--ink);line-height:1.6;margin:0 0 18px">{{ $message }}</p>
                <div class="btn-row" style="margin-top:0;justify-content:flex-end">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </div>
@endif
