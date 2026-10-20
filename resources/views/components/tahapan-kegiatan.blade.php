{{--
    Keterangan SEKUNDER tahapan survei/sensus di samping nama kegiatan utama — bukan
    bagian nama kegiatannya (lihat Kegiatan::namaKegiatanUtama()). Warna dari token
    tema (--ink di atas --chip-auto-bg) supaya tetap kontras di tema terang & gelap.
--}}
@props(['tahapan' => null])

@if (filled($tahapan))
    <span {{ $attributes->merge(['class' => 'tahapan-kegiatan']) }} style="display:inline-block;vertical-align:middle;white-space:nowrap;font-size:11px;font-weight:600;line-height:1.5;color:var(--ink);background:var(--chip-auto-bg);border:1px solid var(--line);border-radius:999px;padding:0 8px">Tahapan {{ \App\Livewire\PengisianKegiatan::labelTahapan($tahapan) }}</span>
@endif
