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
     * @param  \Illuminate\Support\Collection  $rekap  Hasil dataRekap() — dipakai lagi di sini
     *                                                  supaya "rata-rata capaian" di kartu ringkasan
     *                                                  SELALU sama dengan rata-rata kolom Capaian %
     *                                                  (Triwulanan) di tabel Rekap per IKU (dulu
     *                                                  dihitung terpisah dari persentase_capaian
     *                                                  tersimpan per bulan, yang tidak kumulatif —
     *                                                  bisa beda angka dari tabel Rekap, membingungkan).
     * @return array{jumlah_iku_total: int, jumlah_iku_aktif_triwulan: int, jumlah_kegiatan: int, rata_rata_capaian: float, status_breakdown: \Illuminate\Support\Collection}
     */
    protected function ringkasan(\Illuminate\Support\Collection $rekap): array
    {
        // Satu query untuk seluruh baris kegiatan triwulan ini — jumlah, IKU aktif,
        // dan status breakdown semuanya diturunkan dari koleksi yang sama di PHP,
        // bukan beberapa query terpisah (DB remote, tiap query ~400ms).
        $kegiatanRows = $this->kegiatanTriwulanQuery()->get(['iku_id', 'periode_id', 'status_dokumen']);

        $jumlahKegiatan = $kegiatanRows->count();
        $jumlahIkuAktif = $kegiatanRows->pluck('iku_id')->unique()->count();
        $statusBreakdown = $kegiatanRows->countBy('status_dokumen');

        return [
            'jumlah_iku_total' => MasterIku::count(),
            'jumlah_iku_aktif_triwulan' => $jumlahIkuAktif,
            'jumlah_kegiatan' => $jumlahKegiatan,
            'rata_rata_capaian' => $this->rataRataPersentase($rekap),
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
     * Realisasi kumulatif year-to-date per IKU — dijumlahkan dari SELURUH periode
     * (bulan) berstatus terverifikasi/disetujui, dari triwulan I sampai triwulan
     * yang sedang dipilih, pada tahun yang sama (sesuai sheet resmi Tim SAKIP:
     * kolom "Realisasi Kumulatif" TW III sudah mencakup TW I+II+III, bukan cuma
     * realisasi TW III sendiri) — dipakai sebagai pembilang KEDUA jenis capaian
     * di dataRekap() ("Terhadap Target Triwulanan" MAUPUN "Terhadap Target
     * Setahun"), karena keduanya sama-sama membandingkan realisasi kumulatif
     * s.d. triwulan ini, cuma beda target pembaginya.
     *
     * @return \Illuminate\Support\Collection<int, float> iku_id => realisasi kumulatif
     */
    protected function realisasiKumulatifPerIku(): \Illuminate\Support\Collection
    {
        $kegiatanYtd = Kegiatan::whereHas('periode', fn ($q) => $q->where('tahun', $this->tahun)->where('triwulan', '<=', $this->triwulan))
            ->whereIn('status_dokumen', [Kegiatan::STATUS_DIVERIFIKASI, Kegiatan::STATUS_DISETUJUI])
            ->get(['id', 'iku_id', 'periode_id']);

        if ($kegiatanYtd->isEmpty()) {
            return collect();
        }

        $capaianMap = Capaian::whereIn('periode_id', $kegiatanYtd->pluck('periode_id')->unique())
            ->get(['id', 'iku_id', 'periode_id', 'realisasi'])
            ->keyBy(fn ($c) => $c->iku_id.'-'.$c->periode_id);

        return $kegiatanYtd
            ->groupBy('iku_id')
            ->map(fn ($kegiatanGroup) => (float) $kegiatanGroup
                ->map(fn ($k) => $capaianMap->get($k->iku_id.'-'.$k->periode_id))
                ->filter()
                ->unique(fn ($c) => $c->id)
                ->sum('realisasi'));
    }

    /**
     * Agregat 3 bulan dalam satu triwulan, per IKU (RF-49, RNF-01, RNF-02) — dibentuk
     * langsung dari kegiatan+capaian yang sudah TERVERIFIKASI, tanpa penyalinan manual:
     * - target_pk/target_tw: diisi berulang oleh Tim SAKIP di tiap kegiatan untuk
     *   IKU & triwulan yang sama (seharusnya konstan) — diambil nilai TERBESAR
     *   sebagai representasi tunggal, bukan dijumlahkan.
     * - realisasi: DIJUMLAHKAN antar kegiatan dalam triwulan (mis. akumulasi
     *   jumlah dokumen/publikasi yang terealisasi sepanjang triwulan).
     * - persentase (Terhadap Target Triwulanan) & persentase_setahun (Terhadap Target
     *   Setahun): KEDUANYA memakai realisasi KUMULATIF year-to-date yang sama sebagai
     *   pembilang (lihat realisasiKumulatifPerIku()) — BUKAN realisasi triwulan ini saja
     *   ($realisasi di bawah murni informasi "kontribusi triwulan ini") — cuma beda
     *   pembagi: target_tw (alokasi target kumulatif s.d. triwulan ini) untuk yang
     *   pertama, target_pk (target tahunan penuh) untuk yang kedua. Sesuai sheet resmi:
     *   "Alokasi Target" & "Realisasi" SAMA-SAMA kumulatif per TW, bukan nilai triwulan
     *   itu sendiri — capaian TW II dihitung dari realisasi(TW I+II) ÷ target(TW I+II).
     *
     * @return \Illuminate\Support\Collection<int, array{iku: MasterIku, jumlah_kegiatan: int, target_pk: float, target_tw: float, realisasi: float, persentase: ?float, realisasi_ytd: float, persentase_setahun: ?float}>
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

        $realisasiYtdPerIku = $this->realisasiKumulatifPerIku();

        return $kegiatanTerverifikasi
            ->groupBy('iku_id')
            ->map(function ($kegiatanGroup) use ($capaianMap, $realisasiYtdPerIku) {
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
                $realisasiYtd = $realisasiYtdPerIku->get($iku->id, 0.0);

                return [
                    'iku' => $iku,
                    'jumlah_kegiatan' => $kegiatanGroup->count(),
                    'target_pk' => $targetPk,
                    'target_tw' => $targetTw,
                    'realisasi' => $realisasi,
                    // Pembilang kedua kolom capaian di bawah SELALU $realisasiYtd
                    // (kumulatif s.d. triwulan ini), bukan $realisasi (triwulan ini saja).
                    'persentase' => Capaian::hitungPersentase($targetTw, $realisasiYtd),
                    'realisasi_ytd' => $realisasiYtd,
                    'persentase_setahun' => Capaian::hitungPersentase($targetPk, $realisasiYtd),
                ];
            })
            ->sortBy(fn ($row) => $row['iku']->kode)
            ->values();
    }

    /**
     * Rata-rata persentase, mengabaikan baris strip "-" (belum ada capaian) — bukan
     * memperlakukannya sebagai 0%, supaya IKU yang belum sempat direalisasikan tidak
     * menyeret turun rata-rata IKU lain yang sudah tercapai.
     */
    protected function rataRataPersentase(\Illuminate\Support\Collection $rekap): float
    {
        $persentaseTerisi = $rekap->pluck('persentase')->filter(fn ($v) => $v !== null);

        return $persentaseTerisi->isNotEmpty() ? round((float) $persentaseTerisi->avg(), 2) : 0.0;
    }

    public function render()
    {
        $rekap = $this->dataRekap();

        return view('livewire.dasbor-capaian', [
            'ringkasan' => $this->ringkasan($rekap),
            'progresTriwulan' => $this->progresPerTriwulan(),
            'rekap' => $rekap,
            'rataRataPersentase' => $this->rataRataPersentase($rekap),
        ]);
    }
}
