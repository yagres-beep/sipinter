<div>
    <div class="page-title">Rencana Tindak Lanjut &amp; Evaluasi</div>
    <div class="page-sub">Evaluasi RTL triwulan sebelumnya, dan tetapkan RTL untuk triwulan berikutnya.</div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="period-banner">
        <span class="pb-ico">📅</span>
        <div>
            <div class="pb-lbl">Periode Berjalan</div>
            <div class="pb-val">
                <select wire:model.live="bulan" style="border:none;background:transparent;font-weight:700;color:var(--navy);font-size:14px">
                    @foreach (['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'] as $idx => $namaBulan)
                        <option value="{{ $idx + 1 }}">{{ $namaBulan }}</option>
                    @endforeach
                </select>
                <select wire:model.live="tahun" style="border:none;background:transparent;font-weight:700;color:var(--navy);font-size:14px">
                    @foreach (range(now()->year - 1, now()->year + 1) as $tahunOpsi)
                        <option value="{{ $tahunOpsi }}">{{ $tahunOpsi }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <span class="pb-tag">TW {{ ['I', 'II', 'III', 'IV'][$triwulan - 1] }}</span>
    </div>

    <div class="card">
        <div class="sec"><span class="n">1</span><span>IKU</span></div>
        <div class="field">
            <label>Indikator Kinerja (IKU) <span class="req">*</span></label>
            <select class="inp filled" wire:model.live="iku_id">
                <option value="">— Pilih IKU —</option>
                @foreach ($ikuList as $iku)
                    <option value="{{ $iku->id }}">{{ $iku->kode }} — {{ $iku->indikator }}</option>
                @endforeach
            </select>
            @error('iku_id')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>
    </div>

    @if ($iku_id)
        @if ($rtlBerjalan->isNotEmpty())
            <div class="card">
                <div class="sec"><span>RTL Triwulan Berjalan</span> <span class="badge b-draft">hanya-baca</span></div>
                @foreach ($rtlBerjalan as $poin)
                    <div style="padding:10px 0;border-bottom:1px solid var(--line2);font-size:13px">
                        <div>{{ $poin->rtl_teks }}</div>
                        <div style="color:var(--muted);font-size:12px;margin-top:4px">
                            {{ $poin->berlaku_bulan }} · PIC: {{ $poin->pic }} · Batas waktu: {{ $poin->batas_waktu?->translatedFormat('d F Y') }}
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="card">
            <div class="sec"><span class="n">2</span><span>Evaluasi RTL Triwulan Sebelumnya</span></div>
            <div class="info teal">✅ Poin di bawah otomatis diambil dari RTL triwulan sebelumnya. Laporkan realisasinya dan lampirkan bukti bila ada.</div>

            @if ($rtlSebelumnya->isEmpty())
                <p style="color:var(--muted);font-size:13px">Tidak ada poin RTL triwulan sebelumnya untuk IKU ini.</p>
            @endif

            @foreach ($rtlSebelumnya as $poin)
                <div class="poin-single" wire:key="rtl-{{ $poin->id }}">
                    <span class="k-num stat-in">Poin {{ $loop->iteration }}</span>
                    <div class="rtl-planned">
                        <div>
                            <span class="pl-lbl">Direncanakan ({{ $poin->berlaku_bulan }})</span>
                            {{ $poin->rtl_teks }}
                            <div style="color:var(--muted);font-size:11px;margin-top:4px">PIC: {{ $poin->pic }} · Batas waktu: {{ $poin->batas_waktu?->translatedFormat('d F Y') }}</div>
                        </div>
                    </div>

                    @if ($poin->sudahDievaluasi())
                        <div style="font-size:13px;margin-top:10px">
                            <b>Realisasi:</b> {{ $poin->realisasi }}
                            <x-badge-status :status="$poin->status_cocok" />
                        </div>
                        @foreach ($poin->berkas as $file)
                            <div class="filechip" style="max-width:320px;margin-top:6px">
                                <span class="nm">📄 {{ $file->nama_file }}</span>
                            </div>
                        @endforeach
                    @else
                        <div class="field" style="margin:12px 0 10px">
                            <label>Realisasi <span class="req">*</span></label>
                            <textarea class="inp filled" style="height:auto;display:block" rows="2"
                                wire:model.live="evaluasi.{{ $poin->id }}.realisasi"
                                placeholder="Uraikan realisasi tindak lanjut untuk poin RTL ini..."></textarea>
                            @error("evaluasi.{$poin->id}.realisasi")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="field">
                            <label>Status Kecocokan <span class="req">*</span></label>
                            <select class="inp filled" wire:model="evaluasi.{{ $poin->id }}.status_cocok">
                                <option value="">— Pilih Status —</option>
                                <option value="cocok">Cocok</option>
                                <option value="perlu_ditinjau">Perlu Ditinjau</option>
                                <option value="tidak_cocok">Tidak Cocok</option>
                            </select>
                            <div style="color:var(--muted);font-size:11.5px;margin-top:5px">
                                Disarankan otomatis dari kemiripan teks dengan RTL asli — dapat disesuaikan manual.
                            </div>
                            @error("evaluasi.{$poin->id}.status_cocok")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="field">
                            <label>Bukti Realisasi (PDF, opsional)</label>

                            @if (empty($evaluasi[$poin->id]['bukti']))
                                <label class="upload" style="cursor:pointer;display:block;padding:10px">
                                    <div style="font-weight:600;font-size:11.5px;color:var(--blue-600)">📤 Bukti realisasi (PDF) · opsional</div>
                                    <input type="file" wire:model="evaluasi.{{ $poin->id }}.bukti" multiple accept="application/pdf" style="display:none">
                                </label>
                            @else
                                @foreach ($evaluasi[$poin->id]['bukti'] as $fi => $file)
                                    <div class="filechip">
                                        <span class="nm">📄 {{ $file->getClientOriginalName() }}</span>
                                    </div>
                                @endforeach
                            @endif
                            @error("evaluasi.{$poin->id}.bukti.*")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="btn-row">
                            <button type="button" class="btn btn-primary btn-sm" wire:click="simpanEvaluasi({{ $poin->id }})">Simpan Evaluasi</button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="card">
            <div class="sec"><span class="n">3</span><span>Tetapkan RTL untuk {{ $labelBerikutnya }}</span></div>

            @if ($sudahAdaRtlBerikutnya)
                <div class="badge b-approve" style="display:inline-block;margin-bottom:10px">Sudah ditetapkan</div>
                <p style="color:var(--muted);font-size:12.5px">
                    RTL untuk {{ $labelBerikutnya }} sudah ditetapkan dan tampil hanya-baca sampai triwulan tersebut berjalan.
                </p>
            @else
                @error('rtlBaru')
                    <div style="color:var(--red);font-size:11.5px;margin-bottom:10px">{{ $message }}</div>
                @enderror

                @foreach ($rtlBaru as $i => $blok)
                    <div class="poin-single" wire:key="rtlbaru-{{ $i }}">
                        <span class="k-num stat-in">Poin RTL {{ $i + 1 }}</span>
                        @if (count($rtlBaru) > 1)
                            <button type="button" class="btn btn-red btn-sm" style="position:absolute;top:8px;right:8px" wire:click="removeRtlBlock({{ $i }})">🗑</button>
                        @endif

                        <div class="field">
                            <label>RTL (satu blok mencakup keseluruhan triwulan) <span class="req">*</span></label>
                            <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model="rtlBaru.{{ $i }}.rtl_teks"
                                placeholder="mis. Melakukan pelatihan intensif petugas lapangan"></textarea>
                            @error("rtlBaru.{$i}.rtl_teks")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row2">
                            <div class="field">
                                <label>PIC <span class="req">*</span></label>
                                <input type="text" class="inp filled" wire:model="rtlBaru.{{ $i }}.pic" placeholder="Nama penanggung jawab">
                                @error("rtlBaru.{$i}.pic")
                                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="field">
                                <label>Batas Waktu <span class="req">*</span></label>
                                <input type="date" class="inp filled" wire:model="rtlBaru.{{ $i }}.batas_waktu">
                                @error("rtlBaru.{$i}.batas_waktu")
                                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                @endforeach

                <div class="btn-row">
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="addRtlBlock">＋ Tambah Poin RTL</button>
                    <button type="button" class="btn btn-primary" wire:click="tetapkanRtl">Tetapkan RTL →</button>
                </div>
            @endif
        </div>
    @endif
</div>
