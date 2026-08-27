<div>
    <div class="info teal">🎯 Target Tahunan tiap IKU diisi <strong>sekali per tahun di sini</strong> — dipakai otomatis di halaman Verifikasi setiap bulan untuk IKU tsb (tidak perlu diketik ulang tiap sesi verifikasi bulanan). Untuk IKU bersatuan Persen, Alokasi Target Pembilang(X)/Penyebut(Y) TW I-IV JUGA diisi sekali di sini — Tim SAKIP di Verifikasi Capaian tiap triwulan cukup mengisi Realisasi X saja (Realisasi Y otomatis mengikuti Alokasi Y). IKU Non % (langsung) tetap mengisi Alokasi &amp; Realisasi dari halaman Verifikasi masing-masing bulan seperti biasa.</div>
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

        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Indikator</th>
                        <th>Target Tahunan</th>
                        <th colspan="4" style="text-align:center">Alokasi Target (X ÷ Y) per Triwulan <span class="muted" style="font-weight:400">— khusus IKU Persen</span></th>
                    </tr>
                    <tr>
                        <th></th>
                        <th></th>
                        <th></th>
                        @foreach (['I', 'II', 'III', 'IV'] as $tw)
                            <th style="text-align:center">TW {{ $tw }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($daftarIku as $iku)
                        <tr wire:key="target-tahunan-{{ $iku->id }}">
                            <td class="muted">{{ $iku->kode }}</td>
                            <td>{{ $iku->indikator }}</td>
                            <td>
                                @if ($iku->pakaiRasio())
                                    <div style="display:flex;gap:8px;align-items:center">
                                        <input type="number" step="0.01" class="inp filled" style="width:100px" wire:model="nilai.{{ $iku->id }}.x_target" placeholder="{{ $iku->deskripsi_x ?: 'X' }}">
                                        <span class="muted">÷</span>
                                        <input type="number" step="0.01" class="inp filled" style="width:100px" wire:model="nilai.{{ $iku->id }}.y_target" placeholder="{{ $iku->deskripsi_y ?: 'Y' }}">
                                        <span class="muted">
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
                            @if ($iku->pakaiRasio())
                                @for ($tw = 1; $tw <= 4; $tw++)
                                    <td style="text-align:center">
                                        <div style="display:flex;flex-direction:column;gap:3px;align-items:center">
                                            <input type="number" step="0.01" class="inp filled" style="width:72px;text-align:center" wire:model="nilai.{{ $iku->id }}.x_alokasi_tw{{ $tw }}" placeholder="X">
                                            <input type="number" step="0.01" class="inp filled" style="width:72px;text-align:center" wire:model="nilai.{{ $iku->id }}.y_alokasi_tw{{ $tw }}" placeholder="Y">
                                        </div>
                                        @error("nilai.{$iku->id}.x_alokasi_tw{$tw}")
                                            <div style="color:var(--red);font-size:10.5px">{{ $message }}</div>
                                        @enderror
                                        @error("nilai.{$iku->id}.y_alokasi_tw{$tw}")
                                            <div style="color:var(--red);font-size:10.5px">{{ $message }}</div>
                                        @enderror
                                    </td>
                                @endfor
                            @else
                                <td colspan="4" class="muted" style="text-align:center;font-size:11.5px">diisi dari Verifikasi Capaian</td>
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
