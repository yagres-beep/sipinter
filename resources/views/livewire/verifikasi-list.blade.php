@php
    $namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
@endphp

<div>
    <div class="page-title">Verifikasi Bukti &amp; Angka Capaian</div>
    <div class="page-sub">Daftar isian berstatus <span class="badge b-ajukan">diajukan</span> menunggu verifikasi.</div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <x-filter-periode :tahun="$tahun" :triwulan="$triwulan" :bulan="$bulan" mode="lengkap" />

    <div class="info teal">✅ Isi analisis capaian &amp; angka, verifikasi tiap berkas, beri catatan bila tidak sesuai.</div>

    <div class="card">
        <div class="toolbar">
            <div class="search-box">
                🔍
                <input type="text" wire:model.live.debounce.300ms="cari" placeholder="Cari kode, indikator, atau tim…">
            </div>
            <button type="button" class="btn btn-ghost btn-sm" wire:click="resetFilter">↺ Reset</button>
        </div>

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
                    <th>Status</th>
                    <th style="text-align:right">Tindakan</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($daftarCapaian as $capaian)
                    <tr wire:key="capaian-{{ $capaian->id }}">
                        <td>{{ $capaian->masterIku->kode }} — {{ $capaian->masterIku->indikator }}</td>
                        <td>{{ $namaBulan[$capaian->periode->bulan - 1] }} {{ $capaian->periode->tahun }}</td>
                        <td class="muted">{{ $capaian->masterIku->tim }}</td>
                        <td>{{ $jumlahKegiatan->get($capaian->id, 0) }} kegiatan</td>
                        <td><x-badge-status status="diajukan" /></td>
                        <td style="text-align:right">
                            <a href="{{ route('verifikasi.show', $capaian) }}" class="btn btn-primary btn-sm">Periksa →</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="color:var(--muted)">Tidak ada isian yang menunggu verifikasi pada periode/kata kunci ini.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
