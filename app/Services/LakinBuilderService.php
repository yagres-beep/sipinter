<?php

namespace App\Services;

use App\Models\Capaian;
use App\Models\CapaianTahunan;
use App\Models\Lakin;
use App\Models\LakinBaris;
use Illuminate\Support\Collection;

/**
 * Menyiapkan pilihan IKU yang bisa dimasukkan ke LAKIN dari Target Tahunan +
 * Realisasi Triwulanan yang sudah diisi Tim SAKIP (App\Models\CapaianTahunan, satu
 * baris per iku_id+tahun), lalu menambahkan HANYA IKU yang dipilih Tim SAKIP secara
 * eksplisit — LAKIN tidak pernah terisi otomatis-semua, karena tiap instansi bisa
 * punya alasan berbeda memasukkan/tidak memasukkan suatu indikator.
 *
 * Target = Target Tahunan. Realisasi = Realisasi Kumulatif TW I s.d. TW IV (setahun
 * penuh). Kedua asumsi ini adalah TITIK AWAL yang masuk akal, bukan aturan baku —
 * Tim SAKIP bisa menimpa angka apa pun secara manual di LakinDetail sesudahnya.
 */
class LakinBuilderService
{
    /**
     * IKU yang punya data CapaianTahunan pada tahun ini, lengkap dengan angka
     * target/realisasi/capaian% hasil hitung — dipakai untuk checklist pemilihan di
     * LakinDetail. TIDAK menyimpan apa pun; hanya pratinjau sebelum Tim SAKIP memilih.
     *
     * @return Collection<int, object{iku: \App\Models\MasterIku, target: ?string, realisasi: ?float, capaian_persen: ?float, terverifikasi: bool}> dikunci pada iku_id.
     */
    public function ikuTersediaUntukTahun(int $tahun): Collection
    {
        return CapaianTahunan::with('masterIku')
            ->where('tahun', $tahun)
            ->get()
            ->map(function (CapaianTahunan $capaianTahunan) use ($tahun) {
                $target = $capaianTahunan->targetTahunan();
                $totalRealisasi = $capaianTahunan->realisasiKumulatif(4);
                $capaianPersen = $capaianTahunan->capaianSetahun(4);

                return (object) [
                    'iku' => $capaianTahunan->masterIku,
                    'target' => $target,
                    'realisasi' => $totalRealisasi,
                    'capaian_persen' => $capaianPersen,
                    'terverifikasi' => $this->sudahTerverifikasi($capaianTahunan->iku_id, $tahun),
                ];
            })
            ->sortBy(fn ($data) => $data->iku->kode)
            ->values()
            ->keyBy(fn ($data) => $data->iku->id);
    }

    /**
     * IKU dianggap "sudah diverifikasi Tim SAKIP" untuk tahun ini bila SELURUH baris
     * Capaian (per bulan) miliknya pada tahun tsb berstatus diverifikasi/disetujui,
     * dan minimal ada satu baris — dipakai LakinDetail supaya checklist "Tambah dari
     * Data Capaian" otomatis mencentang IKU yang sudah beres diverifikasi, sisanya
     * (masih diajukan/sedang ditangani/dikembalikan/atau belum ada isian sama sekali)
     * tetap harus dipilih manual.
     */
    private function sudahTerverifikasi(int $ikuId, int $tahun): bool
    {
        $statusList = Capaian::where('iku_id', $ikuId)
            ->whereHas('periode', fn ($q) => $q->where('tahun', $tahun))
            ->pluck('status');

        if ($statusList->isEmpty()) {
            return false;
        }

        return $statusList->every(fn ($status) => in_array($status, [
            Capaian::STATUS_DIVERIFIKASI,
            Capaian::STATUS_DISETUJUI,
        ], true));
    }

    /**
     * Tambah/perbarui baris LAKIN HANYA untuk IKU yang eksplisit dipilih Tim SAKIP
     * (lewat checklist) — IKU yang tidak dipilih tidak pernah ikut masuk.
     *
     * @param  list<int>  $ikuIds
     */
    public function tambahDariIku(Lakin $lakin, array $ikuIds): void
    {
        $tersedia = $this->ikuTersediaUntukTahun($lakin->tahun);
        $urutan = (int) $lakin->baris()->max('urutan');

        foreach ($ikuIds as $ikuId) {
            $data = $tersedia->get($ikuId);

            if (! $data) {
                continue;
            }

            $urutan++;

            LakinBaris::updateOrCreate(
                ['lakin_id' => $lakin->id, 'iku_id' => $ikuId],
                [
                    'sasaran' => $data->iku->sasaran,
                    'indikator' => $data->iku->indikator,
                    'target' => $data->target,
                    'realisasi' => $data->realisasi,
                    'capaian_persen' => $data->capaian_persen,
                    'urutan' => $urutan,
                ]
            );
        }
    }

    /**
     * Segarkan ANGKA baris yang SUDAH ADA di LAKIN ini (dicocokkan lewat iku_id) dari
     * data capaian terkini — TIDAK menambah baris baru (Tim SAKIP tetap yang memilih
     * lewat tambahDariIku()) dan TIDAK menyentuh baris custom (iku_id kosong).
     */
    public function segarkanBaris(Lakin $lakin): void
    {
        $tersedia = $this->ikuTersediaUntukTahun($lakin->tahun);

        foreach ($lakin->baris as $baris) {
            if (! $baris->iku_id) {
                continue;
            }

            $data = $tersedia->get($baris->iku_id);

            if (! $data) {
                continue;
            }

            $baris->update([
                'sasaran' => $data->iku->sasaran,
                'indikator' => $data->iku->indikator,
                'target' => $data->target,
                'realisasi' => $data->realisasi,
                'capaian_persen' => $data->capaian_persen,
            ]);
        }
    }
}
