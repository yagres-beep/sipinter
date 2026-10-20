{{-- Pilihan peran dan menu penempatan bagian kustom.
     Variabel: $modelPeran, $modelMenu (path wire:model), $peranDipilih, $namaPeran, $katalogMenu --}}
<div class="field">
    <label>Dapat dilihat &amp; diisi oleh peran <span class="req">*</span></label>
    <div style="display:flex;gap:16px;flex-wrap:wrap">
        @foreach ($namaPeran as $nilai => $label)
            <label style="display:flex;gap:6px;align-items:center;font-size:13px;cursor:pointer">
                <input type="checkbox" value="{{ $nilai }}" wire:model.live="{{ $modelPeran }}"> {{ $label }}
            </label>
        @endforeach
    </div>
</div>

<div class="field">
    <label>Ditampilkan di menu <span class="req">*</span></label>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:8px">
        @foreach ($katalogMenu as $kunci => $menu)
            @php $bisa = array_intersect($peranDipilih ?? [], $menu['peran']) !== []; @endphp
            <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;cursor:{{ $bisa ? 'pointer' : 'not-allowed' }};opacity:{{ $bisa ? '1' : 'max(.45,var(--op-dis-min))' }}">
                <input type="checkbox" value="{{ $kunci }}" wire:model="{{ $modelMenu }}" @disabled(! $bisa)> {{ $menu['label'] }}
            </label>
        @endforeach
    </div>
    <div class="fhint" style="margin-top:6px">Menu yang tidak bisa dibuka peran yang dipilih otomatis nonaktif. Bagian kustom tampil di bagian paling bawah menu yang dipilih.</div>
</div>
