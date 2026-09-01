@php
    $namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $statusWorklist = [\App\Models\Capaian::STATUS_DIAJUKAN, \App\Models\Capaian::STATUS_SEDANG_DITANGANI];
    $isWorklistDefault = empty(array_diff($status, $statusWorklist)) && empty(array_diff($statusWorklist, $status));
@endphp

<div>
    <div class="page-head">
        <div class="page-title">Verifikasi Bukti &amp; Angka Capaian</div>
        <div class="page-sub">
            @if (empty($status))
                Menampilkan isian dari SEMUA status — pilih status tertentu di filter untuk mempersempit.
            @else
                Daftar isian berstatus
                @foreach ($status as $s)
                    <x-badge-status :status="$s" />
                @endforeach
                — pilih status lain di filter untuk menelusuri riwayat.
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <x-filter-periode :tahun="$tahun" :triwulan="$triwulan" :bulan="$bulan" mode="lengkap" />

    @if ($isWorklistDefault)
        <div class="info teal">✅ Isi analisis capaian &amp; angka, verifikasi tiap berkas, beri catatan bila tidak sesuai.</div>
    @elseif (empty($status))
        <div class="info">ℹ️ Menampilkan riwayat SEMUA status sekaligus — bukan worklist verifikasi. Pilih "Diajukan"/"Sedang Ditangani" di filter untuk kembali ke daftar yang perlu diperiksa.</div>
    @else
        <div class="info">ℹ️ Menampilkan riwayat isian berstatus
            @foreach ($status as $s)
                <x-badge-status :status="$s" />
            @endforeach
            — bukan worklist verifikasi. Buka "Reset" untuk kembali ke daftar yang perlu diperiksa.
        </div>
    @endif

    <div class="card">
        <div class="toolbar">
            <div class="search-box">
                🔍
                <input type="text" wire:model.live.debounce.300ms="cari" placeholder="Cari kode, indikator, atau tim…">
                <span wire:loading wire:target="cari" class="muted" style="font-size:11px"><i class="spin"></i> mencari…</span>
            </div>
            <div class="filter-multi" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                <button type="button" class="filter-sel filter-sel-btn" @click="open = !open" :aria-expanded="open" wire:loading.attr="disabled" wire:target="status,bulan,triwulan,tahun,cari,urutkan,resetFilter">
                    @if (empty($status))
                        Semua Status
                    @elseif (count($status) === 1)
                        {{ ucwords(str_replace('_', ' ', $status[0])) }}
                    @else
                        {{ count($status) }} Status Dipilih
                    @endif
                    <span class="fm-caret">▾</span>
                </button>

                <div class="filter-multi-panel" x-show="open" x-cloak x-transition>
                    @foreach ($statusTersedia as $opsi)
                        <label class="fm-item">
                            <input type="checkbox" value="{{ $opsi }}" wire:model.live="status">
                            {{ ucwords(str_replace('_', ' ', $opsi)) }}
                        </label>
                    @endforeach
                    <button type="button" class="fm-clear" wire:click="$set('status', [])" wire:loading.attr="disabled">Semua Status</button>
                </div>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" wire:click="resetFilter" wire:loading.attr="disabled" wire:target="status,bulan,triwulan,tahun,cari,urutkan,resetFilter">↺ Reset</button>
            <span wire:loading wire:target="status,bulan,triwulan,tahun,cari,urutkan,resetFilter" class="muted" style="font-size:11px"><i class="spin"></i> memuat…</span>
        </div>

        <div class="table-scroll" style="max-height:520px" wire:loading.class="table-loading" wire:target="status,bulan,triwulan,tahun,cari,urutkan,resetFilter" x-data="dataTable(10)">
            <table>
                <thead>
                    <tr>
                        <th class="th-sort {{ $urutanKolom === 'kode' ? 'active' : '' }}" wire:click="urutkan('kode')">
                            IKU <span class="th-arrow">{{ $urutanKolom === 'kode' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                        </th>
                        <th class="th-sort {{ $urutanKolom === 'periode' ? 'active' : '' }}" wire:click="urutkan('periode')">
                            Periode <span class="th-arrow">{{ $urutanKolom === 'periode' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                        </th>
                        <th class="th-sort {{ $urutanKolom === 'tim' ? 'active' : '' }}" wire:click="urutkan('tim')">
                            Tim <span class="th-arrow">{{ $urutanKolom === 'tim' ? ($urutanArah === 'asc' ? '▲' : '▼') : '↕' }}</span>
                        </th>
                        <th>Item</th>
                        <th>Rincian Status Item</th>
                        <th>Status</th>
                        <th style="text-align:right">Tindakan</th>
                    </tr>
                </thead>
                <tbody x-ref="tbody">
                    @forelse ($daftarCapaian as $capaian)
                        <tr wire:key="capaian-{{ $capaian->id }}">
                            <td>{{ $capaian->masterIku->kode }} — {{ $capaian->masterIku->indikator }}</td>
                            <td>{{ $namaBulan[$capaian->periode->bulan - 1] }} {{ $capaian->periode->tahun }}</td>
                            <td class="muted">{{ $timPerCapaian->get($capaian->id) ?: '—' }}</td>
                            <td>{{ $jumlahItem->get($capaian->id, 0) }} item</td>
                            <td><x-rincian-status-kegiatan :rincian="$rincianStatusKegiatan->get($capaian->id, collect())" :rincianKendala="$rincianStatusKendala->get($capaian->id, collect())" :rincianRtl="$rincianStatusRtl->get($capaian->id, collect())" /></td>
                            <td><x-badge-status :status="$capaian->status" /></td>
                            <td style="text-align:right">
                                <a wire:navigate href="{{ route('verifikasi.show', $capaian) }}" class="btn btn-primary btn-sm">{{ $capaian->status === 'diajukan' ? 'Periksa →' : 'Lihat →' }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="color:var(--muted)">Tidak ada isian {{ empty($status) ? '' : 'berstatus '.implode(', ', array_map(fn ($s) => ucwords(str_replace('_', ' ', $s)), $status)).' ' }}pada periode/kata kunci ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-table-pagination />
        </div>
    </div>
</div>
