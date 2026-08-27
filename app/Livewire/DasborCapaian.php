<?php

namespace App\Livewire;

use App\Models\Capaian;
use App\Models\CapaianTahunan;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\NilaiSakip;
use App\Services\CapaianCalculatorService;
use Livewire\Component;

/**
 * Dasbor status isian & capaian kinerja (RF-50) — kartu ringkasan, indikator progres
 * per triwulan, dan rekapitulasi otomatis per IKU (RF-49) dalam satu halaman (tadinya
 * dua tab terpisah "Dasbor Kinerja" & "Rekapitulasi" — digabung karena tujuan
 * keduanya sama: memantau capaian triwulan berjalan). Semua dihitung langsung dari
 * DB setiap render, tidak ada data yang di-cache/disimpan terpisah (RNF-01) — kecuali
 * Nilai SAKIP (App\Models\NilaiSakip) yang memang input tersimpan per tahun.
 *
 * Perhitungan berhenti sampai Capaian Kinerja (Rumus 2.0-2.4 Kertas Kerja Pengukuran
 * Kinerja Triwulanan) — Penilaian Kinerja Organisasi (PKO: normalisasi, koreksi
 * predikat, nilai akhir) TIDAK ADA di halaman ini, dihapus dari cakupan aplikasi.
 */
class DasborCapaian extends Component
{
    public int $tahun;

    public int $triwulan;

    /**
     * Nilai SAKIP tahun terpilih (dari Inspektorat, satu angka untuk SELURUH
     * organisasi) — form field untuk simpanNilaiSakip(). Dipakai HANYA untuk
     * menampilkan Predikat SAKIP (Rumus 2.1) sebagai info header, TIDAK dipakai
     * dalam perhitungan capaian apa pun.
     */
    public $nilaiSakipInput = null;

    public function mount(): void
    {
        $this->tahun = (int) now()->year;
        $this->triwulan = (int) ceil(((int) now()->month) / 3);
        $this->muatNilaiSakip();
    }

    protected function muatNilaiSakip(): void
    {
        $this->nilaiSakipInput = NilaiSakip::where('tahun', $this->tahun)->value('nilai');
    }

    public function updatedTahun(): void
    {
        $this->muatNilaiSakip();
    }

    public function simpanNilaiSakip(): void
    {
        $this->validate([
            'nilaiSakipInput' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [], ['nilaiSakipInput' => 'Nilai SAKIP']);

        NilaiSakip::updateOrCreate(['tahun' => $this->tahun], ['nilai' => $this->nilaiSakipInput]);

        session()->flash('status', 'Nilai SAKIP berhasil disimpan.');
    }

    /**
     * Rumus 2.1 (Predikat SAKIP) dari Nilai SAKIP tahun terpilih — info header saja,
     * TIDAK dipakai dalam perhitungan capaian apa pun.
     *
     * @return array{nilai_sakip: ?float, predikat_sakip: ?string}
     */
    protected function infoSakip(): array
    {
        $nilaiSakip = $this->nilaiSakipInput !== null ? (float) $this->nilaiSakipInput : null;

        return [
            'nilai_sakip' => $nilaiSakip,
            'predikat_sakip' => $nilaiSakip !== null ? Capaian::predikatSakip($nilaiSakip) : null,
        ];
    }

    /**
     * Daftar CapaianTahunan tahun terpilih, dipakai ulang oleh
     * capaianKinerjaPerTriwulan()/capaianSetahunPerTriwulan()/capaianPerSasaran()
     * supaya tidak query berulang.
     *
     * @return \Illuminate\Support\Collection<int, CapaianTahunan>
     */
    protected function capaianTahunanTahunIni(): \Illuminate\Support\Collection
    {
        return CapaianTahunan::with('masterIku')
            ->where('tahun', $this->tahun)
            ->get();
    }

    /**
     * Bagian 3 bullet 1 — rata-rata Capaian Terhadap Target Triwulanan (Rumus 2.3)
     * seluruh IKU pada KEEMPAT triwulan tahun terpilih sekaligus, supaya Tim SAKIP
     * bisa membandingkan tren antar triwulan dalam satu tabel tanpa berpindah
     * halaman — lihat kartu "Capaian Kinerja IKU per Triwulan" di view.
     *
     * @return array<int, float|string> triwulan (1-4) => rata-rata Capaian Kinerja IKU, atau "-" bila belum ada IKU yang bisa dinilai pada triwulan itu
     */
    protected function capaianKinerjaPerTriwulan(): array
    {
        $capaianTahunanIku = $this->capaianTahunanTahunIni();

        $hasil = [];

        foreach ([1, 2, 3, 4] as $tw) {
            $capaianList = $capaianTahunanIku
                ->map(fn (CapaianTahunan $ct) => $ct->capaianTriwulanan($tw) ?? CapaianCalculatorService::TIDAK_DINILAI)
                ->all();

            $hasil[$tw] = CapaianCalculatorService::rataRataCapaianTriwulanan($capaianList);
        }

        return $hasil;
    }

    /**
     * Bagian 3 bullet 2 — rata-rata Capaian Terhadap Target Setahun (Rumus 2.4)
     * seluruh IKU pada KEEMPAT triwulan tahun terpilih — paralel dengan
     * capaianKinerjaPerTriwulan() di atas, tapi memakai capaianSetahun()/
     * rataRataCapaianSetahun() (pembagi = jumlah TOTAL indikator, bukan jumlah
     * nilai valid — lihat CapaianCalculatorService::rataRataCapaianSetahun()).
     *
     * @return array<int, float|string>
     */
    protected function capaianSetahunPerTriwulan(): array
    {
        $capaianTahunanIku = $this->capaianTahunanTahunIni();

        $hasil = [];

        foreach ([1, 2, 3, 4] as $tw) {
            $capaianList = $capaianTahunanIku
                ->map(fn (CapaianTahunan $ct) => $ct->capaianSetahun($tw) ?? CapaianCalculatorService::TIDAK_DINILAI)
                ->all();

            $hasil[$tw] = CapaianCalculatorService::rataRataCapaianSetahun($capaianList);
        }

        return $hasil;
    }

    /**
     * Bagian 3 bullet 3 — rata-rata Capaian Terhadap Target Triwulanan (Rumus 2.3)
     * pada triwulan TERPILIH ($this->triwulan), dikelompokkan per MasterIku::sasaran.
     * IKU tanpa sasaran terisi dikelompokkan di bawah label "Tanpa Sasaran" supaya
     * tetap terlihat, bukan diam-diam hilang dari tabel.
     *
     * @return \Illuminate\Support\Collection<string, float|string> sasaran => rata-rata Capaian Kinerja IKU
     */
    protected function capaianPerSasaran(): \Illuminate\Support\Collection
    {
        $perSasaran = $this->capaianTahunanTahunIni()
            ->groupBy(fn (CapaianTahunan $ct) => $ct->masterIku->sasaran ?: 'Tanpa Sasaran');

        return $perSasaran->map(function ($group) {
            $capaianList = $group
                ->map(fn (CapaianTahunan $ct) => $ct->capaianTriwulanan($this->triwulan) ?? CapaianCalculatorService::TIDAK_DINILAI)
                ->all();

            return CapaianCalculatorService::subtotalPerSasaran($capaianList);
        })->sortKeys();
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
     * Target Tahunan + Alokasi/Realisasi Triwulanan per IKU aktif pada tahun terpilih
     * (App\Models\CapaianTahunan, satu baris per iku_id+tahun — lihat komentar di
     * sana) — dipetakan sekali di sini, bukan query per baris rekap. `with('masterIku')`
     * supaya alokasiKumulatif()/realisasiKumulatif() (yang cek MasterIku::pakaiRasio())
     * tidak N+1 saat dipanggil berulang di loop dataRekap().
     *
     * @return \Illuminate\Support\Collection<int, CapaianTahunan> iku_id => baris
     */
    protected function capaianTahunanPerIku(): \Illuminate\Support\Collection
    {
        return CapaianTahunan::with('masterIku')->where('tahun', $this->tahun)->get()->keyBy('iku_id');
    }

    /**
     * Agregat 3 bulan dalam satu triwulan, per IKU (RF-49, RNF-01, RNF-02) — daftar
     * IKU-nya (mana yang "aktif" triwulan ini) tetap ditentukan dari Kegiatan yang
     * sudah TERVERIFIKASI seperti sebelumnya, tapi angka Target Tahunan/Alokasi
     * Target/Realisasi sekarang diambil dari CapaianTahunan (diisi Tim SAKIP SEKALI
     * per tahun di halaman Verifikasi per-IKU), bukan lagi dari kolom target_pk/
     * target_tw/realisasi di `capaian` yang dulu diketik ulang tiap bulan.
     *
     * persentase (Terhadap Target Triwulanan) & persentase_setahun (Terhadap Target
     * Setahun) KEDUANYA memakai Realisasi Kumulatif s.d. triwulan ini sebagai
     * pembilang (CapaianTahunan::realisasiKumulatif()) — BUKAN realisasi triwulan
     * ini saja ($realisasi di bawah murni informasi "kontribusi triwulan ini") —
     * cuma beda pembagi: Alokasi Target Kumulatif untuk yang pertama, Target Tahunan
     * penuh untuk yang kedua. Rumus tetap lewat Capaian::hitungPersentase() (satu
     * sumber rumus resmi, tidak diduplikasi di CapaianTahunan maupun di sini).
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

        $capaianTahunanPerIku = $this->capaianTahunanPerIku();

        return $kegiatanTerverifikasi
            ->groupBy('iku_id')
            ->map(function ($kegiatanGroup) use ($capaianTahunanPerIku) {
                $iku = $kegiatanGroup->first()->masterIku;
                $capaianTahunan = $capaianTahunanPerIku->get($iku->id);

                $targetPk = $capaianTahunan?->targetTahunan() ?? 0.0;
                $targetTw = $capaianTahunan?->alokasiKumulatif($this->triwulan) ?? 0.0;
                $realisasi = (float) ($capaianTahunan?->{"realisasi_tw{$this->triwulan}"} ?? 0);
                $realisasiYtd = $capaianTahunan?->realisasiKumulatif($this->triwulan) ?? 0.0;

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
            'infoSakip' => $this->infoSakip(),
            'capaianKinerjaPerTriwulan' => $this->capaianKinerjaPerTriwulan(),
            'capaianSetahunPerTriwulan' => $this->capaianSetahunPerTriwulan(),
            'capaianPerSasaran' => $this->capaianPerSasaran(),
        ]);
    }
}
