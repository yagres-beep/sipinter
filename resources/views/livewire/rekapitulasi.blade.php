<div>
    <div class="page-title">Rekapitulasi Triwulanan</div>
    <div class="page-sub">Dibentuk otomatis dari capaian bulanan yang sudah terverifikasi — tanpa penyalinan manual.</div>

    <x-filter-periode :tahun="$tahun" :triwulan="$triwulan" mode="triwulanan" />

    <div class="card">
        <div class="card-h">
            📊 Rekap per IKU
            <span class="badge b-verif">rata-rata {{ $rataRataPersentase }}%</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Indikator</th>
                    <th style="text-align:center">Kegiatan</th>
                    <th style="text-align:right">Target PK</th>
                    <th style="text-align:right">Target TW</th>
                    <th style="text-align:right">Realisasi</th>
                    <th style="text-align:right">Capaian %</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rekap as $baris)
                    <tr wire:key="rekap-{{ $baris['iku']->id }}">
                        <td>{{ $baris['iku']->kode }}</td>
                        <td>{{ $baris['iku']->indikator }}</td>
                        <td style="text-align:center">{{ $baris['jumlah_kegiatan'] }}</td>
                        <td style="text-align:right">{{ $baris['target_pk'] }}</td>
                        <td style="text-align:right">{{ $baris['target_tw'] }}</td>
                        <td style="text-align:right">{{ $baris['realisasi'] }}</td>
                        <td style="text-align:right">
                            <span class="badge {{ $baris['persentase'] >= 90 ? 'b-approve' : ($baris['persentase'] >= 70 ? 'b-tunggu' : 'b-tolak') }}">
                                {{ $baris['persentase'] }}%
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="color:var(--muted)">Belum ada capaian terverifikasi pada periode ini.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
