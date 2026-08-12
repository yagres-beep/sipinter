<div>
    <div class="page-title">Persetujuan Notula Triwulan {{ ['I', 'II', 'III', 'IV'][$triwulan - 1] }} {{ $tahun }}</div>
    <div class="page-sub">Tinjau notula gabungan lalu setujui atau kembalikan.</div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="period-banner">
        <span class="pb-ico">📅</span>
        <div>
            <div class="pb-lbl">Triwulan</div>
            <div class="pb-val">
                <select wire:model.live="triwulan" style="border:none;background:transparent;font-weight:700;color:var(--ink);font-size:14px">
                    @foreach (['I', 'II', 'III', 'IV'] as $idx => $label)
                        <option value="{{ $idx + 1 }}">Triwulan {{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="tahun" style="border:none;background:transparent;font-weight:700;color:var(--ink);font-size:14px">
                    @foreach (range(now()->year - 1, now()->year + 1) as $tahunOpsi)
                        <option value="{{ $tahunOpsi }}">{{ $tahunOpsi }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        @if ($notula)
            <x-badge-status :status="$notula->status" />
        @else
            <span class="badge b-draft">-</span>
        @endif
    </div>

    @if (! $notula || ! $notula->pdf_gabungan)
        <div class="card">
            <p style="color:var(--muted);font-size:13px">Belum ada notula yang dikirim Tim SAKIP untuk triwulan ini.</p>
        </div>
    @else
        <div class="grid grid-2" style="align-items:start">
            <div class="card">
                <div class="sec"><span>Pratinjau Notula Gabungan</span></div>
                <iframe src="{{ route('notula.pratinjau', $notula) }}" title="Pratinjau PDF Notula" style="width:100%;height:520px;border:1px solid var(--line);border-radius:11px;background:#f8fafc"></iframe>

                @if ($notula->status === \App\Models\Notula::STATUS_DISETUJUI)
                    <div class="ttd-box" style="margin-top:12px">
                        ttd<br>
                        <b>{{ $notula->disetujuiOleh?->nama }}</b> · {{ $notula->disetujui_pada?->translatedFormat('d F Y') }}
                    </div>
                @endif

                <div class="btn-row">
                    <a href="{{ route('notula.pratinjau', $notula) }}" target="_blank" class="btn btn-ghost btn-sm">🔍 Buka di Tab Baru</a>
                </div>
            </div>

            <div class="card">
                <div class="sec"><span>Tindakan</span></div>

                @if ($notula->status === \App\Models\Notula::STATUS_DISETUJUI)
                    <div class="badge b-approve" style="display:block;margin-bottom:14px">Notula sudah disetujui</div>
                    <a href="{{ route('notula.unduh-final', $notula) }}" class="btn btn-teal" style="width:100%;justify-content:center">⬇ Unduh Final</a>
                @else
                    <div class="info teal">✅ Notula 3 bagian tergabung. Setelah <b>Setujui</b> ditekan: blok TTD terisi otomatis + nama Kepala + tanggal persetujuan elektronik, versi final ber-TTD tersimpan ke Drive, dan tombol Unduh final aktif.</div>

                    @error('aksi')
                        <div style="color:var(--red);font-size:11.5px;margin-bottom:10px">{{ $message }}</div>
                    @enderror

                    @if (! $tampilkanFormKembalikan)
                        <button type="button" class="btn btn-teal" style="width:100%;justify-content:center;margin-bottom:10px" wire:click="setujui">✓ Setujui &amp; Bubuhkan TTD</button>
                        <button type="button" class="btn btn-red" style="width:100%;justify-content:center" wire:click="bukaFormKembalikan">↩ Minta Revisi</button>
                    @else
                        <div class="field">
                            <label>Catatan Pengembalian <span class="req">*</span></label>
                            <textarea class="inp filled" style="height:auto;display:block" rows="3" wire:model="catatanPengembalian"
                                placeholder="Jelaskan bagian yang perlu diperbaiki..."></textarea>
                            @error('catatanPengembalian')
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="btn-row">
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="batalKembalikan">Batal</button>
                            <button type="button" class="btn btn-red btn-sm" wire:click="kembalikan">Kirim Pengembalian</button>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    @endif

    @if ($riwayatDisetujui->isNotEmpty())
        <div class="card" style="margin-top:16px">
            <div class="sec"><span>Riwayat Versi Ber-TTD</span></div>
            @foreach ($riwayatDisetujui as $n)
                <div class="filechip ok" wire:key="riwayat-{{ $n->id }}">
                    <span class="nm">📄 Notula TW {{ ['I', 'II', 'III', 'IV'][$n->periode->triwulan - 1] }} {{ $n->periode->tahun }}
                        <span class="sub">Disetujui {{ $n->disetujui_pada?->translatedFormat('d F Y') }}</span>
                    </span>
                    <a href="{{ route('notula.unduh-final', $n) }}" class="btn btn-ghost btn-sm">⬇ Unduh</a>
                </div>
            @endforeach
        </div>
    @endif
</div>
