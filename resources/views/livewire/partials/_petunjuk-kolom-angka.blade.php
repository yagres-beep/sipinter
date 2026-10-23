{{-- Petunjuk cara mengetik angka untuk kolom angka yang formatnya diatur Tim SAKIP. --}}
@if (($kolom['tipe'] ?? '') === 'angka' && (\App\Support\FormatAngkaKolom::diatur($kolom) || filled($kolom['satuan'] ?? null)))
    <span style="color:var(--muted);font-weight:500">
        ({{ \App\Support\FormatAngkaKolom::diatur($kolom)
            ? (\App\Support\FormatAngkaKolom::pemisahDari($kolom) === 'titik' ? 'pakai titik untuk desimal, mis. 12.5' : 'pakai koma untuk desimal, mis. 12,5')
            : 'angka' }}@if (filled($kolom['satuan'] ?? null)) · satuan: {{ $kolom['satuan'] }}@endif)
    </span>
@endif
