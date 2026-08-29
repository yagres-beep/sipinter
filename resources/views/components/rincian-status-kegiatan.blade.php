{{--
    Rincian jumlah kegiatan per status_dokumen dalam satu Capaian (IKU+bulan) — mis.
    "3 Diverifikasi" + "2 Dikembalikan" — dipakai di tabel dasbor/daftar verifikasi
    supaya status besar (badge Capaian::status) tetap bisa ditelusuri sampai rincian
    per kegiatannya tanpa perlu membuka detail.

    :rincianRtl (opsional) menambahkan rincian bukti evaluasi RTL (status_verifikasi)
    di baris kedua, diberi prefiks "RTL" — supaya Capaian yang "Dikembalikan" KARENA
    realisasi RTL ditolak (bukan karena bukti Kegiatan) tetap kelihatan penyebabnya
    di sini, bukan cuma di badge status besar (lihat RtlEvaluasi::rincianStatusVerifikasi()).

    Pemakaian: <x-rincian-status-kegiatan
        :rincian="App\Models\Kegiatan::rincianStatus($kegiatanIkuIni)"
        :rincianRtl="App\Models\RtlEvaluasi::rincianStatusVerifikasi($rtlIkuIni)" />
--}}
@props(['rincian', 'rincianRtl' => null])

<div style="display:flex;flex-direction:column;gap:4px">
    <div style="display:flex;flex-wrap:wrap;gap:4px">
        @forelse ($rincian as $status => $jumlah)
            <x-badge-status :status="$status" :label="$jumlah.' '.match($status) {
                'draft' => 'Draf',
                'diajukan' => 'Diajukan',
                'diverifikasi' => 'Diverifikasi',
                'dikembalikan' => 'Dikembalikan',
                'disetujui' => 'Disetujui',
                default => ucfirst($status),
            }" style="font-size:10px;padding:2px 7px" />
        @empty
            <span class="muted">—</span>
        @endforelse
    </div>

    @if ($rincianRtl && $rincianRtl->isNotEmpty())
        <div style="display:flex;flex-wrap:wrap;gap:4px">
            @foreach ($rincianRtl as $status => $jumlah)
                <x-badge-status :status="$status" :label="'RTL '.$jumlah.' '.match($status) {
                    'menunggu' => 'Menunggu',
                    'terverifikasi' => 'Sesuai',
                    'ditolak' => 'Tidak Sesuai',
                    default => ucfirst($status),
                }" style="font-size:10px;padding:2px 7px" />
            @endforeach
        </div>
    @endif
</div>
