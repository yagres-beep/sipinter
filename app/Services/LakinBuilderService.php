<?php

namespace App\Services;

use App\Models\Capaian;
use App\Models\Lakin;
use App\Models\LakinBaris;
use Illuminate\Support\Collection;

/**
 * Menyiapkan pilihan IKU yang bisa dimasukkan ke LAKIN dari data capaian yang sudah
 * tersimpan (dari isian Ketua Tim yang sudah diverifikasi Tim SAKIP, sama seperti
 * sumber data Bagian I notula), lalu menambahkan HANYA IKU yang dipilih Tim SAKIP
 * secara eksplisit — LAKIN tidak pernah terisi otomatis-semua, karena tiap instansi
 * bisa punya alasan berbeda memasukkan/tidak memasukkan suatu indikator.
 *
 * Target = Target PK (Perjanjian Kinerja, bersifat TAHUNAN) dari capaian mana pun
 * pada tahun itu yang sudah mengisinya. Realisasi = jumlah realisasi seluruh triwulan
 * tahun itu (capaian bersifat kumulatif triwulanan). Kedua asumsi ini adalah TITIK AWAL
 * yang masuk akal, bukan aturan baku — Tim SAKIP bisa menimpa angka apa pun secara
 * manual di LakinDetail sesudahnya.
 */
class LakinBuilderService
{
    /**
     * IKU yang punya data capaian pada tahun ini, lengkap dengan angka
     * target/realisasi/capaian% hasil hitung — dipakai untuk checklist pemilihan di
     * LakinDetail. TIDAK menyimpan apa pun; hanya pratinjau sebelum Tim SAKIP memilih.
     *
     * @return Collection<int, object{iku: \App\Models\MasterIku, target: ?string, realisasi: ?float, capaian_persen: ?float}> dikunci pada iku_id.
     */
    public function ikuTersediaUntukTahun(int $tahun): Collection
    {
        return Capaian::with('masterIku')
            ->whereHas('periode', fn ($q) => $q->where('tahun', $tahun))
            ->get()
            ->groupBy('iku_id')
            ->map(function ($daftarCapaian) {
                $iku = $daftarCapaian->first()->masterIku;
                $target = $daftarCapaian->pluck('target_pk')->filter()->last();
                $realisasi = $daftarCapaian->pluck('realisasi')->filter();
                $totalRealisasi = $realisasi->isEmpty() ? null : $realisasi->sum();
                $capaianPersen = ($target && (float) $target > 0 && $totalRealisasi !== null)
                    ? round(((float) $totalRealisasi / (float) $target) * 100, 2)
                    : null;

                return (object) [
                    'iku' => $iku,
                    'target' => $target,
                    'realisasi' => $totalRealisasi,
                    'capaian_persen' => $capaianPersen,
                ];
            })
            ->sortBy(fn ($data) => $data->iku->kode)
            ->values()
            ->keyBy(fn ($data) => $data->iku->id);
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
