<?php

namespace App\Livewire;

use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use Livewire\Component;

/**
 * Dasbor status isian & capaian kinerja (RF-50) — kartu ringkasan, indikator progres
 * per triwulan, dan rekapitulasi otomatis per IKU (RF-49) dalam satu halaman (tadinya
 * dua tab terpisah "Dasbor Kinerja" & "Rekapitulasi" — digabung karena tujuan keduanya
 * sama: memantau capaian triwulan berjalan). Semua dihitung langsung dari DB setiap
 * render, tidak ada data yang di-cache/disimpan terpisah (RNF-01).
 */
class DasborCapaian extends Component
{
    public int $tahun;

    public int $triwulan;

    /** Triwulan yang sedang dibuka detailnya (klik pada bar progres) — null = tertutup. */
    public ?int $detailTriwulan = null;

    public function mount(): void
    {
        $this->tahun = (int) now()->year;
        $this->triwulan = (int) ceil(((int) now()->month) / 3);
    }

    public function lihatDetailTriwulan(int $triwulan): void
    {
        $this->detailTriwulan = $this->detailTriwulan === $triwulan ? null : $triwulan;
    }

    protected function kegiatanTriwulanQuery()
    {
        return Kegiatan::whereHas('periode', fn ($q) => $q->where('tahun', $this->tahun)->where('triwulan', $this->triwulan));
    }

    /**
     * @return array{jumlah_iku_total: int, jumlah_iku_aktif_triwulan: int, jumlah_kegiatan: int, rata_rata_capaian: float, status_breakdown: \Illuminate\Support\Collection}
     */
    protected function ringkasan(): array
    {
        // Satu query untuk seluruh baris kegiatan triwulan ini — jumlah, IKU aktif,
        // dan status breakdown semuanya diturunkan dari koleksi yang sama di PHP,
        // bukan 4 query terpisah (DB remote, tiap query ~400ms).
        $kegiatanRows = $this->kegiatanTriwulanQuery()->get(['iku_id', 'periode_id', 'status_dokumen']);

        $jumlahKegiatan = $kegiatanRows->count();
        $jumlahIkuAktif = $kegiatanRows->pluck('iku_id')->unique()->count();

        // Angka capaian melekat pada (iku_id, periode_id), bukan per kegiatan (RF-38) —
        // diambil dari Capaian (via pasangan iku+periode yang sudah diverifikasi/disetujui),
        // supaya tidak terhitung berulang saat satu IKU punya banyak kegiatan pendukung.
        $pasanganTerverifikasi = $kegiatanRows
            ->whereIn('status_dokumen', [Kegiatan::STATUS_DIVERIFIKASI, Kegiatan::STATUS_DISETUJUI])
            ->unique(fn ($k) => $k->iku_id.'-'.$k->periode_id);

        $capaianTerkait = Capaian::whereIn('periode_id', $pasanganTerverifikasi->pluck('periode_id')->unique())
            ->get(['iku_id', 'periode_id', 'persentase_capaian'])
            ->keyBy(fn ($c) => $c->iku_id.'-'.$c->periode_id);

        $rataRataCapaian = $pasanganTerverifikasi
            ->map(fn ($pasangan) => $capaianTerkait->get($pasangan->iku_id.'-'.$pasangan->periode_id)?->persentase_capaian)
            ->filter()
            ->map(fn ($v) => (float) $v);

        $statusBreakdown = $kegiatanRows->countBy('status_dokumen');

        return [
            'jumlah_iku_total' => MasterIku::count(),
            'jumlah_iku_aktif_triwulan' => $jumlahIkuAktif,
            'jumlah_kegiatan' => $jumlahKegiatan,
            'rata_rata_capaian' => $rataRataCapaian->isNotEmpty() ? round((float) $rataRataCapaian->avg(), 2) : 0.0,
            'status_breakdown' => $statusBreakdown,
        ];
    }

    /**
     * Progres keempat triwulan pada tahun terpilih: persentase kegiatan yang sudah
     * diverifikasi/disetujui dari total kegiatan tercatat pada triwulan tsb.
     *
     * @return array<int, array{total: int, selesai: int, persen: float}>
     */
    protected function progresPerTriwulan(): array
    {
        // Satu query untuk kegiatan seluruh tahun (+1 eager-load periode & IKU), dikelompokkan
        // per triwulan di PHP — bukan 2 query x 4 triwulan (8 query terpisah).
        $kegiatanPerTriwulan = Kegiatan::with(['periode', 'masterIku'])
            ->whereHas('periode', fn ($q) => $q->where('tahun', $this->tahun))
            ->get(['id', 'iku_id', 'periode_id', 'uraian_kegiatan', 'status_dokumen'])
            ->groupBy(fn ($k) => $k->periode->triwulan);

        $hasil = [];

        foreach ([1, 2, 3, 4] as $tw) {
            $kegiatanTw = $kegiatanPerTriwulan->get($tw, collect());
            $total = $kegiatanTw->count();
            $selesai = $kegiatanTw->whereIn('status_dokumen', [Kegiatan::STATUS_DIVERIFIKASI, Kegiatan::STATUS_DISETUJUI])->count();

            $hasil[$tw] = [
                'total' => $total,
                'selesai' => $selesai,
                'persen' => $total > 0 ? round(($selesai / $total) * 100, 1) : 0.0,
                // Dipakai saat bar-nya diklik (lihatDetailTriwulan) — daftar kegiatan
                // triwulan ini, supaya jelas mana yang sudah/belum tanpa query ulang.
                'kegiatan' => $kegiatanTw->sortBy(fn ($k) => $k->masterIku?->kode)->values(),
            ];
        }

        return $hasil;
    }

    /**
     * Agregat 3 bulan dalam satu triwulan, per IKU (RF-49, RNF-01, RNF-02) — dibentuk
     * langsung dari kegiatan+capaian yang sudah TERVERIFIKASI, tanpa penyalinan manual:
     * - target_pk/target_tw: diisi berulang oleh Tim SAKIP di tiap kegiatan untuk
     *   IKU & triwulan yang sama (seharusnya konstan) — diambil nilai TERBESAR
     *   sebagai representasi tunggal, bukan dijumlahkan.
     * - realisasi: DIJUMLAHKAN antar kegiatan dalam triwulan (mis. akumulasi
     *   jumlah dokumen/publikasi yang terealisasi sepanjang triwulan).
     * - persentase: dihitung ULANG dari agregat di atas (realisasi ÷ target_tw),
     *   bukan sekadar rata-rata dari angka yang sudah tersimpan.
     *
     * @return \Illuminate\Support\Collection<int, array{iku: MasterIku, jumlah_kegiatan: int, target_pk: float, target_tw: float, realisasi: float, persentase: float}>
     */
    protected function dataRekap()
    {
        $kegiatanTerverifikasi = Kegiatan::with('masterIku')
            ->whereHas('periode', fn ($q) => $q->where('tahun', $this->tahun)->where('triwulan', $this->triwulan))
            ->whereIn('status_dokumen', [Kegiatan::STATUS_DIVERIFIKASI, Kegiatan::STATUS_DISETUJUI])
            ->get();

        if ($kegiatanTerverifikasi->isEmpty()) {
            return collect();
        }

        // Angka capaian melekat pada (iku_id, periode_id), bukan per kegiatan (RF-38) —
        // dipetakan sekali di sini supaya tidak dihitung berulang kalau satu IKU pada
        // bulan yang sama punya banyak kegiatan pendukung.
        $capaianMap = Capaian::whereIn('periode_id', $kegiatanTerverifikasi->pluck('periode_id')->unique())
            ->get()
            ->keyBy(fn ($c) => $c->iku_id.'-'.$c->periode_id);

        return $kegiatanTerverifikasi
            ->groupBy('iku_id')
            ->map(function ($kegiatanGroup) use ($capaianMap) {
                $iku = $kegiatanGroup->first()->masterIku;

                // Satu Capaian per bulan (periode_id) dalam triwulan ini — di-dedupe
                // supaya realisasi antarbulan dijumlahkan sekali, bukan sekali per kegiatan.
                $capaianUnik = $kegiatanGroup
                    ->map(fn ($k) => $capaianMap->get($k->iku_id.'-'.$k->periode_id))
                    ->filter()
                    ->unique(fn ($c) => $c->id);

                $targetPk = (float) $capaianUnik->max('target_pk');
                $targetTw = (float) $capaianUnik->max('target_tw');
                $realisasi = (float) $capaianUnik->sum('realisasi');
                $persentase = $targetTw > 0 ? round(($realisasi / $targetTw) * 100, 2) : 0.0;

                return [
                    'iku' => $iku,
                    'jumlah_kegiatan' => $kegiatanGroup->count(),
                    'target_pk' => $targetPk,
                    'target_tw' => $targetTw,
                    'realisasi' => $realisasi,
                    'persentase' => $persentase,
                ];
            })
            ->sortBy(fn ($row) => $row['iku']->kode)
            ->values();
    }

    public function render()
    {
        $rekap = $this->dataRekap();

        return view('livewire.dasbor-capaian', [
            'ringkasan' => $this->ringkasan(),
            'progresTriwulan' => $this->progresPerTriwulan(),
            'rekap' => $rekap,
            'rataRataPersentase' => $rekap->isNotEmpty() ? round((float) $rekap->avg('persentase'), 2) : 0.0,
        ]);
    }
}
