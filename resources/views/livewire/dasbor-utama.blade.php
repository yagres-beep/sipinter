@php
    $namaBulan = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
    $angkaRomawi = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV'];
    $bulanUntukTriwulan = filled($filterTriwulan) ? range((((int) $filterTriwulan - 1) * 3) + 1, (((int) $filterTriwulan - 1) * 3) + 3) : range(1, 12);
@endphp

<div>
    <div class="page-head">
        <div class="page-title">Dasbor Pemantauan</div>
        <div class="page-sub">Progres capaian kinerja SAKIP — {{ $role === 'Tim SAKIP' ? 'klik baris untuk verifikasi' : 'klik baris untuk membuka halaman terkait' }}</div>
    </div>

    @if ($peringatanStorage)
        <div class="info red" style="margin-bottom:16px">
            ⚠️ <b>{{ $peringatanStorage }}</b>
            <a wire:navigate href="{{ route('storage-accounts.index') }}" style="margin-left:6px;text-decoration:underline">Buka Akun &amp; Storage →</a>
        </div>
    @endif

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
                <span wire:loading wire:target="cari" class="muted" style="font-size:11px"><i class="spin"></i> mencari…</span>
            </div>
            <select class="filter-sel" wire:model.live="filterTriwulan" wire:loading.attr="disabled" wire:target="filterTriwulan,filterBulan,resetFilter">
                <option value="">Semua triwulan</option>
                <option value="1">Triwulan I</option>
                <option value="2">Triwulan II</option>
                <option value="3">Triwulan III</option>
                <option value="4">Triwulan IV</option>
            </select>
            <select class="filter-sel" wire:model.live="filterBulan" wire:key="filter-bulan-tw-{{ $filterTriwulan }}" wire:loading.attr="disabled" wire:target="filterTriwulan,filterBulan,resetFilter">
                <option value="">Semua bulan</option>
                @foreach ($bulanUntukTriwulan as $b)
                    <option value="{{ $b }}">{{ $namaBulan[$b] }}</option>
                @endforeach
            </select>
            <button type="button" class="btn btn-ghost btn-sm" wire:click="resetFilter" wire:loading.attr="disabled" wire:target="filterTriwulan,filterBulan,resetFilter">↺ Reset</button>
        </div>

        @if ($daftarCapaian->isEmpty())
            <p style="color:var(--muted);font-size:13px">Tidak ada isian yang cocok dengan pencarian/filter ini.</p>
        @else
            <div class="table-scroll" wire:loading.class="table-loading" wire:target="filterTriwulan,filterBulan,cari,urutkan,resetFilter" x-data="dataTable(10)">
                <table>
                    <thead>
                        <tr>
                            <th class="th-sort {{ $urutanKolom === 'kode' ? 'active' : '' }}" wire:click="urutkan('kode')">
                                Kode <span class="th-arrow">{{ $urutanKolom === 'kode' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                            </th>
                            <th class="th-sort {{ $urutanKolom === 'indikator' ? 'active' : '' }}" wire:click="urutkan('indikator')">
                                Indikator Kinerja <span class="th-arrow">{{ $urutanKolom === 'indikator' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                            </th>
                            <th class="th-sort {{ $urutanKolom === 'periode' ? 'active' : '' }}" wire:click="urutkan('periode')">
                                Bulan <span class="th-arrow">{{ $urutanKolom === 'periode' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                            </th>
                            <th class="th-sort {{ $urutanKolom === 'triwulan' ? 'active' : '' }}" wire:click="urutkan('triwulan')">
                                TW <span class="th-arrow">{{ $urutanKolom === 'triwulan' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                            </th>
                            <th class="th-sort {{ $urutanKolom === 'tim' ? 'active' : '' }}" wire:click="urutkan('tim')">
                                Tim <span class="th-arrow">{{ $urutanKolom === 'tim' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                            </th>
                            <th>Item</th>
                            <th>Rincian Status Item</th>
                            <th class="th-sort {{ $urutanKolom === 'status' ? 'active' : '' }}" wire:click="urutkan('status')">
                                Status <span class="th-arrow">{{ $urutanKolom === 'status' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody x-ref="tbody">
                        @foreach ($daftarCapaian as $capaian)
                            @php $tautan = $tautanBaris[$capaian->id] ?? null; @endphp
                            <tr wire:key="capaian-{{ $capaian->id }}" @if ($tautan) class="clickable" onclick="Livewire.navigate('{{ $tautan }}')" @endif>
                                <td><b>{{ $capaian->masterIku->kode ?? '—' }}</b></td>
                                <td>{{ $capaian->masterIku->indikator ?? '—' }}</td>
                                <td>{{ $namaBulan[$capaian->periode->bulan ?? 0] ?? '—' }}</td>
                                <td class="muted">{{ $angkaRomawi[$capaian->periode->triwulan ?? 0] ?? '—' }}</td>
                                <td class="muted">{{ $timPerCapaian->get($capaian->id) ?: '—' }}</td>
                                <td class="muted">{{ $jumlahItem->get($capaian->id, 0) }}</td>
                                <td><x-rincian-status-kegiatan :rincian="$rincianStatusKegiatan->get($capaian->id, collect())" :rincianKendala="$rincianStatusKendala->get($capaian->id, collect())" :rincianRtl="$rincianStatusRtl->get($capaian->id, collect())" /></td>
                                <td><x-badge-status :status="$capaian->status" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <x-table-pagination />
            </div>
        @endif
    </div>
</div>
