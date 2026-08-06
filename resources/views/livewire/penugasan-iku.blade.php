<div>
    <div class="info">ℹ️ Chip abu-abu "via tim" otomatis dari Keanggotaan Tim (tim IKU sama dengan tim Ketua Tim). Chip biru adalah penugasan manual tambahan.</div>

    @forelse ($ikuList as $iku)
        @php
            $otomatis = $otomatisPerTim->get($iku->tim, collect());
            $idOtomatis = $otomatis->pluck('id');
            $sudahDitugaskan = $iku->penugasanManual->pluck('user_id')->merge($idOtomatis);
            $kandidat = $ketuaTimList->whereNotIn('id', $sudahDitugaskan);
        @endphp
        <div class="assign-card" wire:key="iku-{{ $iku->id }}">
            <div class="ac-person">
                <div class="ac-av">{{ $iku->kode }}</div>
                <div>
                    <div class="ac-name">{{ $iku->indikator }}</div>
                    <div class="ac-sub">Tim: {{ $iku->tim }}</div>
                </div>
            </div>
            <div class="ac-chips">
                @foreach ($otomatis as $orang)
                    <span class="chip chip-auto">{{ $orang->nama }} <span class="chip-via">via tim</span></span>
                @endforeach

                @foreach ($iku->penugasanManual as $penugasan)
                    <span class="chip chip-tim" wire:key="manual-{{ $penugasan->id }}">
                        {{ $penugasan->user->nama }}
                        <span style="cursor:pointer;margin-left:4px" wire:click="hapusManual({{ $penugasan->id }})">✕</span>
                    </span>
                @endforeach

                @if ($kandidat->isNotEmpty())
                    <select class="inp filled" style="width:auto;display:inline-block" wire:model="orangBaru.{{ $iku->id }}">
                        <option value="">＋ Tambah manual…</option>
                        @foreach ($kandidat as $orang)
                            <option value="{{ $orang->id }}">{{ $orang->nama }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="tambahManual({{ $iku->id }})">＋ Tambah</button>
                @endif
            </div>
        </div>
    @empty
        <p style="color:var(--muted);font-size:13px">Belum ada data Master IKU.</p>
    @endforelse
</div>
