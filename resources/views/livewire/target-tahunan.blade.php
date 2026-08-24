<div>
    <div class="info teal">🎯 Target Tahunan tiap IKU diisi <strong>sekali per tahun di sini</strong> — dipakai otomatis di halaman Verifikasi setiap bulan untuk IKU tsb (tidak perlu diketik ulang tiap sesi verifikasi bulanan). Alokasi Target &amp; Realisasi per triwulan tetap diisi dari halaman Verifikasi masing-masing bulan.</div>

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
