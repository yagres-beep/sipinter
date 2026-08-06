<?php

namespace App\Livewire;

use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use Livewire\Component;

/**
 * Dasbor status isian & capaian kinerja (RF-50) — kartu ringkasan dan indikator
 * progres per triwulan, terbarui otomatis setiap kali capaian dicatat/diverifikasi
 * (tidak ada data yang di-cache/disimpan terpisah, semua dihitung langsung dari DB
 * setiap render, RNF-01).
 */
class DasborCapaian extends Component
{
    public int $tahun;

    public int $triwulan;

    public function mount(): void
    {
        $this->tahun = (int) now()->year;
        $this->triwulan = (int) ceil(((int) now()->month) / 3);
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
        // Satu query untuk kegiatan seluruh tahun (+1 eager-load periode), dikelompokkan
        // per triwulan di PHP — bukan 2 query x 4 triwulan (8 query terpisah).
        $kegiatanPerTriwulan = Kegiatan::with('periode')
            ->whereHas('periode', fn ($q) => $q->where('tahun', $this->tahun))
            ->get(['id', 'periode_id', 'status_dokumen'])
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
            ];
        }

        return $hasil;
    }

    public function render()
    {
        return view('livewire.dasbor-capaian', [
            'ringkasan' => $this->ringkasan(),
            'progresTriwulan' => $this->progresPerTriwulan(),
        ]);
    }
}
