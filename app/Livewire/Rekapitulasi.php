<?php

namespace App\Livewire;

use App\Models\Capaian;
use App\Models\Kegiatan;
use Livewire\Component;

/**
 * Rekapitulasi triwulanan OTOMATIS per IKU (RF-49, RNF-01, RNF-02) — dibentuk
 * langsung dari kegiatan+capaian yang sudah TERVERIFIKASI, tanpa ada langkah
 * penyalinan manual dari spreadsheet seperti alur kerja lama (SRS §2.1).
 */
class Rekapitulasi extends Component
{
    public int $tahun;

    public int $triwulan;

    public function mount(): void
    {
        $this->tahun = (int) now()->year;
        $this->triwulan = (int) ceil(((int) now()->month) / 3);
    }

    /**
     * Agregat 3 bulan dalam satu triwulan, per IKU:
     * - target_pk/target_tw: diisi berulang oleh Tim SAKIP di tiap kegiatan untuk
     *   IKU & triwulan yang sama (seharusnya konstan) — diambil nilai TERBESAR
     *   sebagai representasi tunggal, bukan dijumlahkan.
     * - realisasi: DIJUMLAHKAN antar kegiatan dalam triwulan (mis. akumulasi
     *   jumlah dokumen/publikasi yang terealisasi sepanjang triwulan).
     * - persentase: dihitung ULANG dari agregat di atas (realisasi ÷ target_tw),
     *   bukan sekadar rata-rata dari angka yang sudah tersimpan — inilah bagian
     *   "otomatis, tanpa penyalinan manual" pada RF-49.
     *
     * @return \Illuminate\Support\Collection<int, array{iku: \App\Models\MasterIku, jumlah_kegiatan: int, target_pk: float, target_tw: float, realisasi: float, persentase: float}>
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

        return view('livewire.rekapitulasi', [
            'rekap' => $rekap,
            'rataRataPersentase' => $rekap->isNotEmpty() ? round((float) $rekap->avg('persentase'), 2) : 0.0,
        ]);
    }
}
