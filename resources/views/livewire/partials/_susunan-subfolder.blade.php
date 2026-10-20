{{-- Pilihan "1 folder (bawaan)" / "Pisah ke beberapa subfolder" untuk SATU folder bukti,
     dipakai bersama Kegiatan (Bagian 2) & Evaluasi RTL (Bagian 4). Nama folder bebas
     diketik sendiri; daftar "Saran" hanya pintasan.

     $metode     ['atur' => ..., 'tambah' => ..., 'hapus' => ...] — method Livewire
                 bertanda tangan (id, nilai, tahapan), mis. aturSusunanFolder / aturSusunanFolderRtl
     $argId      argumen pertama (indeks blok kegiatan / id poin RTL)
     $argTahapan literal JS tahapan (Js::from(), "null" bila tanpa tahapan)
     $kunci      awalan wire:key unik
     $pakai, $daftar, $saran, $tempat (teks lokasi folder untuk keterangan)
     $kurang     true bila mode pisah baru berisi < 2 subfolder (subfolderKurangDariDua()) --}}
<div class="pills">
    <span class="pill {{ $pakai ? '' : 'on' }}" wire:click="{{ $metode['atur'] }}({{ $argId }}, false, {{ $argTahapan }})">1 folder (bawaan)</span>
    <span class="pill {{ $pakai ? 'on' : '' }}" wire:click="{{ $metode['atur'] }}({{ $argId }}, true, {{ $argTahapan }})">Pisah ke beberapa subfolder</span>
</div>
@if ($pakai)
    <div x-data="{ namaSub: '' }" style="margin-top:8px;display:flex;gap:6px">
        <input type="text" class="inp" maxlength="100" x-model="namaSub" placeholder="Ketik nama folder sendiri, mis. Surat Tugas, Bahan Paparan…" data-abaikan-perubahan
            @keydown.enter.prevent="if (namaSub.trim()) { $wire.{{ $metode['tambah'] }}({{ $argId }}, namaSub, {{ $argTahapan }}); namaSub = '' }">
        <button type="button" class="btn btn-ghost btn-sm" style="white-space:nowrap" @click="if (namaSub.trim()) { $wire.{{ $metode['tambah'] }}({{ $argId }}, namaSub, {{ $argTahapan }}); namaSub = '' }">＋ Tambah Folder</button>
    </div>
    @if ($daftar !== [])
        <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;align-items:center">
            @foreach ($daftar as $si => $namaSub)
                <span class="pill on" wire:key="{{ $kunci }}-sub-{{ md5($namaSub) }}">📁 {{ $namaSub }}
                    <span style="cursor:pointer;margin-left:4px" title="Hapus folder" wire:click="{{ $metode['hapus'] }}({{ $argId }}, {{ $si }}, {{ $argTahapan }})">✕</span>
                </span>
            @endforeach
        </div>
    @endif
    <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;align-items:center">
        <span style="color:var(--muted);font-size:11.5px">Saran:</span>
        @foreach ($saran as $s)
            @if (! in_array(mb_strtolower($s), array_map('mb_strtolower', $daftar), true))
                <span class="pill" style="border-style:dashed" wire:click="{{ $metode['tambah'] }}({{ $argId }}, @js($s), {{ $argTahapan }})">＋ {{ $s }}</span>
            @endif
        @endforeach
    </div>
    @if ($kurang ?? false)
        <div style="color:var(--red);font-size:11.5px;margin-top:6px" role="alert">⚠️ Pilih minimal 2 folder tujuan untuk menggunakan fitur pemisahan folder — {{ $daftar === [] ? 'belum ada' : 'baru 1' }} subfolder. Tambah subfolder lagi atau pilih "1 folder (bawaan)" bila semua bukti cukup di satu folder.</div>
    @endif
    <div class="fhint">Folder dibuat di dalam folder {{ $tempat }}. Tiap folder punya tombol unggah sendiri di bawah{{ ($kurang ?? false) ? ', aktif setelah minimal 2 subfolder dibuat' : '' }}.</div>
@else
    <div class="fhint">Semua bukti masuk langsung ke folder {{ $tempat }}. Pilih "Pisah ke beberapa subfolder" untuk membuat folder sendiri (mis. Undangan, Dokumentasi).</div>
@endif
