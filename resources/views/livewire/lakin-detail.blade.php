<div>
    <div class="page-title">LAKIN {{ $lakin->tahun }}</div>
    <div class="page-sub">Laporan Kinerja tahunan — sasaran, indikator, target, realisasi, dan capaian %.</div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="btn-row" style="margin-bottom:16px">
        <a href="{{ route('lakin.index') }}" class="btn btn-ghost btn-sm">← Daftar LAKIN</a>
        <button type="button" class="btn btn-teal btn-sm" wire:click="unduhExcel">⬇ Unduh Excel</button>
        @if ($this->isTimSakip())
            <button type="button" class="btn btn-ghost btn-sm" wire:click="susunUlangOtomatis" wire:confirm="Susun ulang dari data capaian terbaru? Baris otomatis (bukan custom) akan ditimpa.">↻ Susun Ulang Otomatis</button>
        @endif
    </div>

    <div class="card">
        <div class="sec"><span>Tabel LAKIN</span></div>

        @if ($lakin->baris->isEmpty())
            <p style="color:var(--muted);font-size:13px">Belum ada baris. {{ $this->isTimSakip() ? 'Tambahkan lewat form di bawah, atau klik "Susun Ulang Otomatis" untuk mengisi dari data capaian.' : '' }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Sasaran</th>
                        <th>Indikator</th>
                        <th style="text-align:right">Target</th>
                        <th style="text-align:right">Realisasi</th>
                        <th style="text-align:right">Capaian %</th>
                        @if ($this->isTimSakip())
                            <th></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lakin->baris as $baris)
                        <tr wire:key="baris-{{ $baris->id }}">
                            @if ($this->isTimSakip())
                                <td><input type="text" class="inp filled" style="min-width:140px" wire:model="edit.{{ $baris->id }}.sasaran"></td>
                                <td><textarea class="inp filled" style="height:auto;min-width:260px" rows="1" wire:model="edit.{{ $baris->id }}.indikator"></textarea></td>
                                <td style="text-align:right"><input type="number" step="0.01" class="inp filled" style="width:110px;text-align:right" wire:model="edit.{{ $baris->id }}.target"></td>
                                <td style="text-align:right"><input type="number" step="0.01" class="inp filled" style="width:110px;text-align:right" wire:model="edit.{{ $baris->id }}.realisasi"></td>
                                <td style="text-align:right">
                                    <span class="badge {{ $baris->capaian_persen === null ? 'b-draft' : ($baris->capaian_persen >= 100 ? 'b-approve' : ($baris->capaian_persen >= 80 ? 'b-tunggu' : 'b-tolak')) }}">
                                        {{ $baris->capaian_persen !== null ? $baris->capaian_persen.'%' : '-' }}
                                    </span>
                                </td>
                                <td style="white-space:nowrap">
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="simpanBaris({{ $baris->id }})">💾</button>
                                    <button type="button" class="btn btn-red btn-sm" wire:click="hapusBaris({{ $baris->id }})" wire:confirm="Hapus baris ini?">🗑</button>
                                </td>
                            @else
                                <td class="muted">{{ $baris->sasaran ?: '-' }}</td>
                                <td>{{ $baris->indikator }}</td>
                                <td style="text-align:right">{{ $baris->target ?? '-' }}</td>
                                <td style="text-align:right">{{ $baris->realisasi ?? '-' }}</td>
                                <td style="text-align:right">
                                    <span class="badge {{ $baris->capaian_persen === null ? 'b-draft' : ($baris->capaian_persen >= 100 ? 'b-approve' : ($baris->capaian_persen >= 80 ? 'b-tunggu' : 'b-tolak')) }}">
                                        {{ $baris->capaian_persen !== null ? $baris->capaian_persen.'%' : '-' }}
                                    </span>
                                </td>
                            @endif
                        </tr>
                        @error("edit.{$baris->id}.indikator")
                            <tr><td colspan="6" style="color:var(--red);font-size:11.5px">{{ $message }}</td></tr>
                        @enderror
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($this->isTimSakip())
        <div class="card" style="margin-top:16px">
            <div class="sec"><span>Tambah Baris Custom</span></div>
            <div class="info">ℹ️ Untuk indikator yang tidak mengacu ke Master IKU manapun, atau format LAKIN yang berbeda dari bawaan sistem.</div>
            <div class="grid grid-2">
                <div class="field">
                    <label>Sasaran (opsional)</label>
                    <input type="text" class="inp filled" wire:model="sasaranBaru">
                </div>
                <div class="field">
                    <label>Indikator <span class="req">*</span></label>
                    <input type="text" class="inp filled" wire:model="indikatorBaru">
                    @error('indikatorBaru')
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>
                <div class="field">
                    <label>Target (opsional)</label>
                    <input type="number" step="0.01" class="inp filled" wire:model="targetBaru">
                </div>
                <div class="field">
                    <label>Realisasi (opsional)</label>
                    <input type="number" step="0.01" class="inp filled" wire:model="realisasiBaru">
                </div>
            </div>
            <div class="btn-row">
                <button type="button" class="btn btn-primary" wire:click="tambahBaris">＋ Tambah Baris</button>
            </div>
        </div>
    @endif
</div>
