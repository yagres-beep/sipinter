<div>
    <div class="stat-grid" style="margin-bottom:16px">
        <div class="stat-tile">
            <div class="si">📊</div>
            <div class="sv">{{ $ikuList->count() }}</div>
            <div class="sl">Total IKU</div>
        </div>
        <div class="stat-tile">
            <div class="si">🗂️</div>
            <div class="sv">{{ $ikuPerTim->count() }}</div>
            <div class="sl">Tim Berbeda</div>
        </div>
        <div class="stat-tile">
            <div class="si">{{ $totalTanpaPenanggungJawab > 0 ? '⚠️' : '✅' }}</div>
            <div class="sv">{{ $totalTanpaPenanggungJawab }}</div>
            <div class="sl">Belum Ada Penanggung Jawab</div>
        </div>
    </div>

    <div class="toolbar" style="margin-bottom:16px">
        <div class="search-box">
            🔍
            <input type="text" wire:model.live.debounce.300ms="cari" placeholder="Cari kode, indikator, atau tim…">
            <span wire:loading wire:target="cari" class="muted" style="font-size:11px">mencari…</span>
        </div>
    </div>

    <div class="info">ℹ️ Chip abu-abu "via tim" otomatis dari Keanggotaan Tim (tim IKU sama dengan tim Ketua Tim). Chip biru adalah penugasan manual tambahan — saring dulu lewat dropdown Tim (opsional), lalu pilih satu/lebih nama (tahan Ctrl/Cmd untuk pilih beberapa) sebelum menekan "Tambah Terpilih".</div>

    @forelse ($ikuPerTim as $namaTim => $daftarIku)
        <div class="tim-group">
            <div class="tim-group-h">
                <span class="tgh-dot"></span>{{ $namaTim ?: 'Tanpa Tim' }}
                <span class="tgh-count">{{ $daftarIku->count() }} IKU</span>
            </div>

            @foreach ($daftarIku as $iku)
                @php
                    $otomatis = $otomatisPerTim->get($iku->tim, collect());
                    $idOtomatis = $otomatis->pluck('id');
                    $sudahDitugaskan = $iku->penugasanManual->pluck('user_id')->merge($idOtomatis);
                    $kandidat = $ketuaTimList->whereNotIn('id', $sudahDitugaskan);
                    $punyaPenanggungJawab = $otomatis->isNotEmpty() || $iku->penugasanManual->isNotEmpty();
                @endphp
                @php
                    $timKandidat = $kandidat->flatMap(fn ($orang) => $orang->namaTimList())->unique()->sort()->values();
                @endphp
                <div class="assign-card {{ ! $punyaPenanggungJawab ? 'ac-kosong' : '' }}" wire:key="iku-{{ $iku->id }}">
                    <div class="ac-person">
                        <div class="ac-av">{{ $iku->kode }}</div>
                        <div style="flex:1">
                            <div class="ac-name">{{ $iku->indikator }}</div>
                            <div class="ac-sub">Tim: {{ $iku->tim }}</div>
                        </div>
                        @if (! $punyaPenanggungJawab)
                            <span class="badge b-tolak">Belum ada PJ</span>
                        @endif
                    </div>
                    <div class="ac-chips">
                        @foreach ($otomatis as $orang)
                            <span class="chip chip-auto">👤 {{ $orang->nama }} <span class="chip-via">via tim</span></span>
                        @endforeach

                        @foreach ($iku->penugasanManual as $penugasan)
                            <span class="chip chip-tim" wire:key="manual-{{ $penugasan->id }}">
                                👤 {{ $penugasan->user->nama }}
                                <span class="chip-x" wire:click="hapusManual({{ $penugasan->id }})">✕</span>
                            </span>
                        @endforeach

                        @if ($kandidat->isNotEmpty())
                            <div x-data="{ timFilter: '' }" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                                @if ($timKandidat->isNotEmpty())
                                    <select class="inp filled" style="width:auto;display:inline-block" x-model="timFilter">
                                        <option value="">Semua tim</option>
                                        @foreach ($timKandidat as $timOpsi)
                                            <option value="{{ $timOpsi }}">{{ $timOpsi }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <select class="inp filled" style="width:auto;display:inline-block;min-width:170px" multiple size="3"
                                    wire:model="orangBaru.{{ $iku->id }}">
                                    @foreach ($kandidat as $orang)
                                        <option value="{{ $orang->id }}" :hidden="timFilter && !{{ \Illuminate\Support\Js::from($orang->namaTimList()) }}.includes(timFilter)">{{ $orang->nama }}</option>
                                    @endforeach
                                </select>
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="tambahManual({{ $iku->id }})">＋ Tambah Terpilih</button>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @empty
        <p style="color:var(--muted);font-size:13px">Tidak ada IKU yang cocok dengan pencarian ini.</p>
    @endforelse
</div>
