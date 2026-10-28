{{--
    Bukti dukung satu poin di menu Isian Bagian Kustom: berkas tersimpan (buka / hapus
    dengan konfirmasi), berkas baru yang dipilih, dan zona unggah sesuai aturan bagian.
    Semua aturan diperiksa ulang di server (IsianBagianKustom::simpan/hapusBukti).

    Parameter: $bagianId, $i, $aturan, $poinId (?int), $tersimpan (koleksi Berkas),
    $baru (array unggahan), $ringkas (bool).
--}}
@php
    $ringkas ??= false;
    // Hak aksi peran (matriks Data Master); bawaan = boleh. Diperiksa ulang di server.
    $bolehUnggah ??= true;
    $bolehHapus ??= true;
    $berkasTidakSesuai = $tersimpan->reject(fn ($b) => \App\Support\AturanBuktiBagianKustom::namaCocok($aturan, $b->nama_file));
@endphp
<div class="field" style="margin-bottom:0">
    @unless ($ringkas)
        <label>Bukti dukung
            @if ($aturan['mode'] === 'wajib')<span class="req">wajib{{ $aturan['min'] > 1 ? ' min. '.$aturan['min'] : '' }}</span>@else<span style="color:var(--muted);font-weight:500">opsional</span>@endif
        </label>
        <div class="fhint" style="margin:0 0 6px">{{ \App\Support\AturanBuktiBagianKustom::ringkasan($aturan) }}@if ($aturan['petunjuk']) — {{ $aturan['petunjuk'] }}@endif</div>
    @endunless

    @if ($tersimpan->isNotEmpty())
        <div class="filechip-grid">
            @foreach ($tersimpan as $file)
                <div class="filechip" wire:key="bukti-lama-{{ $file->id }}">
                    <span class="nm">
                        <a href="{{ route('berkas.show', $file) }}" target="_blank" rel="noopener">📎 {{ $file->nama_file }}</a>
                        @unless (filled($file->drive_file_id) || blank($file->path))
                            <span class="sub" style="color:var(--amber, #b45309)">Belum tersalin ke Google Drive — disalin otomatis.</span>
                        @endunless
                    </span>
                    @if ($poinId && $bolehHapus)
                        <button type="button" class="x" style="background:none;border:0;cursor:pointer" title="Hapus bukti ini" aria-label="Hapus bukti {{ $file->nama_file }}"
                            wire:click="hapusBukti({{ $bagianId }}, {{ $poinId }}, {{ $file->id }})"
                            wire:confirm="Hapus bukti &quot;{{ $file->nama_file }}&quot;? Berkas dilepas dari poin ini dan salinan Google Drive-nya dipindah ke Sampah (masih bisa dipulihkan dari Drive)."
                            wire:loading.attr="disabled" wire:target="hapusBukti({{ $bagianId }}, {{ $poinId }}, {{ $file->id }})">🗑️</button>
                    @endif
                </div>
            @endforeach
        </div>
        @if ($berkasTidakSesuai->isNotEmpty())
            <div class="info warn" style="font-size:11.5px;margin-top:6px">⚠️ {{ $berkasTidakSesuai->count() }} berkas lama tidak sesuai format yang berlaku sekarang ({{ \App\Support\AturanBuktiBagianKustom::labelFormat($aturan) }}). Berkas tetap tersimpan dan tetap bisa dibuka.</div>
        @endif
        @if ($aturan['maks'] !== null && $tersimpan->count() > $aturan['maks'])
            <div class="info warn" style="font-size:11.5px;margin-top:6px">⚠️ Jumlah bukti ({{ $tersimpan->count() }}) melebihi batas yang berlaku sekarang ({{ $aturan['maks'] }}). Berkas lama tidak dihapus, tetapi tidak bisa ditambah lagi.</div>
        @endif
    @endif

    @if (! empty($baru))
        <div class="filechip-grid">
            @foreach ($baru as $fi => $file)
                @if (is_object($file))
                    <div class="filechip" wire:key="bukti-baru-{{ $bagianId }}-{{ $i }}-{{ $fi }}">
                        <span class="nm">{{ $file->getClientOriginalName() }} <span class="sub">baru — tersimpan setelah Simpan</span></span>
                        <button type="button" class="x" style="background:none;border:0;cursor:pointer" aria-label="Batalkan berkas {{ $file->getClientOriginalName() }}" wire:click="hapusBuktiBaru({{ $bagianId }}, {{ $i }}, {{ $fi }})">✕</button>
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    @if ($bolehUnggah)
    <x-zona-unggah :model="'bukti.'.$bagianId.'.'.$i" :accept="\App\Support\AturanBuktiBagianKustom::accept($aturan)" :ekstensi="\App\Support\AturanBuktiBagianKustom::ekstensi($aturan)"
        :label="'Seret bukti ('.\App\Support\AturanBuktiBagianKustom::labelFormat($aturan).') ke sini atau klik untuk memilih'" :ringkas="$ringkas" style="margin-top:6px" />
    @endif
    @error("bukti.{$bagianId}.{$i}")
        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
    @enderror
</div>
