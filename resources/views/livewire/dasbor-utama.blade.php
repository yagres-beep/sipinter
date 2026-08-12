@php
    $namaBulan = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
    $angkaRomawi = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV'];
    $bulanUntukTriwulan = filled($filterTriwulan) ? range((((int) $filterTriwulan - 1) * 3) + 1, (((int) $filterTriwulan - 1) * 3) + 3) : range(1, 12);
@endphp

<div>
    <div class="page-title">Dasbor Pemantauan</div>
    <div class="page-sub">Progres capaian kinerja SAKIP — {{ $role === 'Tim SAKIP' ? 'klik baris untuk verifikasi' : 'klik baris untuk membuka halaman terkait' }}</div>

    <div class="grid grid-4" style="margin-bottom:16px">
        <x-stat-card icon="🎯" icon-class="ico-blue" :value="$ringkasan['total_iku']" label="Total IKU dipantau" />
        <x-stat-card icon="✅" icon-class="ico-teal" :value="$ringkasan['sudah_diverifikasi']" label="Sudah diverifikasi" />
        <x-stat-card icon="⏱️" icon-class="ico-amber" :value="$ringkasan['menunggu_verifikasi']" label="Menunggu verifikasi" />
        <x-stat-card icon="⚠️" icon-class="ico-red" :value="$ringkasan['lewat_tenggat']" label="Lewat tenggat" />
    </div>

    @if ($ikuBelumTerisiTriwulanIni->isNotEmpty())
        <div class="info warn" style="margin-bottom:16px">
            ⚠️ <b>{{ $ikuBelumTerisiTriwulanIni->count() }} IKU belum ada isian sama sekali di Triwulan {{ ['I', 'II', 'III', 'IV'][$triwulanBerjalan - 1] }} ini</b>
            — tidak wajib diisi tiap bulan, tapi disarankan minimal satu kegiatan per triwulan:
            {{ $ikuBelumTerisiTriwulanIni->pluck('kode')->join(', ', ', dan ') }}.
        </div>
    @endif

    <div class="card">
        <div class="sec"><span>Status Pengisian per IKU</span></div>

        <div class="toolbar">
            <div class="search-box">
                🔍
                <input type="text" wire:model.live.debounce.300ms="cari" placeholder="Cari kode, indikator, atau tim…">
                <span wire:loading wire:target="cari" class="muted" style="font-size:11px">mencari…</span>
            </div>
            <select class="filter-sel" wire:model.live="filterTriwulan">
                <option value="">Semua triwulan</option>
                <option value="1">Triwulan I</option>
                <option value="2">Triwulan II</option>
                <option value="3">Triwulan III</option>
                <option value="4">Triwulan IV</option>
            </select>
            <select class="filter-sel" wire:model.live="filterBulan" wire:key="filter-bulan-tw-{{ $filterTriwulan }}">
                <option value="">Semua bulan</option>
                @foreach ($bulanUntukTriwulan as $b)
                    <option value="{{ $b }}">{{ $namaBulan[$b] }}</option>
                @endforeach
            </select>
            <button type="button" class="btn btn-ghost btn-sm" wire:click="resetFilter">↺ Reset</button>
        </div>

        @if ($daftarKegiatan->isEmpty())
            <p style="color:var(--muted);font-size:13px">Tidak ada kegiatan yang cocok dengan pencarian/filter ini.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th class="th-sort {{ $urutanKolom === 'kode' ? 'active' : '' }}" wire:click="urutkan('kode')">
                            Kode <span class="th-arrow">{{ $urutanKolom === 'kode' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                        </th>
                        <th>Indikator Kinerja</th>
                        <th class="th-sort {{ $urutanKolom === 'periode' ? 'active' : '' }}" wire:click="urutkan('periode')">
                            Bulan <span class="th-arrow">{{ $urutanKolom === 'periode' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                        </th>
                        <th>TW</th>
                        <th class="th-sort {{ $urutanKolom === 'tim' ? 'active' : '' }}" wire:click="urutkan('tim')">
                            Tim <span class="th-arrow">{{ $urutanKolom === 'tim' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                        </th>
                        <th class="th-sort {{ $urutanKolom === 'status' ? 'active' : '' }}" wire:click="urutkan('status')">
                            Status <span class="th-arrow">{{ $urutanKolom === 'status' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($daftarKegiatan as $kegiatan)
                        @php $tautan = $tautanBaris[$kegiatan->id] ?? null; @endphp
                        <tr @if ($tautan) class="clickable" onclick="window.location='{{ $tautan }}'" @endif>
                            <td><b>{{ $kegiatan->masterIku->kode ?? '—' }}</b></td>
                            <td>{{ $kegiatan->masterIku->indikator ?? '—' }}</td>
                            <td>{{ $namaBulan[$kegiatan->periode->bulan ?? 0] ?? '—' }}</td>
                            <td class="muted">{{ $angkaRomawi[$kegiatan->periode->triwulan ?? 0] ?? '—' }}</td>
                            <td class="muted">{{ $kegiatan->masterIku->tim ?? '—' }}</td>
                            <td><x-badge-status :status="$kegiatan->status_dokumen" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
