<div>
    <div class="page-title">Kendala &amp; Solusi</div>
    <div class="page-sub">Catat kendala dan solusi per IKU. Ditampilkan kumulatif pada notula sampai triwulan berjalan.</div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="period-banner">
        <span class="pb-ico">📅</span>
        <div>
            <div class="pb-lbl">Periode Pengisian</div>
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

    @if ($iku_id && $riwayat->isNotEmpty())
        <div class="card">
            <div class="sec"><span>Riwayat Kumulatif Triwulan Berjalan</span> <span class="badge b-draft">hanya-baca</span></div>

            @foreach ($riwayat as $triwulanKe => $entriTriwulan)
                <div style="margin-bottom:14px">
                    <div style="font-size:12px;font-weight:700;color:var(--blue-600);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">
                        Triwulan {{ ['I', 'II', 'III', 'IV'][$triwulanKe - 1] }}
                    </div>
                    @foreach ($entriTriwulan as $entri)
                        <div style="padding:10px 0;border-bottom:1px solid var(--line2);font-size:13px">
                            <div><b>Kendala:</b> {{ $entri->kendala }}</div>
                            @if ($entri->solusi)
                                <div style="margin-top:4px"><b>Solusi:</b> {{ $entri->solusi }}</div>
                                @foreach ($entri->berkas as $file)
                                    <div class="filechip" style="max-width:320px">
                                        <span class="nm">📄 {{ $file->nama_file }}</span>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    <div class="card">
        <div class="sec"><span class="n">2</span><span>Kendala &amp; Solusi</span></div>
        <div class="info warn">⚠️ Jika mengisi solusi, wajib melampirkan bukti dukung solusinya.</div>

        @foreach ($blocks as $i => $block)
            <div class="poin-row" wire:key="block-{{ $i }}">
                <span class="k-num">Pasangan {{ $i + 1 }}</span>
                @if (count($blocks) > 1)
                    <button type="button" class="btn btn-red btn-sm" style="position:absolute;top:8px;right:8px" wire:click="removeBlock({{ $i }})">🗑</button>
                @endif

                <div class="field" style="margin-bottom:0">
                    <label>Kendala <span class="req">*</span></label>
                    <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model="blocks.{{ $i }}.kendala"
                        placeholder="mis. Keterlambatan pengumpulan dokumen dari mitra"></textarea>
                    @error("blocks.{$i}.kendala")
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <div class="field" style="margin-bottom:0">
                        <label>Solusi</label>
                        <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model.live="blocks.{{ $i }}.solusi"
                            placeholder="mis. Percepatan koordinasi dengan mitra terkait"></textarea>
                        @error("blocks.{$i}.solusi")
                            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                        @enderror
                    </div>

                    @if (filled($block['solusi']))
                        <div style="margin-top:8px">
                            <label style="font-size:11.5px;font-weight:600;margin-bottom:5px;display:block">Bukti Dukung Solusi (PDF) <span class="req">wajib</span></label>

                            @if (empty($block['bukti_solusi']))
                                <label class="upload need" style="cursor:pointer;display:block;padding:10px">
                                    <div style="font-weight:600;font-size:11.5px">📤 Klik untuk unggah bukti solusi (PDF)</div>
                                    <input type="file" wire:model="blocks.{{ $i }}.bukti_solusi" multiple accept="application/pdf" style="display:none">
                                </label>
                            @else
                                @foreach ($block['bukti_solusi'] as $fi => $file)
                                    <div class="filechip ok">
                                        <span class="nm">📄 {{ $file->getClientOriginalName() }}</span>
                                        <span class="x" style="cursor:pointer" wire:click="removeBukti({{ $i }}, {{ $fi }})">✕</span>
                                    </div>
                                @endforeach
                                <label class="btn btn-ghost btn-sm" style="margin-top:8px;cursor:pointer">
                                    ⟲ Ganti Berkas
                                    <input type="file" wire:model="blocks.{{ $i }}.bukti_solusi" multiple accept="application/pdf" style="display:none">
                                </label>
                            @endif

                            <div wire:loading wire:target="blocks.{{ $i }}.bukti_solusi" style="font-size:11.5px;color:var(--muted);margin-top:6px">Mengunggah berkas…</div>

                            @error("blocks.{$i}.bukti_solusi")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                            @error("blocks.{$i}.bukti_solusi.*")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        </div>
                    @endif
                </div>
            </div>
        @endforeach

        <div class="btn-row">
            <button type="button" class="btn btn-ghost btn-sm" wire:click="addBlock">＋ Tambah Pasangan</button>
            <button type="button" class="btn btn-primary" wire:click="simpan" wire:loading.attr="disabled" wire:target="simpan">
                <span wire:loading.remove wire:target="simpan">Simpan Kendala &amp; Solusi</span>
                <span wire:loading wire:target="simpan">Menyimpan…</span>
            </button>
        </div>
    </div>
</div>
