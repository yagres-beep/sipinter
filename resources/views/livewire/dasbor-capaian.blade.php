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
    <div class="page-title">Dasbor Capaian Kinerja</div>
    <div class="page-sub">Status isian dan capaian kinerja secara menyeluruh, terbarui setiap saat (RF-50).</div>

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
        @foreach ($progresTriwulan as $tw => $data)
            <div style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:5px">
                    <span style="font-weight:600">Triwulan {{ ['I', 'II', 'III', 'IV'][$tw - 1] }}</span>
                    <span style="color:var(--muted)">{{ $data['selesai'] }}/{{ $data['total'] }} kegiatan ({{ $data['persen'] }}%)</span>
                </div>
                <div class="quota-bar">
                    <div class="quota-fill {{ $data['persen'] < 50 ? 'warn' : '' }}" style="width: {{ $data['persen'] }}%"></div>
                </div>
            </div>
        @endforeach
    </div>
</div>
