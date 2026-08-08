<?php

namespace App\Services;

use App\Models\Capaian;
use App\Models\Lakin;
use App\Models\LakinBaris;

/**
 * Membentuk/menyusun-ulang baris LAKIN satu TAHUN dari data capaian yang sudah
 * tersimpan (dari isian Ketua Tim yang sudah diverifikasi Tim SAKIP, sama seperti
 * sumber data Bagian I notula) — satu baris per IKU yang punya capaian pada tahun itu.
 *
 * Target = Target PK (Perjanjian Kinerja, bersifat TAHUNAN) dari capaian mana pun
 * pada tahun itu yang sudah mengisinya. Realisasi = jumlah realisasi seluruh triwulan
 * tahun itu (capaian bersifat kumulatif triwulanan). Kedua asumsi ini adalah TITIK AWAL
 * yang masuk akal, bukan aturan baku — Tim SAKIP bisa menimpa angka apa pun secara
 * manual di LakinDetail sesudahnya.
 *
 * PENTING: memanggil bentuk() lagi akan MENIMPA baris yang otomatis dibuat sebelumnya
 * (dicocokkan lewat iku_id) dengan angka terbaru — sama seperti "Susun Ulang Otomatis"
 * pada notula. Baris CUSTOM (iku_id kosong, ditambah manual Tim SAKIP) tidak pernah disentuh.
 */
class LakinBuilderService
{
    public function bentuk(int $tahun): Lakin
    {
        $lakin = Lakin::firstOrCreate(['tahun' => $tahun]);

        $capaianPerIku = Capaian::with('masterIku')
            ->whereHas('periode', fn ($q) => $q->where('tahun', $tahun))
            ->get()
            ->groupBy('iku_id');

        $urutan = 0;

        foreach ($capaianPerIku as $ikuId => $daftarCapaian) {
            $iku = $daftarCapaian->first()->masterIku;
            $target = $daftarCapaian->pluck('target_pk')->filter()->last();
            $realisasi = $daftarCapaian->pluck('realisasi')->filter();
            $totalRealisasi = $realisasi->isEmpty() ? null : $realisasi->sum();
            $capaianPersen = ($target && (float) $target > 0 && $totalRealisasi !== null)
                ? round(((float) $totalRealisasi / (float) $target) * 100, 2)
                : null;

            LakinBaris::updateOrCreate(
                ['lakin_id' => $lakin->id, 'iku_id' => $ikuId],
                [
                    'sasaran' => $iku->sasaran,
                    'indikator' => $iku->indikator,
                    'target' => $target,
                    'realisasi' => $totalRealisasi,
                    'capaian_persen' => $capaianPersen,
                    'urutan' => $urutan++,
                ]
            );
        }

        return $lakin->fresh('baris');
    }
}
