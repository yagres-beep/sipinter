{{--
    Kartu pengaturan satu kolom isian di builder Bagian Kustom.
    Parameter: $idBagian, $j (indeks kolom di form), $kol (baris form), $f (form bagian),
    $tipeKolom, $pilihanPemisah, $formatTanggal, $pertama, $terakhir (dalam kelompoknya).
--}}
@php
    $m = "form.{$idBagian}.kolom.{$j}";
    $tipe = $kol['tipe'] ?? 'teks';
    $galat = fn ($k) => $errors->first("{$m}.{$k}");
@endphp
<div style="border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:10px;background:var(--surface-2)" wire:key="kolom-{{ $idBagian }}-{{ $kol['kunci'] ?: 'baru-'.$j }}">
    <div class="row2">
        <div class="field" style="margin-bottom:8px">
            <label>Label kolom <span class="req">*</span></label>
            <input type="text" class="inp filled" wire:model.live.debounce.300ms="{{ $m }}.label" placeholder="mis. Level Risiko">
            @if ($galat('label'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('label') }}</div>@endif
        </div>
        <div class="field" style="margin-bottom:8px">
            <label>Jenis isian</label>
            <select class="inp filled" wire:model.live="{{ $m }}.tipe">
                @foreach ($tipeKolom as $nilai => $label)
                    <option value="{{ $nilai }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="row2">
        <div class="field" style="margin-bottom:8px">
            <label>Letak</label>
            <select class="inp filled" wire:model.live="{{ $m }}.subbagian">
                <option value="">Langsung di bagian (tanpa subbagian)</option>
                @foreach ($f['subbagian'] ?? [] as $sub)
                    <option value="{{ $sub['kunci'] }}">Subbagian: {{ $sub['nama'] ?: '(belum bernama)' }}</option>
                @endforeach
            </select>
            @if ($galat('subbagian'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('subbagian') }}</div>@endif
        </div>
        <div class="field" style="margin-bottom:8px">
            <label>Contoh isian / placeholder <span style="color:var(--muted);font-weight:500">(opsional)</span></label>
            <input type="text" class="inp filled" wire:model.blur="{{ $m }}.placeholder" placeholder="mis. Tuliskan nama risiko">
        </div>
    </div>

    <div class="field" style="margin-bottom:8px">
        <label>Petunjuk pengisian <span style="color:var(--muted);font-weight:500">(opsional, tampil di bawah label)</span></label>
        <input type="text" class="inp filled" wire:model.blur="{{ $m }}.petunjuk" placeholder="mis. Isi sesuai register risiko terbaru">
    </div>

    @if (in_array($tipe, ['pilihan', 'pilihan_ganda'], true))
        <div class="field" style="margin-bottom:8px">
            <label>Pilihan jawaban <span style="color:var(--muted);font-weight:500">(pisahkan dengan koma)</span></label>
            <input type="text" class="inp filled" wire:model.live.debounce.300ms="{{ $m }}.opsi_teks" placeholder="mis. Rendah, Sedang, Tinggi">
            @if ($galat('opsi_teks'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('opsi_teks') }}</div>@endif
        </div>
    @endif

    @if ($tipe === 'angka')
        @php $kolomContoh = \App\Models\BagianKustom::normalkanKolom(['kunci' => 'contoh', 'label' => 'contoh'] + $kol); @endphp
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-bottom:8px">
            <div class="field" style="margin-bottom:0">
                <label>Satuan</label>
                <input type="text" class="inp filled" wire:model.live.debounce.300ms="{{ $m }}.satuan" placeholder="mis. persen, orang">
                @if ($galat('satuan'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('satuan') }}</div>@endif
            </div>
            <div class="field" style="margin-bottom:0">
                <label>Angka di belakang koma</label>
                <select class="inp filled" wire:model.live="{{ $m }}.desimal">
                    <option value="">Tampil apa adanya</option>
                    @for ($d = 0; $d <= \App\Support\FormatAngkaKolom::DESIMAL_MAKS; $d++)
                        <option value="{{ $d }}">{{ $d }} desimal</option>
                    @endfor
                </select>
            </div>
            @if (($kol['desimal'] ?? '') !== '')
                <div class="field" style="margin-bottom:0">
                    <label>Pemisah desimal</label>
                    <select class="inp filled" wire:model.live="{{ $m }}.pemisah">
                        @foreach ($pilihanPemisah as $nilai => $label)
                            <option value="{{ $nilai }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;align-self:end;padding-bottom:10px">
                    <input type="checkbox" wire:model.live="{{ $m }}.ribuan"> Pakai pemisah ribuan
                </label>
            @endif
            <div class="field" style="margin-bottom:0">
                <label>Nilai minimal</label>
                <input type="text" inputmode="decimal" class="inp filled" wire:model.blur="{{ $m }}.min" placeholder="kosong = bebas">
                @if ($galat('min'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('min') }}</div>@endif
            </div>
            <div class="field" style="margin-bottom:0">
                <label>Nilai maksimal</label>
                <input type="text" inputmode="decimal" class="inp filled" wire:model.blur="{{ $m }}.maks" placeholder="kosong = bebas">
                @if ($galat('maks'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('maks') }}</div>@endif
            </div>
        </div>
        <div class="fhint" style="margin:0 0 8px">Contoh tampilan: <b>{{ \App\Support\TemplateBagianKustom::contohKolom($kolomContoh) }}</b>. Format hanya mengubah tampilan — angka yang sudah tersimpan tidak diubah atau dibulatkan.</div>
    @endif

    @if (in_array($tipe, ['teks', 'teks_panjang'], true))
        <div class="row2">
            <div class="field" style="margin-bottom:8px">
                <label>Panjang minimal (karakter)</label>
                <input type="number" min="0" max="5000" class="inp filled" wire:model.blur="{{ $m }}.panjang_min" placeholder="kosong = bebas">
                @if ($galat('panjang_min'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('panjang_min') }}</div>@endif
            </div>
            <div class="field" style="margin-bottom:8px">
                <label>Panjang maksimal (karakter)</label>
                <input type="number" min="1" max="5000" class="inp filled" wire:model.blur="{{ $m }}.panjang_maks" placeholder="bawaan {{ \App\Support\JenisKolomBagianKustom::PANJANG_MAKS_BAWAAN[$tipe] }}">
                @if ($galat('panjang_maks'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('panjang_maks') }}</div>@endif
            </div>
        </div>
    @endif

    @if ($tipe === 'tanggal')
        <div class="field" style="margin-bottom:8px">
            <label>Format tanggal di tampilan &amp; notula</label>
            <select class="inp filled" wire:model.live="{{ $m }}.format_tanggal">
                @foreach ($formatTanggal as $nilai => $contoh)
                    <option value="{{ $nilai }}">{{ $contoh }}</option>
                @endforeach
            </select>
            <div class="fhint" style="margin-top:4px">Tanggal disimpan sebagai tahun-bulan-hari; format hanya mengatur tampilannya.</div>
        </div>
    @endif

    <div class="row2">
        <div class="field" style="margin-bottom:8px">
            <label>Mulai berlaku <span style="color:var(--muted);font-weight:500">(kosong = sejak awal)</span></label>
            <div style="display:flex;gap:6px">
                <select class="inp filled" wire:model="{{ $m }}.mulai_triwulan" style="flex:1">
                    <option value="">—</option>
                    @foreach (['I', 'II', 'III', 'IV'] as $n => $romawi)
                        <option value="{{ $n + 1 }}">TW {{ $romawi }}</option>
                    @endforeach
                </select>
                <input type="number" class="inp filled" style="flex:1" min="2020" max="2100" wire:model="{{ $m }}.mulai_tahun" placeholder="Tahun">
            </div>
            @if ($galat('mulai_triwulan'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('mulai_triwulan') }}</div>@endif
            @if ($galat('mulai_tahun'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('mulai_tahun') }}</div>@endif
        </div>
        <div class="field" style="margin-bottom:8px">
            <label>Pesan bila isian tidak sesuai aturan <span style="color:var(--muted);font-weight:500">(opsional)</span></label>
            <input type="text" class="inp filled" wire:model.blur="{{ $m }}.pesan" placeholder="kosong = pesan bawaan">
        </div>
    </div>

    @if (! ($kol['aktif'] ?? true))
        <div class="info warn" style="font-size:11.5px;margin-bottom:8px">Kolom nonaktif: tidak lagi tampil di formulir isian. Data yang sudah tersimpan tetap ada, tetap tampil di Verifikasi, dan tetap tercetak di notula bila variabelnya dipakai.</div>
    @endif

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between">
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
            <input type="checkbox" wire:model="{{ $m }}.wajib"> Wajib diisi
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
            <input type="checkbox" wire:model.live="{{ $m }}.aktif"> Aktif
        </label>
        <span style="font-size:11px;color:var(--muted)">Variabel: <code>{{ $kol['kunci'] ?? '' }}</code>@if (! ($kol['kunci'] ?? null)) <i>(dibuat otomatis saat disimpan)</i>@endif</span>
        <div class="btn-row" style="margin-top:0;gap:4px">
            <button type="button" class="btn btn-ghost btn-sm" wire:click="naikkanKolom({{ $idBagian }}, {{ $j }})" @disabled($pertama) aria-label="Naikkan kolom">↑</button>
            <button type="button" class="btn btn-ghost btn-sm" wire:click="turunkanKolom({{ $idBagian }}, {{ $j }})" @disabled($terakhir) aria-label="Turunkan kolom">↓</button>
            <button type="button" class="btn btn-red btn-sm" wire:click="hapusKolom({{ $idBagian }}, {{ $j }})" aria-label="Hapus kolom">🗑</button>
        </div>
    </div>
</div>
