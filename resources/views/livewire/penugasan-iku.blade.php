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
                <span wire:loading wire:target="cari" class="muted" style="font-size:11px"><i class="spin"></i> mencari…</span>
            </div>
            <select class="filter-sel" wire:model.live="filterStatus" wire:loading.attr="disabled" wire:target="filterStatus,cari,urutkan,resetFilter">
                <option value="">Semua status PJ</option>
                <option value="ada">Sudah ada PJ</option>
                <option value="belum">Belum ada PJ</option>
            </select>
            <button type="button" class="btn btn-ghost btn-sm" wire:click="resetFilter" wire:loading.attr="disabled" wire:target="filterStatus,cari,urutkan,resetFilter">↺ Reset</button>
            <span wire:loading wire:target="filterStatus,urutkan,resetFilter" class="muted" style="font-size:11px"><i class="spin"></i> memuat…</span>
        </div>

        <div class="table-scroll" style="max-height:640px" wire:loading.class="table-loading" wire:target="filterStatus,cari,urutkan,resetFilter" x-data="dataTable(10)">
            <table>
                <thead>
                    <tr>
                        <th class="th-sort {{ $urutanKolom === 'kode' ? 'active' : '' }}" wire:click="urutkan('kode')">
                            Kode <span class="th-arrow">{{ $urutanKolom === 'kode' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                        </th>
                        <th class="th-sort {{ $urutanKolom === 'indikator' ? 'active' : '' }}" wire:click="urutkan('indikator')">
                            Indikator <span class="th-arrow">{{ $urutanKolom === 'indikator' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                        </th>
                        <th style="width:120px">Tim</th>
                        <th style="min-width:260px;width:260px">Penanggung Jawab</th>
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
                                <div style="display:flex;flex-direction:column;gap:6px;padding:6px 0" x-data="{ open: false, timFilter: '' }">
                                    <div style="display:flex;flex-wrap:wrap;gap:5px;align-items:center">
                                        @foreach ($baris['otomatis'] as $orang)
                                            <span class="chip chip-auto">
                                                {{ $orang->nama }} <span class="chip-via">via tim</span>
                                                <span class="chip-x" wire:click="kecualikanOtomatis({{ $iku->id }}, {{ $orang->id }})" wire:loading.class="btn-busy" wire:target="kecualikanOtomatis({{ $iku->id }}, {{ $orang->id }})" title="Kecualikan dari IKU ini saja (tetap anggota tim)">✕</span>
                                            </span>
                                        @endforeach

                                        @foreach ($baris['manual'] as $penugasan)
                                            <span class="chip chip-tim" wire:key="manual-{{ $penugasan->id }}">
                                                {{ $penugasan->user->nama }}
                                                <span class="chip-x" wire:click="hapusManual({{ $penugasan->id }})" wire:loading.class="btn-busy" wire:target="hapusManual({{ $penugasan->id }})">✕</span>
                                            </span>
                                        @endforeach

                                        @if ($baris['kandidat']->isNotEmpty())
                                            <button type="button" class="chip-add" x-show="!open" x-cloak @click="open = true">＋ Tambah PJ</button>
                                        @endif
                                    </div>

                                    @if ($baris['dikecualikan']->isNotEmpty())
                                        <div style="display:flex;flex-wrap:wrap;gap:5px">
                                            @foreach ($baris['dikecualikan'] as $p)
                                                <span class="chip chip-auto" style="opacity:.55" wire:key="kecuali-{{ $p['pengecualian_id'] }}">
                                                    {{ $p['user']->nama }} <span class="chip-via">dikecualikan</span>
                                                    <span class="chip-x" wire:click="sertakanKembali({{ $p['pengecualian_id'] }})" wire:loading.class="btn-busy" wire:target="sertakanKembali({{ $p['pengecualian_id'] }})" title="Sertakan lagi sebagai PJ otomatis">↺</span>
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($baris['kandidat']->isNotEmpty())
                                        <div class="pj-picker" x-show="open" x-cloak>
                                            @if ($baris['tim_kandidat']->isNotEmpty())
                                                <select class="inp filled" x-model="timFilter">
                                                    <option value="">Semua tim</option>
                                                    @foreach ($baris['tim_kandidat'] as $timOpsi)
                                                        <option value="{{ $timOpsi }}">{{ $timOpsi }}</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                            <select class="pj-picker-select" multiple size="{{ min(5, max(2, $baris['kandidat']->count())) }}"
                                                wire:model="orangBaru.{{ $iku->id }}">
                                                @foreach ($baris['kandidat'] as $orang)
                                                    <option value="{{ $orang->id }}" :hidden="!!timFilter && !{{ \Illuminate\Support\Js::from($orang->namaTimList()) }}.includes(timFilter)">{{ $orang->nama }}</option>
                                                @endforeach
                                            </select>
                                            <div style="display:flex;gap:6px">
                                                <button type="button" class="btn btn-primary btn-sm" wire:click="tambahManual({{ $iku->id }})"
                                                    wire:loading.attr="disabled" wire:target="tambahManual({{ $iku->id }})">
                                                    <span wire:loading.remove wire:target="tambahManual({{ $iku->id }})">＋ Tambah Terpilih</span>
                                                    <span wire:loading wire:target="tambahManual({{ $iku->id }})"><i class="spin"></i> Menambahkan…</span>
                                                </button>
                                                <button type="button" class="btn btn-ghost btn-sm" @click="open = false; timFilter = ''">Batal</button>
                                            </div>
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
