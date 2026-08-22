<div>
    <div class="page-head">
        <div class="page-title">Notula</div>
        <div class="page-sub">Pantau status notula tiap triwulan dan buka versi final setelah disetujui Kepala.</div>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr><th>Periode</th><th>Ringkasan</th><th>Status</th><th style="text-align:right">Aksi</th></tr>
            </thead>
            <tbody>
                @forelse ($daftarNotula as $notula)
                    <tr wire:key="notula-{{ $notula->id }}">
                        <td><b>Triwulan {{ ['I', 'II', 'III', 'IV'][$notula->periode->triwulan - 1] }} {{ $notula->periode->tahun }}</b></td>
                        <td>Notula capaian kinerja SAKIP</td>
                        <td><x-badge-status :status="$notula->status" /></td>
                        <td style="text-align:right">
                            @if ($notula->status === \App\Models\Notula::STATUS_DISETUJUI && $notula->pdf_final)
                                <a href="{{ route('notula.unduh-final', $notula) }}" class="btn btn-primary btn-sm">Buka →</a>
                            @else
                                <span class="btn btn-ghost btn-sm" style="opacity:.5;cursor:not-allowed" title="Belum disetujui Kepala">Belum tersedia</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" style="color:var(--muted)">Belum ada notula yang disusun.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
