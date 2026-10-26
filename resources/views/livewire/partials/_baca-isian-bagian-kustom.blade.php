{{--
    Isian Bagian Kustom tersimpan, baca-saja, dalam bentuk yang diatur Tim SAKIP
    (BagianKustom::tampilanBaca(): daftar | tabel | paragraf) + ringkasan nilai angka.
    Nilai tampil memakai format kolom (satuan, desimal, pemisah) tanpa mengubah nilai
    tersimpan. Semua kolom (termasuk yang sudah nonaktif) ikut tampil bila berisi.

    Parameter: $bagian, $daftar (koleksi BagianKustomPoin, eager masterIku & berkas),
    $tampilkanKonteks (bool, tampilkan pengisi menu/peran — untuk pembaca),
    $kolomDitampilkan (opsional; bawaan semua kolom termasuk yang nonaktif).
--}}
@php
    $tampilkanKonteks ??= false;
    $kolomSemua = $kolomDitampilkan ?? $bagian->kolomList();
    $kolomBerisi = array_values(array_filter($kolomSemua, fn ($k) => $daftar->contains(fn ($p) => filled(($p->nilai_kolom ?? [])[$k['kunci']] ?? null))));
    $ringkasan = $bagian->ringkasanNilai($daftar);
    $bentuk = $bagian->tampilanBaca();
    $labelKonteks = fn ($p) => \App\Support\MenuBagianKustom::label($p->menu ?? \App\Support\MenuBagianKustom::PENGISIAN).' · '.(\App\Models\BagianKustom::NAMA_PERAN[$p->peran_pengisi ?? 'ketua_tim'] ?? $p->peran_pengisi);
@endphp

@if ($daftar->isEmpty())
    <p style="color:var(--muted);font-size:12.5px;margin:0">Belum ada isian pada periode ini.</p>
@elseif ($bentuk === 'tabel')
    <div class="tabel-gulir" style="overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%;min-width:0;contain:inline-size;position:relative">
        <table class="tabel-bagian-kustom" style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:480px">
            <thead>
                <tr style="text-align:left;color:var(--muted)">
                    <th scope="col" style="padding:6px;border-bottom:1px solid var(--line)">No</th>
                    <th scope="col" style="padding:6px;border-bottom:1px solid var(--line)">IKU</th>
                    <th scope="col" style="padding:6px;border-bottom:1px solid var(--line)">{{ $bagian->labelUraianEfektif() }}</th>
                    @foreach ($kolomBerisi as $k)
                        <th scope="col" style="padding:6px;border-bottom:1px solid var(--line);{{ $k['tipe'] === 'angka' ? 'text-align:right' : '' }}">{{ $k['label'] }}</th>
                    @endforeach
                    <th scope="col" style="padding:6px;border-bottom:1px solid var(--line)">Bukti</th>
                    @if ($tampilkanKonteks)<th scope="col" style="padding:6px;border-bottom:1px solid var(--line)">Diisi di</th>@endif
                </tr>
            </thead>
            <tbody>
                @foreach ($daftar as $p)
                    <tr wire:key="baca-{{ $bagian->id }}-{{ $p->id }}">
                        <td style="padding:6px;border-bottom:1px solid var(--line2)">{{ $loop->iteration }}</td>
                        <td style="padding:6px;border-bottom:1px solid var(--line2)">{{ $p->masterIku?->kode ?? '—' }}</td>
                        <td style="padding:6px;border-bottom:1px solid var(--line2);white-space:pre-line">{{ $p->teks }}</td>
                        @foreach ($kolomBerisi as $k)
                            <td style="padding:6px;border-bottom:1px solid var(--line2);white-space:pre-line;{{ $k['tipe'] === 'angka' ? 'text-align:right' : '' }}">{{ \App\Models\BagianKustom::nilaiTampil($k, ($p->nilai_kolom ?? [])[$k['kunci']] ?? '') }}</td>
                        @endforeach
                        <td style="padding:6px;border-bottom:1px solid var(--line2)">
                            @forelse ($p->berkas as $file)
                                <a href="{{ route('berkas.show', $file) }}" target="_blank" rel="noopener" style="display:block">📎 {{ $file->nama_file }}</a>
                            @empty
                                <span style="color:var(--muted)">—</span>
                            @endforelse
                        </td>
                        @if ($tampilkanKonteks)<td style="padding:6px;border-bottom:1px solid var(--line2);color:var(--muted)">{{ $labelKonteks($p) }}</td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@elseif ($bentuk === 'paragraf')
    @foreach ($daftar as $p)
        <p style="font-size:13px;line-height:1.7;margin:0 0 10px" wire:key="baca-{{ $bagian->id }}-{{ $p->id }}">
            <b>{{ $p->masterIku?->kode ?? '—' }}.</b> {{ $p->teks }}@foreach ($kolomBerisi as $k)@php $v = \App\Models\BagianKustom::nilaiTampil($k, ($p->nilai_kolom ?? [])[$k['kunci']] ?? ''); @endphp @if ($v !== '') {{ $k['label'] }}: {{ $v }}.@endif @endforeach
            @foreach ($p->berkas as $file)<a href="{{ route('berkas.show', $file) }}" target="_blank" rel="noopener">📎 {{ $file->nama_file }}</a> @endforeach
            @if ($tampilkanKonteks)<span style="color:var(--muted);font-size:11.5px">({{ $labelKonteks($p) }})</span>@endif
        </p>
    @endforeach
@else
    <ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6">
        @foreach ($daftar as $p)
            <li style="margin-bottom:8px" wire:key="baca-{{ $bagian->id }}-{{ $p->id }}">
                <b>{{ $p->masterIku?->kode ?? '—' }}</b> — <span style="white-space:pre-line">{{ $p->teks }}</span>
                @foreach ($kolomBerisi as $k)
                    @php $v = \App\Models\BagianKustom::nilaiTampil($k, ($p->nilai_kolom ?? [])[$k['kunci']] ?? ''); @endphp
                    @if ($v !== '')<div style="font-size:12px;color:var(--muted);white-space:pre-line">{{ $k['label'] }}: {{ $v }}</div>@endif
                @endforeach
                @foreach ($p->berkas as $file)
                    <a href="{{ route('berkas.show', $file) }}" target="_blank" rel="noopener" style="font-size:12px;margin-right:8px">📎 {{ $file->nama_file }}</a>
                @endforeach
                @if ($tampilkanKonteks)<div style="font-size:11.5px;color:var(--faint)">Diisi di {{ $labelKonteks($p) }}</div>@endif
            </li>
        @endforeach
    </ul>
@endif

@if ($ringkasan !== [])
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px" aria-label="Ringkasan nilai">
        @foreach ($ringkasan as $r)
            <span class="badge b-verif" title="Dihitung dari {{ $r['jumlah_data'] }} poin berisi angka">{{ $r['jenis'] }} {{ $r['label'] }}: <b>{{ $r['nilai'] }}</b></span>
        @endforeach
    </div>
@endif
