{{--
    Kolom isian satu poin Bagian Kustom, dikelompokkan per subbagian — dibentuk dari
    konfigurasi di database (BagianKustom::kelompokKolomBerlaku()). Dipakai Isian
    Kegiatan, Isian Bagian Kustom, dan Pratinjau di Data Master.

    Parameter: $kelompok (hasil kelompokKolomBerlaku), $model (awalan wire:model, mis.
    "baris.5.0.kolom"). Galat dibaca dari kunci "{$model}.{kunci}".
    $ringkas (opsional) = tanpa label/judul subbagian, untuk sel tabel (label lewat aria-label).
    $nilai (opsional) = nilai formulir saat ini (kunci => nilai), untuk hasil rumus.

    Syarat tampil (kolom/subbagian) dievaluasi di browser dari nilai formulir terkini
    ($wire), dan diperiksa ulang di server (BagianKustom::galatNilaiPoin()) — kolom yang
    tersembunyi tidak divalidasi dan tidak disimpan.
--}}
@php
    $ringkas ??= false;
    $nilai ??= [];
    $semuaKolom = collect($kelompok)->flatMap(fn ($g) => $g['kolom'])->values()->all();
    // Fungsi pencocok syarat (sama dengan BagianKustom::syaratTerpenuhi()).
    $fnSyarat = "{ syaratOk(v, op, t) { if (v === null || v === undefined) v = ''; if (typeof v !== 'string') v = Array.isArray(v) ? JSON.stringify(v) : String(v); v = v.trim(); let daftar = []; if (v !== '' && v !== '[]') { try { const a = JSON.parse(v); daftar = Array.isArray(a) ? a.map(x => String(x).trim()) : [v]; } catch (e) { daftar = [v]; } } if (op === 'terisi') return daftar.length > 0; const cocok = daftar.includes(t); return op === 'sama' ? cocok : ! cocok; } }";
@endphp
@foreach ($kelompok as $grup)
    @php $syaratSub = $grup['subbagian']['tampil_jika'] ?? null; @endphp
    <div @if ($syaratSub) x-data="{{ $fnSyarat }}" x-show="syaratOk($wire.get($el.dataset.sj), $el.dataset.so, $el.dataset.sn)" data-sj="{{ $model }}.{{ $syaratSub['kunci'] }}" data-so="{{ $syaratSub['operator'] }}" data-sn="{{ $syaratSub['nilai'] }}" @endif @if ($ringkas) style="display:contents" @endif>
    @if ($grup['subbagian'] && ! $ringkas)
        <div class="sub-bagian-kustom" style="margin:14px 0 8px;padding-top:10px;border-top:1px dashed var(--line)">
            <div style="font-weight:700;font-size:12.5px">{{ $grup['subbagian']['nama'] }}@if ($grup['subbagian']['wajib'] ?? false) <span class="req" title="Minimal satu isian di subbagian ini wajib diisi">*</span>@endif</div>
            @if ($grup['subbagian']['deskripsi'])
                <div class="fhint" style="margin:2px 0 0">{{ $grup['subbagian']['deskripsi'] }}</div>
            @endif
        </div>
    @endif

    @foreach ($grup['kolom'] as $kolom)
        @php
            $jalur = $model.'.'.$kolom['kunci'];
            $idKolom = 'kol-'.str_replace('.', '-', $jalur);
            $syarat = $kolom['tampil_jika'] ?? null;
        @endphp
        <div class="field" @if ($ringkas) style="margin-bottom:0;min-width:140px" @endif
            @if ($syarat) x-data="{{ $fnSyarat }}" x-show="syaratOk($wire.get($el.dataset.sj), $el.dataset.so, $el.dataset.sn)" data-sj="{{ $model }}.{{ $syarat['kunci'] }}" data-so="{{ $syarat['operator'] }}" data-sn="{{ $syarat['nilai'] }}" @endif>
            @if ($ringkas)
                <label for="{{ $idKolom }}" class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)">{{ $kolom['label'] }}</label>
            @else
            <label for="{{ $idKolom }}">{{ $kolom['label'] }}
                @if ($kolom['tipe'] === 'hitung')<span style="color:var(--muted);font-weight:500">otomatis</span>@elseif ($kolom['wajib'])<span class="req">*</span>@else<span style="color:var(--muted);font-weight:500">opsional</span>@endif
                @if ($kolom['tipe'] !== 'hitung')
                    @include('livewire.partials._petunjuk-kolom-angka', ['kolom' => \App\Support\JenisKolomBagianKustom::sebagaiAngka($kolom)])
                @endif
            </label>
            @endif
            @if (($kolom['petunjuk'] ?? null) && ! $ringkas)
                <div class="fhint" style="margin:0 0 6px">{{ $kolom['petunjuk'] }}</div>
            @endif

            @switch($kolom['tipe'])
                @case('pilihan')
                    {{-- .change: nilai langsung terbaca syarat tampil kolom lain tanpa menunggu blur. --}}
                    <select id="{{ $idKolom }}" class="inp filled" wire:model.change="{{ $jalur }}">
                        <option value="">{{ $kolom['placeholder'] ?? '— pilih —' }}</option>
                        @foreach ($kolom['opsi'] as $opsi)
                            <option value="{{ $opsi }}">{{ $opsi }}</option>
                        @endforeach
                    </select>
                    @break

                @case('pilihan_ganda')
                    {{-- Nilai formulir tetap teks (JSON daftar pilihan), diatur kotak centang ini. --}}
                    <div id="{{ $idKolom }}" role="group" aria-label="{{ $kolom['label'] }}" style="display:flex;flex-wrap:wrap;gap:6px 14px;padding:6px 0"
                        x-data="{
                            nilai: $wire.entangle('{{ $jalur }}'),
                            get dipilih() {
                                if (! this.nilai) return [];
                                try { const a = JSON.parse(this.nilai); return Array.isArray(a) ? a : [String(a)]; } catch (e) { return [this.nilai]; }
                            },
                            ubah(opsi, nyala) {
                                const a = this.dipilih.filter(x => x !== opsi);
                                if (nyala) a.push(opsi);
                                this.nilai = a.length ? JSON.stringify(a) : '';
                            },
                        }">
                        @foreach ($kolom['opsi'] as $opsi)
                            <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;cursor:pointer">
                                <input type="checkbox" value="{{ $opsi }}" :checked="dipilih.includes(@js($opsi))" @change="ubah(@js($opsi), $event.target.checked)">
                                {{ $opsi }}
                            </label>
                        @endforeach
                    </div>
                    @break

                @case('angka')
                    <input id="{{ $idKolom }}" type="text" inputmode="decimal" class="inp filled" wire:model.blur="{{ $jalur }}" placeholder="{{ $kolom['placeholder'] ?? 'Angka' }}">
                    @break

                @case('persentase')
                    <div style="display:flex;align-items:center;gap:6px">
                        <input id="{{ $idKolom }}" type="text" inputmode="decimal" class="inp filled" style="flex:1" wire:model.blur="{{ $jalur }}" placeholder="{{ $kolom['placeholder'] ?? 'mis. 87,5' }}">
                        <span style="font-weight:700;color:var(--muted)" aria-hidden="true">%</span>
                    </div>
                    @break

                @case('tautan')
                    <input id="{{ $idKolom }}" type="url" inputmode="url" class="inp filled" wire:model.blur="{{ $jalur }}" placeholder="{{ $kolom['placeholder'] ?? 'https://…' }}" maxlength="2000">
                    @break

                @case('hitung')
                    @php $hasilHitung = \App\Models\BagianKustom::nilaiHitung($kolom, $nilai, $semuaKolom); @endphp
                    <div id="{{ $idKolom }}" class="inp filled" style="color:var(--muted);height:auto;min-height:38px" aria-live="polite" data-hitung="{{ $kolom['kunci'] }}">
                        {{ $hasilHitung !== null ? \App\Support\JenisKolomBagianKustom::tampil($hasilHitung, $kolom) : '—' }}
                        <span style="font-size:11px">· dihitung otomatis{{ $ringkas ? '' : ' saat disimpan dari '.$kolom['rumus'] }}</span>
                    </div>
                    @break

                @case('tabel')
                    @php
                        $subkolom = $kolom['tabel_kolom'] ?? [];
                        $barisMaks = min(\App\Support\JenisKolomBagianKustom::BARIS_TABEL_MAKS, (int) ($kolom['baris_maks'] ?? 0) ?: \App\Support\JenisKolomBagianKustom::BARIS_TABEL_MAKS);
                    @endphp
                    {{-- Nilai formulir = JSON daftar baris; disinkronkan ke server bersama aksi berikutnya (Simpan). --}}
                    <div id="{{ $idKolom }}" role="group" aria-label="{{ $kolom['label'] }}" data-tabel="{{ $kolom['kunci'] }}"
                        x-data="{
                            nilai: $wire.entangle('{{ $jalur }}'),
                            kolom: @js($subkolom),
                            maks: {{ $barisMaks }},
                            baris: [],
                            init() {
                                this.baris = this.urai(this.nilai);
                                this.$watch('nilai', v => { if (v !== this.teks()) this.baris = this.urai(v); });
                            },
                            urai(v) {
                                if (! v) return [];
                                try { const a = JSON.parse(v); return Array.isArray(a) ? a.filter(b => b && typeof b === 'object' && ! Array.isArray(b)) : []; } catch (e) { return []; }
                            },
                            teks() { return this.baris.length ? JSON.stringify(this.baris) : ''; },
                            simpan() { this.nilai = this.teks(); },
                            tambah() {
                                if (this.baris.length >= this.maks) return;
                                const b = {}; this.kolom.forEach(k => b[k.kunci] = ''); this.baris.push(b); this.simpan();
                            },
                            hapus(i) { this.baris.splice(i, 1); this.simpan(); },
                        }">
                        <div class="tabel-gulir" style="overflow-x:auto;max-width:100%;min-width:0">
                            <table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:{{ max(260, count($subkolom) * 130) }}px">
                                <thead>
                                    <tr style="text-align:left;color:var(--muted)">
                                        <th scope="col" style="padding:4px;border-bottom:1px solid var(--line);width:28px">No</th>
                                        @foreach ($subkolom as $s)
                                            <th scope="col" style="padding:4px;border-bottom:1px solid var(--line)">{{ $s['label'] }}@if ($s['wajib']) <span class="req">*</span>@endif</th>
                                        @endforeach
                                        <th scope="col" style="padding:4px;border-bottom:1px solid var(--line);width:36px"><span class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)">Aksi</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(b, i) in baris" :key="i">
                                        <tr style="vertical-align:top">
                                            <td style="padding:4px;border-bottom:1px solid var(--line2)" x-text="i + 1"></td>
                                            @foreach ($subkolom as $s)
                                                <td style="padding:4px;border-bottom:1px solid var(--line2)">
                                                    @if ($s['tipe'] === 'pilihan')
                                                        <select class="inp filled" x-model="b[@js($s['kunci'])]" @change="simpan()" :aria-label="@js($s['label']).concat(' baris ', i + 1)">
                                                            <option value="">— pilih —</option>
                                                            @foreach ($s['opsi'] as $o)
                                                                <option value="{{ $o }}">{{ $o }}</option>
                                                            @endforeach
                                                        </select>
                                                    @elseif ($s['tipe'] === 'tanggal')
                                                        <input type="date" class="inp filled" x-model="b[@js($s['kunci'])]" @change="simpan()" :aria-label="@js($s['label']).concat(' baris ', i + 1)">
                                                    @else
                                                        <input type="text" class="inp filled" @if ($s['tipe'] === 'angka') inputmode="decimal" @endif x-model="b[@js($s['kunci'])]" @input="simpan()" :aria-label="@js($s['label']).concat(' baris ', i + 1)">
                                                    @endif
                                                </td>
                                            @endforeach
                                            <td style="padding:4px;border-bottom:1px solid var(--line2)">
                                                <button type="button" class="btn btn-red btn-sm" @click="hapus(i)" :aria-label="'Hapus baris ' + (i + 1)">🗑</button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <p x-show="baris.length === 0" style="color:var(--muted);font-size:12px;margin:6px 0">Belum ada baris.</p>
                        <button type="button" class="btn btn-ghost btn-sm" style="margin-top:6px" @click="tambah()" :disabled="baris.length >= maks">＋ Tambah baris</button>
                        @if (($kolom['baris_min'] ?? null) || ($kolom['baris_maks'] ?? null))
                            <span class="fhint" style="margin-left:6px">{{ ($kolom['baris_min'] ?? null) ? 'minimal '.$kolom['baris_min'].' baris' : '' }}{{ ($kolom['baris_min'] ?? null) && ($kolom['baris_maks'] ?? null) ? ', ' : '' }}{{ ($kolom['baris_maks'] ?? null) ? 'maksimal '.$kolom['baris_maks'].' baris' : '' }}</span>
                        @endif
                    </div>
                    @break

                @case('tanggal')
                    <input id="{{ $idKolom }}" type="date" class="inp filled" wire:model.blur="{{ $jalur }}">
                    @break

                @case('teks_panjang')
                    <textarea id="{{ $idKolom }}" class="inp filled" style="height:auto;display:block" rows="4" wire:model.blur="{{ $jalur }}" placeholder="{{ $kolom['placeholder'] ?? '' }}" @if ($kolom['panjang_maks'] ?? null) maxlength="{{ $kolom['panjang_maks'] }}" @endif></textarea>
                    @break

                @default
                    <input id="{{ $idKolom }}" type="text" class="inp filled" wire:model.blur="{{ $jalur }}" placeholder="{{ $kolom['placeholder'] ?? '' }}" @if ($kolom['panjang_maks'] ?? null) maxlength="{{ $kolom['panjang_maks'] }}" @endif>
            @endswitch

            @error($jalur)
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>
    @endforeach
    </div>
@endforeach
