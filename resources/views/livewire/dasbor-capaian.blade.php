@php
    $labelStatus = [
        'draft' => 'Draft',
        'diajukan' => 'Diajukan',
        'diverifikasi' => 'Diverifikasi',
        'disetujui' => 'Disetujui',
        'dikembalikan' => 'Dikembalikan',
    ];
    $badgeStatus = [
        'draft' => 'b-draft',
        'diajukan' => 'b-ajukan',
        'diverifikasi' => 'b-verif',
        'disetujui' => 'b-approve',
        'dikembalikan' => 'b-tolak',
    ];
@endphp

<div>
    <div class="page-head">
        <div class="page-title">Dasbor Kinerja</div>
        <div class="page-sub">Status isian, progres verifikasi, dan rekapitulasi capaian per IKU — terbarui setiap saat (RF-49, RF-50).</div>
    </div>

    <x-filter-periode :tahun="$tahun" :triwulan="$triwulan" mode="triwulanan" />

    <div class="stat-grid" style="margin-bottom:16px">
        <div class="stat-tile">
            <div class="si">📊</div>
            <div class="sv">{{ $ringkasan['jumlah_iku_total'] }}</div>
            <div class="sl">Total Master IKU</div>
        </div>
        <div class="stat-tile">
            <div class="si">📝</div>
            <div class="sv">{{ $ringkasan['jumlah_kegiatan'] }}</div>
            <div class="sl">Kegiatan Triwulan Ini</div>
        </div>
        <div class="stat-tile">
            <div class="si">🎯</div>
            <div class="sv">{{ $ringkasan['rata_rata_capaian'] }}%</div>
            <div class="sl">Rata-rata Capaian</div>
        </div>
        <div class="stat-tile">
            <div class="si">✅</div>
            <div class="sv">{{ $ringkasan['jumlah_iku_aktif_triwulan'] }}</div>
            <div class="sl">IKU Aktif Triwulan Ini</div>
        </div>
    </div>

    <div class="card">
        <div class="card-h">📋 Status Dokumen Kegiatan — Triwulan {{ ['I', 'II', 'III', 'IV'][$triwulan - 1] }} {{ $tahun }}</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            @foreach ($labelStatus as $key => $label)
                <span class="badge {{ $badgeStatus[$key] }}">{{ $label }}: {{ $ringkasan['status_breakdown'][$key] ?? 0 }}</span>
            @endforeach
        </div>
    </div>

    <div class="card">
        <div class="card-h">📈 Progres Verifikasi per Triwulan {{ $tahun }}</div>
        <div class="fhint" style="margin:-6px 0 14px">Klik salah satu bar untuk melihat daftar kegiatan triwulan tsb — mana yang sudah &amp; belum diverifikasi.</div>
        <div x-data="{ terbukaTw: null }">
            @foreach ($progresTriwulan as $tw => $data)
                <div style="margin-bottom:6px">
                    <button type="button" @click="terbukaTw = (terbukaTw === {{ $tw }} ? null : {{ $tw }})"
                        style="display:block;width:100%;text-align:left;background:none;border:none;padding:8px 0;cursor:pointer;font:inherit;color:inherit;{{ $data['total'] === 0 ? 'cursor:not-allowed;opacity:.6' : '' }}"
                        @disabled($data['total'] === 0)>
                        <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:5px">
                            <span style="font-weight:600">Triwulan {{ ['I', 'II', 'III', 'IV'][$tw - 1] }} <span style="color:var(--faint)" x-text="terbukaTw === {{ $tw }} ? '▲' : '▼'"></span></span>
                            <span style="color:var(--muted)">{{ $data['selesai'] }}/{{ $data['total'] }} kegiatan ({{ $data['persen'] }}%)</span>
                        </div>
                        <div class="quota-bar">
                            <div class="quota-fill {{ $data['persen'] < 50 ? 'warn' : '' }}" style="width: {{ $data['persen'] }}%"></div>
                        </div>
                    </button>

                    <div x-show="terbukaTw === {{ $tw }}" x-cloak style="margin:10px 0 16px;border:1.5px solid var(--line);border-radius:11px;max-height:320px;overflow-y:auto">
                        @forelse ($data['kegiatan'] as $kegiatan)
                            <div style="display:flex;align-items:center;gap:10px;justify-content:space-between;padding:9px 13px;font-size:12.5px;border-bottom:1px solid var(--line2)">
                                <div>
                                    <b>{{ $kegiatan->masterIku?->kode }}</b>
                                    <span class="muted">— {{ \Illuminate\Support\Str::limit($kegiatan->uraian_kegiatan, 70) }}</span>
                                </div>
                                <span class="badge {{ $badgeStatus[$kegiatan->status_dokumen] }}" style="flex:none">{{ $labelStatus[$kegiatan->status_dokumen] }}</span>
                            </div>
                        @empty
                            <div style="padding:13px;font-size:12.5px;color:var(--muted)">Belum ada kegiatan pada triwulan ini.</div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="card">
        <div class="card-h">
            📊 Rekap per IKU — Triwulan {{ ['I', 'II', 'III', 'IV'][$triwulan - 1] }} {{ $tahun }}
            <span class="badge b-verif">rata-rata {{ $rataRataPersentase }}%</span>
        </div>
        <div class="fhint" style="margin:-6px 0 14px">ℹ️ Target &amp; Realisasi berkolom "Kumulatif" adalah akumulasi dari Triwulan I sampai triwulan terpilih (bukan hanya triwulan ini sendiri) — sesuai Kertas Kerja Pengukuran Kinerja Triwulanan. Capaian % (Triwulanan) = Realisasi Kumulatif ÷ Target TW Kumulatif; Capaian % (Setahun) = Realisasi Kumulatif ÷ Target PK (target tahunan penuh).</div>

        <div class="table-scroll" style="max-height:460px" x-data="dataTable(10)">
            <table>
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Indikator</th>
                        <th style="text-align:center">Kegiatan</th>
                        <th style="text-align:right">Target TW <span class="muted" style="font-weight:400">(Kumulatif)</span></th>
                        <th style="text-align:right">Realisasi <span class="muted" style="font-weight:400">(Kumulatif)</span></th>
                        <th style="text-align:right">Capaian % <span class="muted" style="font-weight:400">(Triwulanan)</span></th>
                        <th style="text-align:right">Target PK <span class="muted" style="font-weight:400">(Setahun)</span></th>
                        <th style="text-align:right">Capaian % <span class="muted" style="font-weight:400">(Setahun)</span></th>
                        <th style="text-align:right">Realisasi <span class="muted" style="font-weight:400">(TW Ini)</span></th>
                    </tr>
                </thead>
                <tbody x-ref="tbody">
                    @forelse ($rekap as $baris)
                        <tr wire:key="rekap-{{ $baris['iku']->id }}">
                            <td>{{ $baris['iku']->kode }}</td>
                            <td>{{ $baris['iku']->indikator }}</td>
                            <td style="text-align:center">{{ $baris['jumlah_kegiatan'] }}</td>
                            <td style="text-align:right">{{ $baris['target_tw'] }}</td>
                            <td style="text-align:right">{{ $baris['realisasi_ytd'] }}</td>
                            <td style="text-align:right">
                                @if ($baris['persentase'] === null)
                                    <span class="badge b-draft">-</span>
                                @else
                                    <span class="badge {{ $baris['persentase'] >= 90 ? 'b-approve' : ($baris['persentase'] >= 70 ? 'b-tunggu' : 'b-tolak') }}">
                                        {{ $baris['persentase'] }}%
                                    </span>
                                @endif
                            </td>
                            <td style="text-align:right">{{ $baris['target_pk'] }}</td>
                            <td style="text-align:right">
                                @if ($baris['persentase_setahun'] === null)
                                    <span class="badge b-draft">-</span>
                                @else
                                    <span class="badge {{ $baris['persentase_setahun'] >= 90 ? 'b-approve' : ($baris['persentase_setahun'] >= 70 ? 'b-tunggu' : 'b-tolak') }}">
                                        {{ $baris['persentase_setahun'] }}%
                                    </span>
                                @endif
                            </td>
                            <td style="text-align:right" class="muted">{{ $baris['realisasi'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" style="color:var(--muted)">Belum ada capaian terverifikasi pada periode ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-table-pagination />
        </div>
    </div>
</div>
