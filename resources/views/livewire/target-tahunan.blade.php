<div>
    <div class="info teal">🎯 Target Tahunan tiap IKU diisi <strong>sekali per tahun di sini</strong> — dipakai otomatis di halaman Verifikasi setiap bulan untuk IKU tsb (tidak perlu diketik ulang tiap sesi verifikasi bulanan). Untuk Jenis Nilai %, Alokasi X diisi PER TRIWULAN (I-IV, karena nilainya bertambah bertahap), sedangkan Alokasi Y diisi lewat daftar Rincian Item (N) — jumlah item = Alokasi Y, dipilih lagi per item saat Verifikasi Capaian tiap triwulan untuk menentukan Realisasi X (Realisasi Y otomatis mengikuti Alokasi Y). Jenis Nilai Non % tetap mengisi Alokasi &amp; Realisasi dari halaman Verifikasi masing-masing bulan seperti biasa.</div>
    <div class="fhint" style="margin-bottom:14px">ℹ️ Batas/plafon yang dipakai membandingkan Target Tahunan ini terhadap Realisasi (saat ini 120%) diatur terpisah di tab <a wire:navigate href="{{ route('master-iku.index') }}#rumus">🧮 Rumus Capaian</a>.</div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="field" style="max-width:160px;margin-bottom:14px">
        <label>Tahun</label>
        <select class="inp filled" wire:model.live="tahun">
            @foreach (range(now()->year - 1, now()->year + 1) as $tahunOpsi)
                <option value="{{ $tahunOpsi }}">{{ $tahunOpsi }}</option>
            @endforeach
        </select>
    </div>

    <div class="card">
        <div class="sec"><span>Target Tahunan — {{ $tahun }}</span></div>

        <div class="table-scroll" style="max-height:65vh">
            <table>
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Indikator</th>
                        <th>Jenis Nilai</th>
                        <th>Target Tahunan</th>
                        <th colspan="4" style="text-align:center">Alokasi X per Triwulan <span class="muted" style="font-weight:400">— khusus Jenis Nilai %</span></th>
                        <th style="text-align:center">Alokasi Y <span class="muted" style="font-weight:400">(total, sama semua TW)</span></th>
                    </tr>
                    <tr>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        @foreach (['I', 'II', 'III', 'IV'] as $tw)
                            <th style="text-align:center">TW {{ $tw }}</th>
                        @endforeach
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($daftarIku as $iku)
                        @php $pakaiRasio = $iku->pakaiRasio(); @endphp
                        <tr wire:key="target-tahunan-{{ $iku->id }}">
                            <td class="muted">{{ $iku->kode }}</td>
                            <td>{{ $iku->indikator }}</td>
                            <td>
                                <span class="badge" title="Diatur dari Metode Perhitungan di Master IKU">{{ $pakaiRasio ? '% (X ÷ Y)' : 'Non % (langsung)' }}</span>
                            </td>
                            <td>
                                @if ($pakaiRasio)
                                    <div style="display:flex;gap:8px;align-items:flex-end">
                                        <div>
                                            <div class="muted" style="font-size:10px;margin-bottom:2px" title="{{ $iku->deskripsi_x ?: 'Pembilang (X)' }}">X — {{ \Illuminate\Support\Str::limit($iku->deskripsi_x ?: 'Pembilang', 18) }}</div>
                                            <input type="number" step="0.01" class="inp filled" style="width:100px" wire:model="nilai.{{ $iku->id }}.x_target">
                                        </div>
                                        <span class="muted" style="padding-bottom:9px">÷</span>
                                        <div>
                                            <div class="muted" style="font-size:10px;margin-bottom:2px" title="{{ $iku->deskripsi_y ?: 'Penyebut (Y)' }}">Y — {{ \Illuminate\Support\Str::limit($iku->deskripsi_y ?: 'Penyebut', 18) }}</div>
                                            <input type="number" step="0.01" class="inp filled" style="width:100px" wire:model="nilai.{{ $iku->id }}.y_target">
                                        </div>
                                        <span class="muted" style="padding-bottom:9px">
                                            = {{ filled($nilai[$iku->id]['y_target'] ?? null) && $nilai[$iku->id]['y_target'] > 0 ? round(($nilai[$iku->id]['x_target'] ?? 0) / $nilai[$iku->id]['y_target'] * 100, 2) : 0 }}%
                                        </span>
                                    </div>
                                    @error("nilai.{$iku->id}.x_target")
                                        <div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>
                                    @enderror
                                    @error("nilai.{$iku->id}.y_target")
                                        <div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>
                                    @enderror
                                @else
                                    <div style="display:flex;gap:8px;align-items:center">
                                        <input type="number" step="0.01" class="inp filled" style="width:140px" wire:model="nilai.{{ $iku->id }}.target_tahunan">
                                        <span class="muted">{{ $iku->satuan }}</span>
                                    </div>
                                    @error("nilai.{$iku->id}.target_tahunan")
                                        <div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>
                                    @enderror
                                @endif
                            </td>
                            @if ($pakaiRasio)
                                @for ($tw = 1; $tw <= 4; $tw++)
                                    <td style="text-align:center">
                                        <input type="number" step="0.01" class="inp filled" style="width:72px;text-align:center" wire:model="nilai.{{ $iku->id }}.x_alokasi_tw{{ $tw }}">
                                        @error("nilai.{$iku->id}.x_alokasi_tw{$tw}")
                                            <div style="color:var(--red);font-size:10.5px">{{ $message }}</div>
                                        @enderror
                                    </td>
                                @endfor
                                <td style="text-align:left;min-width:220px">
                                    <div class="muted" style="font-size:10.5px;margin-bottom:4px">Rincian N — {{ count($rincianN[$iku->id] ?? []) }} item = Alokasi Y</div>
                                    <div style="max-height:140px;overflow-y:auto;display:flex;flex-direction:column;gap:4px">
                                        @foreach (($rincianN[$iku->id] ?? []) as $kunci => $baris)
                                            <div wire:key="rincian-n-{{ $iku->id }}-{{ $kunci }}" style="display:flex;gap:4px;align-items:center">
                                                <input type="text" class="inp filled" style="flex:1;font-size:11.5px;padding:4px 6px" placeholder="Uraian item" wire:model="rincianN.{{ $iku->id }}.{{ $kunci }}.uraian">
                                                <button type="button" class="btn btn-red btn-sm" style="padding:2px 6px" wire:click="hapusN({{ $iku->id }}, '{{ $kunci }}')" title="Hapus item">✕</button>
                                            </div>
                                        @endforeach
                                    </div>
                                    <button type="button" class="btn btn-ghost btn-sm" style="margin-top:4px" wire:click="tambahN({{ $iku->id }})">+ Tambah item</button>
                                    @error("rincianN.{$iku->id}.*.uraian")
                                        <div style="color:var(--red);font-size:10.5px">{{ $message }}</div>
                                    @enderror
                                </td>
                            @else
                                <td colspan="5" class="muted" style="text-align:center;font-size:11.5px">diisi dari Verifikasi Capaian</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="btn-row" style="margin-top:14px">
            <button type="button" class="btn btn-teal" wire:click="simpan" wire:loading.attr="disabled" wire:target="simpan">
                <span wire:loading.remove wire:target="simpan">💾 Simpan Target Tahunan</span>
                <span wire:loading wire:target="simpan"><i class="spin"></i> Menyimpan…</span>
            </button>
        </div>
    </div>
</div>
