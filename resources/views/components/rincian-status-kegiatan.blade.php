{{--
    Rincian jumlah kegiatan per status_dokumen dalam satu Capaian (IKU+bulan) — mis.
    "3 Diverifikasi" + "2 Dikembalikan" — dipakai di tabel dasbor/daftar verifikasi
    supaya status besar (badge Capaian::status) tetap bisa ditelusuri sampai rincian
    per kegiatannya tanpa perlu membuka detail.

    Pemakaian: <x-rincian-status-kegiatan :rincian="App\Models\Kegiatan::rincianStatus($kegiatanIkuIni)" />
--}}
@props(['rincian'])

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
