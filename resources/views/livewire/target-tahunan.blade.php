<div>
    <div class="info teal">🎯 Target Tahunan tiap IKU diisi <strong>sekali per tahun di sini</strong> — dipakai otomatis di halaman Verifikasi setiap bulan untuk IKU tsb (tidak perlu diketik ulang tiap sesi verifikasi bulanan). Alokasi Target diisi PER TRIWULAN sebagai angka <strong>KUMULATIF TW I s.d. TW tsb</strong> (persis seperti Kertas Kerja Excel — mis. 1, 1, 2, 3, BUKAN kontribusi tiap TW), untuk KEDUA Jenis Nilai. Khusus Jenis Nilai %: Alokasi X diisi begitu (kumulatif), sedangkan <strong>angka N</strong> (Alokasi Y) diketik sendiri dan berlaku sama untuk keempat TW — tidak dihitung dari jumlah rincian. Daftar Rincian N menjadi daftar rincian n yang dicentang saat Verifikasi Capaian bila sudah terealisasi; jumlahnya boleh berbeda dari angka N. Nilai dasar n (Realisasi Y) otomatis mengikuti angka N, sedangkan realisasi n tidak ikut berubah saat N diubah. Target Tahunan (KEDUA Jenis Nilai) otomatis = Alokasi TW IV, tidak diketik terpisah. Realisasi (kedua Jenis Nilai) tetap diisi dari halaman Verifikasi masing-masing bulan — untuk Non % juga diketik langsung sebagai angka kumulatif, sama polanya dengan Alokasi di sini.</div>
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
                        <th colspan="4" style="text-align:center">Alokasi Target Kumulatif per Triwulan <span class="muted" style="font-weight:400">— isi angka kumulatif TW I s.d. TW tsb (X untuk Jenis Nilai %, nilai langsung untuk Non %)</span></th>
                        <th style="text-align:center">Angka N (Alokasi Y) &amp; Rincian N <span class="muted" style="font-weight:400">(khusus Jenis Nilai %, N sama semua TW)</span></th>
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
                                    @php
                                        $xTw4 = $nilai[$iku->id]['x_alokasi_tw4'] ?? null;
                                        $yTw4 = $angkaN[$iku->id] ?? null;
                                        $yTw4 = is_numeric($yTw4) ? (float) $yTw4 : null;
                                    @endphp
                                    <div class="muted" style="font-size:11.5px" title="Otomatis = Alokasi X ÷ Alokasi Y TW IV, tidak diketik terpisah">
                                        {{ $xTw4 ?? '—' }} ÷ {{ $yTw4 ?? '—' }}
                                        = <strong>{{ filled($yTw4) && $yTw4 > 0 ? round(($xTw4 ?? 0) / $yTw4 * 100, 2) : '—' }}%</strong>
                                    </div>
                                @else
                                    @php $twEmpat = $nilai[$iku->id]['alokasi_tw4'] ?? null; @endphp
                                    <div class="muted" style="font-size:11.5px" title="Otomatis = Alokasi Target TW IV, tidak diketik terpisah">
                                        <strong>{{ $twEmpat ?? '—' }}</strong> {{ $iku->satuan }}
                                    </div>
                                @endif
                            </td>
                            @if ($pakaiRasio)
                                @for ($tw = 1; $tw <= 4; $tw++)
                                    <td style="text-align:center">
                                        <input type="number" step="0.01" class="inp filled" style="width:72px;text-align:center" title="Kumulatif TW I s.d. TW {{ $tw }}" wire:model="nilai.{{ $iku->id }}.x_alokasi_tw{{ $tw }}">
                                        @error("nilai.{$iku->id}.x_alokasi_tw{$tw}")
                                            <div style="color:var(--red);font-size:10.5px">{{ $message }}</div>
                                        @enderror
                                    </td>
                                @endfor
                                <td style="text-align:left;min-width:220px">
                                    @php
                                        $jumlahRincian = collect($rincianN[$iku->id] ?? [])->filter(fn ($b) => trim((string) ($b['uraian'] ?? '')) !== '')->count();
                                        $nIsian = $angkaN[$iku->id] ?? null;
                                        $nIsian = is_numeric($nIsian) ? (float) $nIsian : null;
                                    @endphp
                                    <div style="display:flex;gap:6px;align-items:center;margin-bottom:6px;flex-wrap:wrap">
                                        <label for="angka-n-{{ $iku->id }}" style="font-size:11.5px;font-weight:600">N =</label>
                                        <input id="angka-n-{{ $iku->id }}" type="number" step="0.01" min="0" class="inp filled" style="width:90px;text-align:center" title="Angka total N (penyebut), berlaku untuk TW I-IV" wire:model.live.debounce.400ms="angkaN.{{ $iku->id }}">
                                        @if ($nIsian === null)
                                            <span class="badge" style="font-size:9.5px" title="Persentase belum bisa dihitung sebelum N diisi">N belum diisi</span>
                                        @endif
                                    </div>
                                    @error("angkaN.{$iku->id}")
                                        <div style="color:var(--red);font-size:10.5px;margin-bottom:4px">{{ $message }}</div>
                                    @enderror
                                    <div class="muted" style="font-size:10.5px;margin-bottom:4px">
                                        Rincian N — {{ $jumlahRincian }} item
                                        @if ($nIsian !== null && abs($nIsian - $jumlahRincian) > 0.0001)
                                            <span title="Boleh berbeda. Angka N yang dipakai sebagai penyebut, bukan jumlah rincian.">(berbeda dari N)</span>
                                        @endif
                                        @if ($jumlahRincian > 0 && ($nIsian === null || abs($nIsian - $jumlahRincian) > 0.0001))
                                            <button type="button" class="btn btn-ghost btn-sm" style="padding:0 6px;font-size:10.5px" wire:click="samakanNDenganRincian({{ $iku->id }})" title="Isi N dengan jumlah rincian ({{ $jumlahRincian }}). Baru tersimpan setelah Simpan.">Pakai {{ $jumlahRincian }} sebagai N</button>
                                        @endif
                                    </div>
                                    <div style="max-height:140px;overflow-y:auto;display:flex;flex-direction:column;gap:4px">
                                        @foreach (($rincianN[$iku->id] ?? []) as $kunci => $baris)
                                            <div wire:key="rincian-n-{{ $iku->id }}-{{ $kunci }}" style="display:flex;gap:4px;align-items:center">
                                                <input type="text" class="inp filled" style="flex:1;font-size:11.5px;padding:4px 6px" placeholder="Uraian item" wire:model="rincianN.{{ $iku->id }}.{{ $kunci }}.uraian">
                                                @if (! empty($baris['terealisasi']))
                                                    <span class="badge b-approve" style="font-size:9.5px" title="Sudah terealisasi. Bila dihapus dari N, realisasinya tetap tercatat di n (tidak ikut terhapus).">✓ terealisasi</span>
                                                @endif
                                                <button type="button" class="btn btn-red btn-sm" style="padding:2px 6px" wire:click="hapusN({{ $iku->id }}, '{{ $kunci }}')" title="{{ ! empty($baris['terealisasi']) ? 'Keluarkan dari N (realisasi tetap tercatat) -- diproses saat Simpan' : 'Hapus item -- diproses saat Simpan' }}">✕</button>
                                            </div>
                                        @endforeach
                                    </div>
                                    @if (($jumlahTambahan[$iku->id] ?? 0) > 0)
                                        <div class="muted" style="font-size:10.5px;margin-top:4px" title="Ditambahkan lewat halaman Verifikasi. Tidak mengubah angka N dan tidak terpengaruh perubahan di sini.">+ {{ $jumlahTambahan[$iku->id] }} rincian tambahan n dari Verifikasi (tidak mengubah N)</div>
                                    @endif
                                    <button type="button" class="btn btn-ghost btn-sm" style="margin-top:4px" wire:click="tambahN({{ $iku->id }})">+ Tambah item</button>
                                    @error("rincianN.{$iku->id}.*.uraian")
                                        <div style="color:var(--red);font-size:10.5px">{{ $message }}</div>
                                    @enderror
                                </td>
                            @else
                                @for ($tw = 1; $tw <= 4; $tw++)
                                    <td style="text-align:center">
                                        <input type="number" step="0.01" class="inp filled" style="width:72px;text-align:center" title="Kumulatif TW I s.d. TW {{ $tw }}" wire:model="nilai.{{ $iku->id }}.alokasi_tw{{ $tw }}">
                                        @error("nilai.{$iku->id}.alokasi_tw{$tw}")
                                            <div style="color:var(--red);font-size:10.5px">{{ $message }}</div>
                                        @enderror
                                    </td>
                                @endfor
                                <td class="muted" style="text-align:center;font-size:11.5px">— (tidak berlaku, Non %)</td>
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
