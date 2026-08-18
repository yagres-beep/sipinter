<div>
    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="stat-grid" style="margin-bottom:16px">
        <div class="stat-tile">
            <div class="si">📊</div>
            <div class="sv">{{ $totalIku }}</div>
            <div class="sl">Total IKU</div>
        </div>
        <div class="stat-tile">
            <div class="si">🗂️</div>
            <div class="sv">{{ $totalTim }}</div>
            <div class="sl">Tim Berbeda</div>
        </div>
        <div class="stat-tile">
            <div class="si">{{ $totalTanpaPenanggungJawab > 0 ? '⚠️' : '✅' }}</div>
            <div class="sv">{{ $totalTanpaPenanggungJawab }}</div>
            <div class="sl">Belum Ada Penanggung Jawab</div>
        </div>
    </div>

    <div class="info">ℹ️ Chip abu-abu "via tim" otomatis dari Keanggotaan Tim — klik ✕ pada chip itu untuk mengecualikannya dari IKU ini saja (tetap anggota tim). Chip biru adalah penugasan manual tambahan — saring dulu lewat dropdown Tim (opsional), lalu pilih satu/lebih nama (tahan Ctrl/Cmd) sebelum menekan "Tambah Terpilih".</div>

    <div class="card">
        <div class="toolbar">
            <div class="search-box">
                🔍
                <input type="text" wire:model.live.debounce.300ms="cari" placeholder="Cari kode, indikator, tim, atau nama penanggung jawab…">
                <span wire:loading wire:target="cari" class="muted" style="font-size:11px">mencari…</span>
            </div>
            <select class="filter-sel" wire:model.live="filterStatus">
                <option value="">Semua status PJ</option>
                <option value="ada">Sudah ada PJ</option>
                <option value="belum">Belum ada PJ</option>
            </select>
            <button type="button" class="btn btn-ghost btn-sm" wire:click="resetFilter">↺ Reset</button>
        </div>

        <div class="table-scroll" style="max-height:640px" x-data="dataTable(10)">
            <table>
                <thead>
                    <tr>
                        <th class="th-sort {{ $urutanKolom === 'kode' ? 'active' : '' }}" wire:click="urutkan('kode')">
                            Kode <span class="th-arrow">{{ $urutanKolom === 'kode' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                        </th>
                        <th class="th-sort {{ $urutanKolom === 'indikator' ? 'active' : '' }}" wire:click="urutkan('indikator')">
                            Indikator <span class="th-arrow">{{ $urutanKolom === 'indikator' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                        </th>
                        <th>Tim</th>
                        <th style="min-width:280px">Penanggung Jawab</th>
                    </tr>
                </thead>
                <tbody x-ref="tbody">
                    @forelse ($data as $baris)
                        @php $iku = $baris['iku']; @endphp
                        <tr wire:key="iku-{{ $iku->id }}">
                            <td><b>{{ $iku->nomor_kode }}</b></td>
                            <td>
                                {{ $iku->indikator }}
                                @unless ($baris['punya_pj'])
                                    <span class="badge b-tolak" style="margin-left:6px">Belum ada PJ</span>
                                @endunless
                            </td>
                            <td class="muted">{{ $iku->tim }}</td>
                            <td>
                                <div style="display:flex;flex-direction:column;gap:8px;padding:8px 0">
                                    <div style="display:flex;flex-wrap:wrap;gap:6px">
                                        @foreach ($baris['otomatis'] as $orang)
                                            <span class="chip chip-auto">
                                                👤 {{ $orang->nama }} <span class="chip-via">via tim</span>
                                                <span class="chip-x" wire:click="kecualikanOtomatis({{ $iku->id }}, {{ $orang->id }})" title="Kecualikan dari IKU ini saja (tetap anggota tim)">✕</span>
                                            </span>
                                        @endforeach

                                        @foreach ($baris['manual'] as $penugasan)
                                            <span class="chip chip-tim" wire:key="manual-{{ $penugasan->id }}">
                                                👤 {{ $penugasan->user->nama }}
                                                <span class="chip-x" wire:click="hapusManual({{ $penugasan->id }})">✕</span>
                                            </span>
                                        @endforeach
                                    </div>

                                    @if ($baris['dikecualikan']->isNotEmpty())
                                        <div style="display:flex;flex-wrap:wrap;gap:6px">
                                            @foreach ($baris['dikecualikan'] as $p)
                                                <span class="chip chip-auto" style="opacity:.55" wire:key="kecuali-{{ $p['pengecualian_id'] }}">
                                                    👤 {{ $p['user']->nama }} <span class="chip-via">dikecualikan</span>
                                                    <span class="chip-x" wire:click="sertakanKembali({{ $p['pengecualian_id'] }})" title="Sertakan lagi sebagai PJ otomatis">↺</span>
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($baris['kandidat']->isNotEmpty())
                                        <div x-data="{ timFilter: '' }" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                                            @if ($baris['tim_kandidat']->isNotEmpty())
                                                <select class="inp filled" style="width:auto;display:inline-block" x-model="timFilter">
                                                    <option value="">Semua tim</option>
                                                    @foreach ($baris['tim_kandidat'] as $timOpsi)
                                                        <option value="{{ $timOpsi }}">{{ $timOpsi }}</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                            <select class="inp filled" style="width:auto;display:inline-block;min-width:170px" multiple size="3"
                                                wire:model="orangBaru.{{ $iku->id }}">
                                                @foreach ($baris['kandidat'] as $orang)
                                                    <option value="{{ $orang->id }}" :hidden="timFilter && !{{ \Illuminate\Support\Js::from($orang->namaTimList()) }}.includes(timFilter)">{{ $orang->nama }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="tambahManual({{ $iku->id }})">＋ Tambah Terpilih</button>
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" style="color:var(--muted)">Tidak ada IKU yang cocok dengan pencarian/filter ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-table-pagination />
        </div>
    </div>
</div>
