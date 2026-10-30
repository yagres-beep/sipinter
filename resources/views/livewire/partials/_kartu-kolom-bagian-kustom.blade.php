{{--
    Kartu pengaturan satu kolom isian di builder Bagian Kustom.
    Parameter: $idBagian, $j (indeks kolom di form), $kol (baris form), $f (form bagian),
    $tipeKolom, $pilihanPemisah, $formatTanggal, $pertama, $terakhir (dalam kelompoknya).
--}}
@php
    $m = "form.{$idBagian}.kolom.{$j}";
    $katalogVariabel ??= collect();
    $varTerpilih = filled($kol['variabel'] ?? null) ? $katalogVariabel->firstWhere('kode', $kol['variabel']) : null;
    // Kolom bertaut katalog: jenis, opsi, format, dan batas mengikuti katalog.
    if ($varTerpilih) {
        $def = $varTerpilih->definisiKolom();
        $kol = array_merge($kol, ['tipe' => $def['tipe'], 'opsi_teks' => implode(', ', $def['opsi']), 'satuan' => (string) $def['satuan'], 'desimal' => $def['desimal'] !== null ? (string) $def['desimal'] : '', 'pemisah' => $def['pemisah'], 'ribuan' => $def['ribuan']]);
    }
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
            @if ($varTerpilih)
                <div class="inp filled" style="color:var(--muted)">{{ $tipeKolom[$tipe] ?? $tipe }} <span style="font-size:11px">(mengikuti katalog)</span></div>
            @else
                <select class="inp filled" wire:model.live="{{ $m }}.tipe">
                    @foreach ($tipeKolom as $nilai => $label)
                        <option value="{{ $nilai }}">{{ $label }}</option>
                    @endforeach
                </select>
            @endif
        </div>
    </div>

    <div class="field" style="margin-bottom:8px" x-data="{ cariKatalog: '' }" data-uji="pemilih-variabel-kolom">
        <label>Variabel katalog <span style="color:var(--muted);font-weight:500">(opsional — pakai definisi bersama)</span></label>
        @if ($katalogVariabel->isNotEmpty() || filled($kol['variabel'] ?? null))
            @php
                $kelompokKatalog = $katalogVariabel
                    ->filter(fn ($v) => $v->aktif || ($kol['variabel'] ?? null) === $v->kode)
                    ->groupBy(fn ($v) => \App\Support\RegistriVariabel::KATEGORI[$v->kategori ?: \App\Models\VariabelKatalog::KATEGORI_BAWAAN] ?? \App\Support\RegistriVariabel::KATEGORI['bagian_kustom']);
            @endphp
            @if ($katalogVariabel->count() > 6)
                <input type="search" class="inp filled" style="margin-bottom:6px;height:32px;font-size:12px" x-model="cariKatalog" placeholder="Cari kode atau nama variabel" aria-label="Cari variabel katalog">
            @endif
            <select class="inp filled" wire:model.live="{{ $m }}.variabel">
                <option value="">Tidak — kolom lokal bagian ini</option>
                @foreach ($kelompokKatalog as $namaKategori => $isiKategori)
                    <optgroup label="{{ $namaKategori }}">
                        @foreach ($isiKategori as $v)
                            <option value="{{ $v->kode }}" x-bind:hidden="cariKatalog !== '' && ! @js(mb_strtolower($v->kode.' '.$v->nama)).includes(cariKatalog.toLowerCase())">{{ $v->nama }} ({{ $v->kode }}){{ $v->aktif ? '' : ' — nonaktif' }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            @if ($galat('variabel'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('variabel') }}</div>@endif
            @if ($varTerpilih)
                <div style="margin-top:6px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:12px;line-height:1.55" data-uji="info-variabel-kolom">
                    <div><b>{{ $varTerpilih->nama }}</b> <code>{{ $varTerpilih->kode }}</code>
                        @if (! $varTerpilih->aktif)<span class="badge b-tunggu">nonaktif — hanya untuk kolom lama</span>@endif
                        @if ($varTerpilih->pengganti_kode)<span class="badge b-tunggu">disarankan diganti {{ $varTerpilih->pengganti_kode }}</span>@endif
                    </div>
                    @if ($varTerpilih->keterangan)<div>{{ $varTerpilih->keterangan }}</div>@endif
                    <div><span style="color:var(--muted)">Jenis data:</span> {{ $tipeKolom[$varTerpilih->tipe] ?? $varTerpilih->tipe }} · {{ $varTerpilih->ringkasan() }}</div>
                    <div><span style="color:var(--muted)">Sumber:</span> {{ \App\Models\VariabelKatalog::SUMBER[$varTerpilih->sumber] ?? \App\Models\VariabelKatalog::SUMBER['isian'] }}</div>
                    <div><span style="color:var(--muted)">Contoh:</span> {{ $varTerpilih->contoh ?: \App\Support\TemplateBagianKustom::contohKolom(\App\Models\BagianKustom::normalkanKolom(['kunci' => $varTerpilih->kode, 'label' => $varTerpilih->nama] + $varTerpilih->definisiKolom())) }}</div>
                    @if ($varTerpilih->catatan)<div><span style="color:var(--muted)">Aturan:</span> {{ $varTerpilih->catatan }}</div>@endif
                    <div style="color:var(--muted)">Jenis, pilihan, satuan, format angka, dan batas nilai mengikuti katalog. Label, wajib, letak, dan petunjuk tetap diatur di sini. Nilai tetap tersimpan per isian bagian ini dan tersedia di format notula sebagai <code>{{ '{'.($kol['kunci'] ?: 'kode_kolom').'}' }}</code>.</div>
                </div>
            @endif
        @else
            <div class="fhint" style="margin-top:0">Belum ada definisi bersama. Buat di Data Master → Katalog Variabel bila beberapa bagian butuh kolom dengan aturan yang sama.</div>
        @endif
        <div class="fhint" style="margin-top:4px">Nilai yang sudah ada di sistem (kode/indikator/sasaran IKU, tahun, triwulan, bulan) tidak perlu dijadikan kolom isian — pakai variabelnya langsung di tab Notula &amp; Variabel.</div>
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

    @if ($varTerpilih)
        @if ($tipe === 'angka')
            <div class="field" style="margin-bottom:8px;max-width:320px">
                <label>Ringkasan di tampilan tabel</label>
                <select class="inp filled" wire:model.live="{{ $m }}.ringkasan">
                    <option value="">Tidak ada</option>
                    @foreach (\App\Models\BagianKustom::RINGKASAN as $nilai => $label)
                        <option value="{{ $nilai }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    @else
    @if (in_array($tipe, ['pilihan', 'pilihan_ganda'], true))
        <div class="field" style="margin-bottom:8px">
            <label>Pilihan jawaban <span style="color:var(--muted);font-weight:500">(pisahkan dengan koma)</span></label>
            <input type="text" class="inp filled" wire:model.live.debounce.300ms="{{ $m }}.opsi_teks" placeholder="mis. Rendah, Sedang, Tinggi">
            @if ($galat('opsi_teks'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('opsi_teks') }}</div>@endif
        </div>
    @endif

    @if ($tipe === 'kode')
        <div class="field" style="margin-bottom:8px;max-width:420px">
            <label>Pola isian</label>
            <select class="inp filled" wire:model.live="{{ $m }}.pola">
                @foreach (\App\Support\JenisKolomBagianKustom::POLA_KODE as $nilai => $label)
                    <option value="{{ $nilai }}">{{ $label }}</option>
                @endforeach
            </select>
            @if ($galat('pola'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('pola') }}</div>@endif
            <div class="fhint" style="margin-top:4px">Disimpan sebagai teks, jadi nol di depan (mis. 0012) tidak hilang.</div>
        </div>
    @endif

    @if ($tipe === 'referensi')
        <div class="field" style="margin-bottom:8px;max-width:420px">
            <label>Sumber data</label>
            <select class="inp filled" wire:model.live="{{ $m }}.sumber">
                @foreach (\App\Support\ReferensiBagianKustom::SUMBER as $nilai => $label)
                    <option value="{{ $nilai }}">{{ $label }}</option>
                @endforeach
            </select>
            @if ($galat('sumber'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('sumber') }}</div>@endif
            <div class="fhint" style="margin-top:4px">Pengisi memilih dari data yang sudah ada ({{ count(\App\Support\ReferensiBagianKustom::opsi((string) ($kol['sumber'] ?? 'iku'))) }} data saat ini). Yang disimpan identitasnya, jadi mengganti nama IKU/pengguna tidak memutus isian lama.</div>
        </div>
    @endif

    @if ($tipe === 'informasi')
        <div class="field" style="margin-bottom:8px">
            <label>Isi teks informasi <span class="req">*</span></label>
            <textarea class="inp filled" style="height:auto;display:block" rows="3" maxlength="2000" wire:model.blur="{{ $m }}.isi_info" placeholder="mis. Isi register risiko sesuai Pedoman Manajemen Risiko BPS 2025."></textarea>
            @if ($galat('isi_info'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('isi_info') }}</div>@endif
            <div class="fhint" style="margin-top:4px">Hanya ditampilkan di formulir sebagai petunjuk — tidak diisi, tidak disimpan, dan tidak masuk notula.</div>
        </div>
    @endif

    @if ($tipe === 'hitung')
        @php $varAngka = collect($f['kolom'] ?? [])->filter(fn ($k) => filled($k['kunci'] ?? null) && \App\Support\JenisKolomBagianKustom::angkaIsian($k['tipe'] ?? ''))->values(); @endphp
        <div class="field" style="margin-bottom:8px">
            <label>Rumus <span class="req">*</span></label>
            <input type="text" class="inp filled" style="font-family:monospace" wire:model.live.debounce.400ms="{{ $m }}.rumus" placeholder="mis. {realisasi} / {target} * 100">
            @if ($galat('rumus'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('rumus') }}</div>@endif
            <div class="fhint" style="margin-top:4px">Pakai + − × (*) ÷ (/) dan tanda kurung. Variabel yang tersedia (kolom Angka/Persentase yang sudah disimpan):
                @forelse ($varAngka as $v)<code>{{ '{'.$v['kunci'].'}' }}</code> ({{ $v['label'] }})@if (! $loop->last), @endif @empty <i>belum ada — tambahkan kolom Angka lalu simpan dulu</i>@endforelse.
                Hasil dihitung server saat poin disimpan; bila salah satu nilai kosong atau pembagian dengan nol, hasilnya kosong.</div>
        </div>
    @endif

    @if ($tipe === 'tabel')
        <div class="field" style="margin-bottom:8px" data-editor-tabel="{{ $j }}">
            <label>Kolom di dalam tabel <span class="req">*</span></label>
            @if ($galat('tabel_kolom'))<div style="color:var(--red);font-size:11.5px;margin-bottom:4px">{{ $galat('tabel_kolom') }}</div>@endif
            @foreach ($kol['tabel_kolom'] ?? [] as $si => $sk)
                <div style="display:grid;grid-template-columns:minmax(120px,2fr) minmax(100px,1fr) auto auto;gap:6px;align-items:start;margin-bottom:6px" wire:key="subkolom-{{ $idBagian }}-{{ $j }}-{{ $sk['kunci'] ?: 'baru-'.$si }}">
                    <div>
                        <input type="text" class="inp filled" aria-label="Nama kolom tabel {{ $si + 1 }}" wire:model.live.debounce.300ms="{{ $m }}.tabel_kolom.{{ $si }}.label" placeholder="mis. Nama kegiatan">
                        @if ($galat("tabel_kolom.{$si}.label"))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat("tabel_kolom.{$si}.label") }}</div>@endif
                        @if (($sk['tipe'] ?? '') === 'pilihan')
                            <input type="text" class="inp filled" style="margin-top:4px" aria-label="Pilihan kolom tabel {{ $si + 1 }}" wire:model.blur="{{ $m }}.tabel_kolom.{{ $si }}.opsi_teks" placeholder="Pilihan, pisahkan koma">
                            @if ($galat("tabel_kolom.{$si}.opsi_teks"))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat("tabel_kolom.{$si}.opsi_teks") }}</div>@endif
                        @endif
                    </div>
                    <select class="inp filled" aria-label="Jenis kolom tabel {{ $si + 1 }}" wire:model.live="{{ $m }}.tabel_kolom.{{ $si }}.tipe">
                        @foreach ($tipeSubkolom as $nilai => $label)
                            <option value="{{ $nilai }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <label style="display:flex;align-items:center;gap:4px;font-size:12px;padding-top:9px;cursor:pointer"><input type="checkbox" wire:model="{{ $m }}.tabel_kolom.{{ $si }}.wajib"> Wajib</label>
                    <div class="btn-row" style="margin-top:0;gap:2px">
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="geserKolomTabel({{ $idBagian }}, {{ $j }}, {{ $si }}, -1)" @disabled($si === 0) aria-label="Geser kiri">←</button>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="geserKolomTabel({{ $idBagian }}, {{ $j }}, {{ $si }}, 1)" @disabled($si === count($kol['tabel_kolom']) - 1) aria-label="Geser kanan">→</button>
                        <button type="button" class="btn btn-red btn-sm" wire:click="hapusKolomTabel({{ $idBagian }}, {{ $j }}, {{ $si }})" aria-label="Hapus kolom tabel">🗑</button>
                    </div>
                </div>
            @endforeach
            <button type="button" class="btn btn-ghost btn-sm" wire:click="tambahKolomTabel({{ $idBagian }}, {{ $j }})">＋ Kolom tabel</button>
        </div>
        <div class="row2">
            <div class="field" style="margin-bottom:8px">
                <label>Jumlah baris minimal</label>
                <input type="number" min="0" max="{{ \App\Support\JenisKolomBagianKustom::BARIS_TABEL_MAKS }}" class="inp filled" wire:model.blur="{{ $m }}.baris_min" placeholder="kosong = bebas">
                @if ($galat('baris_min'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('baris_min') }}</div>@endif
            </div>
            <div class="field" style="margin-bottom:8px">
                <label>Jumlah baris maksimal</label>
                <input type="number" min="1" max="{{ \App\Support\JenisKolomBagianKustom::BARIS_TABEL_MAKS }}" class="inp filled" wire:model.blur="{{ $m }}.baris_maks" placeholder="kosong = {{ \App\Support\JenisKolomBagianKustom::BARIS_TABEL_MAKS }}">
                @if ($galat('baris_maks'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('baris_maks') }}</div>@endif
            </div>
        </div>
        <div class="fhint" style="margin:-4px 0 8px">Pengisi bisa menambah dan menghapus baris sendiri. Baris yang seluruhnya kosong tidak disimpan. Di notula, tabel dicetak per baris: "1) Kolom: isi; … | 2) …".</div>
    @endif

    @if (\App\Support\JenisKolomBagianKustom::numerik($tipe))
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
            @if ($tipe !== 'hitung')
            <div class="field" style="margin-bottom:0">
                <label>Nilai minimal</label>
                <input type="text" inputmode="decimal" class="inp filled" wire:model.blur="{{ $m }}.min" placeholder="{{ $tipe === 'persentase' ? 'mis. 0' : 'kosong = bebas' }}">
                @if ($galat('min'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('min') }}</div>@endif
            </div>
            <div class="field" style="margin-bottom:0">
                <label>Nilai maksimal</label>
                <input type="text" inputmode="decimal" class="inp filled" wire:model.blur="{{ $m }}.maks" placeholder="{{ $tipe === 'persentase' ? 'mis. 100' : 'kosong = bebas' }}">
                @if ($galat('maks'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('maks') }}</div>@endif
            </div>
            @endif
            @if (in_array($tipe, ['angka', 'mata_uang'], true))
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;align-self:end;padding-bottom:10px">
                    <input type="checkbox" wire:model.live="{{ $m }}.bulat"> Hanya bilangan bulat
                </label>
            @endif
            <div class="field" style="margin-bottom:0">
                <label>Ringkasan nilai</label>
                <select class="inp filled" wire:model.live="{{ $m }}.ringkasan">
                    <option value="">Tidak ada</option>
                    @foreach (\App\Models\BagianKustom::RINGKASAN as $nilai => $label)
                        <option value="{{ $nilai }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="fhint" style="margin:0 0 8px">Contoh tampilan: <b>{{ \App\Support\TemplateBagianKustom::contohKolom($kolomContoh) }}</b>. Format hanya mengubah tampilan — angka yang sudah tersimpan tidak diubah atau dibulatkan.@if ($tipe === 'mata_uang') Satuan = simbol mata uang di depan angka (bawaan Rp).@endif</div>
    @endif

    @if (\App\Support\JenisKolomBagianKustom::berpanjang($tipe))
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

    @if (in_array($tipe, ['tanggal', 'tanggal_waktu'], true))
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
    @endif {{-- /kolom lokal (tanpa katalog) --}}

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

    {{-- Syarat tampil: kolom ini hanya tampil (dan divalidasi) bila kolom lain bernilai tertentu. --}}
    @php
        $acuanSyarat = collect($f['kolom'] ?? [])->filter(fn ($k) => filled($k['kunci'] ?? null) && ($k['kunci'] ?? null) !== ($kol['kunci'] ?? null) && \App\Support\JenisKolomBagianKustom::bisaJadiAcuan($k['tipe'] ?? ''))->values();
        $acuanDipilih = $acuanSyarat->firstWhere('kunci', $kol['syarat_kunci'] ?? '');
        $opSyarat = $kol['syarat_operator'] ?? 'sama';
        // Pilihan nilai pembanding untuk acuan berpilihan (operator sama / tidak sama).
        $opsiSyarat = match ($acuanDipilih['tipe'] ?? '') {
            'ya_tidak' => \App\Support\JenisKolomBagianKustom::YA_TIDAK,
            'pilihan', 'pilihan_ganda' => collect(array_filter(array_map('trim', preg_split('/[,\n]+/', (string) ($acuanDipilih['opsi_teks'] ?? '')))))->mapWithKeys(fn ($o) => [$o => $o])->all(),
            default => [],
        };
        $pakaiDaftarSyarat = $opsiSyarat !== [] && in_array($opSyarat, ['sama', 'tidak_sama'], true);
    @endphp
    <div class="field" style="margin-bottom:8px">
        <label>Tampilkan kolom ini <span style="color:var(--muted);font-weight:500">(keterlihatan bersyarat)</span></label>
        <div style="display:grid;grid-template-columns:minmax(140px,2fr) minmax(120px,1fr) minmax(120px,1.5fr);gap:6px">
            <select class="inp filled" aria-label="Kolom acuan syarat" wire:model.live="{{ $m }}.syarat_kunci">
                <option value="">Selalu tampil</option>
                @foreach ($acuanSyarat as $a)
                    <option value="{{ $a['kunci'] }}">Bila "{{ $a['label'] }}"</option>
                @endforeach
            </select>
            @if (filled($kol['syarat_kunci'] ?? null))
                <select class="inp filled" aria-label="Operator syarat" wire:model.live="{{ $m }}.syarat_operator">
                    @foreach ($operatorSyarat as $nilai => $label)
                        <option value="{{ $nilai }}">{{ $label }}</option>
                    @endforeach
                </select>
                @if (! in_array($opSyarat, \App\Models\BagianKustom::OPERATOR_TANPA_NILAI, true))
                    @if ($pakaiDaftarSyarat)
                        <select class="inp filled" aria-label="Nilai syarat" wire:model.live="{{ $m }}.syarat_nilai">
                            <option value="">— pilih —</option>
                            @foreach ($opsiSyarat as $o => $labelO)
                                <option value="{{ $o }}">{{ $labelO }}</option>
                            @endforeach
                        </select>
                    @else
                        <input type="text" class="inp filled" aria-label="Nilai syarat" wire:model.blur="{{ $m }}.syarat_nilai"
                            placeholder="{{ match ($acuanDipilih['tipe'] ?? '') { 'tanggal' => 'mis. 2026-06-30', 'waktu' => 'mis. 14:30', 'angka', 'persentase', 'mata_uang' => 'mis. 50', default => 'nilai pembanding' } }}">
                    @endif
                @endif
            @endif
        </div>
        @if ($galat('syarat_kunci'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('syarat_kunci') }}</div>@endif
        @if ($galat('syarat_operator'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('syarat_operator') }}</div>@endif
        @if ($galat('syarat_nilai'))<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $galat('syarat_nilai') }}</div>@endif
        <div class="fhint" style="margin-top:4px">Kolom yang tersembunyi tidak wajib diisi dan isiannya tidak disimpan. Kolom acuan harus sudah disimpan (punya kode variabel).</div>
    </div>

    @if (! ($kol['aktif'] ?? true))
        <div class="info warn" style="font-size:11.5px;margin-bottom:8px">Kolom nonaktif: tidak lagi tampil di formulir isian. Data yang sudah tersimpan tetap ada, tetap tampil di Verifikasi, dan tetap tercetak di notula bila variabelnya dipakai.</div>
    @endif

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between">
        @if (! \App\Support\JenisKolomBagianKustom::tanpaIsian($tipe))
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
            <input type="checkbox" wire:model="{{ $m }}.wajib"> Wajib diisi{{ $tipe === 'tabel' ? ' (minimal satu baris)' : '' }}
        </label>
        @endif
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
            <input type="checkbox" wire:model.live="{{ $m }}.aktif"> Aktif
        </label>
        <span style="font-size:11px;color:var(--muted)">Variabel: <code>{{ $kol['kunci'] ?? '' }}</code>@if (! ($kol['kunci'] ?? null)) <i>(dibuat otomatis saat disimpan)</i>@endif</span>
        <div class="btn-row" style="margin-top:0;gap:4px">
            <button type="button" class="btn btn-ghost btn-sm" wire:click="naikkanKolom({{ $idBagian }}, {{ $j }})" @disabled($pertama) aria-label="Naikkan kolom">↑</button>
            <button type="button" class="btn btn-ghost btn-sm" wire:click="turunkanKolom({{ $idBagian }}, {{ $j }})" @disabled($terakhir) aria-label="Turunkan kolom">↓</button>
            <button type="button" class="btn btn-ghost btn-sm" wire:click="duplikasiKolom({{ $idBagian }}, {{ $j }})" title="Salin kolom ini (variabel baru dibuat saat disimpan)" aria-label="Duplikasi kolom">⧉</button>
            <button type="button" class="btn btn-red btn-sm" wire:click="hapusKolom({{ $idBagian }}, {{ $j }})" aria-label="Hapus kolom">🗑</button>
        </div>
    </div>
</div>
