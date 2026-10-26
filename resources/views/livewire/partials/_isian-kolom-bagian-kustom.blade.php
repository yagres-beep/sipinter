{{--
    Kolom isian satu poin Bagian Kustom, dikelompokkan per subbagian — dibentuk dari
    konfigurasi di database (BagianKustom::kelompokKolomBerlaku()). Dipakai Isian
    Kegiatan dan Isian Bagian Kustom.

    Parameter: $kelompok (hasil kelompokKolomBerlaku), $model (awalan wire:model, mis.
    "baris.5.0.kolom"). Galat dibaca dari kunci "{$model}.{kunci}".
--}}
@foreach ($kelompok as $grup)
    @if ($grup['subbagian'])
        <div class="sub-bagian-kustom" style="margin:14px 0 8px;padding-top:10px;border-top:1px dashed var(--line)">
            <div style="font-weight:700;font-size:12.5px">{{ $grup['subbagian']['nama'] }}</div>
            @if ($grup['subbagian']['deskripsi'])
                <div class="fhint" style="margin:2px 0 0">{{ $grup['subbagian']['deskripsi'] }}</div>
            @endif
        </div>
    @endif

    @foreach ($grup['kolom'] as $kolom)
        @php
            $jalur = $model.'.'.$kolom['kunci'];
            $idKolom = 'kol-'.str_replace('.', '-', $jalur);
        @endphp
        <div class="field">
            <label for="{{ $idKolom }}">{{ $kolom['label'] }}
                @if ($kolom['wajib'])<span class="req">*</span>@else<span style="color:var(--muted);font-weight:500">opsional</span>@endif
                @include('livewire.partials._petunjuk-kolom-angka', ['kolom' => $kolom])
            </label>
            @if ($kolom['petunjuk'] ?? null)
                <div class="fhint" style="margin:0 0 6px">{{ $kolom['petunjuk'] }}</div>
            @endif

            @switch($kolom['tipe'])
                @case('pilihan')
                    <select id="{{ $idKolom }}" class="inp filled" wire:model.blur="{{ $jalur }}">
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
@endforeach
