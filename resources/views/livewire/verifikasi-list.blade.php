@php
    $namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
@endphp

<div>
    <div class="page-head">
        <div class="page-title">Verifikasi Bukti &amp; Angka Capaian</div>
        <div class="page-sub">
            @if ($status === '')
                Menampilkan isian dari SEMUA status — pilih status tertentu di filter untuk mempersempit.
            @else
                Daftar isian berstatus <x-badge-status :status="$status" /> — pilih status lain di filter untuk menelusuri riwayat.
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <x-filter-periode :tahun="$tahun" :triwulan="$triwulan" :bulan="$bulan" mode="lengkap" />

    @if ($status === 'diajukan')
        <div class="info teal">✅ Isi analisis capaian &amp; angka, verifikasi tiap berkas, beri catatan bila tidak sesuai.</div>
    @elseif ($status === '')
        <div class="info">ℹ️ Menampilkan riwayat SEMUA status sekaligus — bukan worklist verifikasi. Pilih "Diajukan" di filter untuk kembali ke daftar yang perlu diperiksa.</div>
    @else
        <div class="info">ℹ️ Menampilkan riwayat isian berstatus <x-badge-status :status="$status" /> — bukan worklist verifikasi. Buka "Reset" atau pilih "Diajukan" untuk kembali ke daftar yang perlu diperiksa.</div>
    @endif

    <div class="card">
        <div class="toolbar">
            <div class="search-box">
                🔍
                <input type="text" wire:model.live.debounce.300ms="cari" placeholder="Cari kode, indikator, atau tim…">
                <span wire:loading wire:target="cari" class="muted" style="font-size:11px"><i class="spin"></i> mencari…</span>
            </div>
            <select class="filter-sel" wire:model.live="status" wire:loading.attr="disabled" wire:target="status,bulan,triwulan,tahun,cari,urutkan,resetFilter">
                <option value="">Semua Status</option>
                @foreach ($statusTersedia as $opsi)
                    <option value="{{ $opsi }}">{{ ucwords(str_replace('_', ' ', $opsi)) }}</option>
                @endforeach
            </select>
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
                        <th>Kegiatan Pendukung</th>
                        <th>Rincian Status Kegiatan</th>
                        <th>Status</th>
                        <th style="text-align:right">Tindakan</th>
                    </tr>
                </thead>
                <tbody x-ref="tbody">
                    @forelse ($daftarCapaian as $capaian)
                        <tr wire:key="capaian-{{ $capaian->id }}">
                            <td>{{ $capaian->masterIku->kode }} — {{ $capaian->masterIku->indikator }}</td>
                            <td>{{ $namaBulan[$capaian->periode->bulan - 1] }} {{ $capaian->periode->tahun }}</td>
                            <td class="muted">{{ $capaian->masterIku->tim }}</td>
                            <td>{{ $jumlahKegiatan->get($capaian->id, 0) }} kegiatan</td>
                            <td><x-rincian-status-kegiatan :rincian="$rincianStatusKegiatan->get($capaian->id, collect())" /></td>
                            <td><x-badge-status :status="$capaian->status" /></td>
                            <td style="text-align:right">
                                <a wire:navigate href="{{ route('verifikasi.show', $capaian) }}" class="btn btn-primary btn-sm">{{ $capaian->status === 'diajukan' ? 'Periksa →' : 'Lihat →' }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="color:var(--muted)">Tidak ada isian {{ $status === '' ? '' : 'berstatus '.ucfirst($status).' ' }}pada periode/kata kunci ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-table-pagination />
        </div>
    </div>
</div>
