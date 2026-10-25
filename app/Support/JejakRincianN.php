<?php

namespace App\Support;

use App\Models\LogAktivitas;
use App\Models\MasterIku;
use App\Models\RincianN;

/**
 * Jejak audit perubahan angka N dan rincian n/N ke log_aktivitas -- dipakai
 * App\Livewire\TargetTahunan dan App\Livewire\VerifikasiCapaian supaya formatnya
 * sama. Dipanggil di dalam transaksi simpan, jadi ikut batal bila simpan batal
 * (lihat LogAktivitas::catat()).
 */
class JejakRincianN
{
    private const ROMAWI = [1 => 'I', 'II', 'III', 'IV'];

    public static function nDiubah(MasterIku $iku, int $tahun, ?float $lama, ?float $baru, string $menu): void
    {
        LogAktivitas::catat(
            'target_tahunan.n_diubah',
            LogAktivitas::pelaku().' mengubah angka N '.LogAktivitas::labelIku($iku)." tahun {$tahun} dari "
                .self::angka($lama).' menjadi '.self::angka($baru).'. Nilai dasar n ikut disamakan; realisasi n tidak berubah.',
            [
                'objek_tipe' => LogAktivitas::OBJEK_CAPAIAN_TAHUNAN,
                'iku_id' => $iku->id,
                'menu' => $menu,
                'perubahan' => ['N' => ['sebelum' => $lama, 'sesudah' => $baru]],
            ],
        );
    }

    /**
     * @param  array<string, array{sebelum: mixed, sesudah: mixed}>|array{sebelum: array, sesudah: array}  $perubahan
     */
    public static function rincian(string $aksi, RincianN $n, string $menu, array $perubahan = [], ?int $periodeId = null): void
    {
        $kata = [
            'rincian_n.dibuat' => 'menambahkan',
            'rincian_n.diubah' => 'mengubah uraian',
            'rincian_n.dilepas' => 'melepas dari daftar target (realisasi tetap tercatat)',
            'rincian_n.dihapus' => 'menghapus',
            'rincian_n.realisasi_diubah' => 'mengubah status realisasi',
        ][$aksi] ?? $aksi;

        LogAktivitas::catat(
            $aksi,
            LogAktivitas::pelaku()." {$kata} ".self::jenis($n).' "'.$n->uraian.'" '.LogAktivitas::labelIku($n->masterIku)." tahun {$n->tahun}.",
            [
                'objek_tipe' => LogAktivitas::OBJEK_RINCIAN_N,
                'objek_id' => $n->id,
                'iku_id' => $n->iku_id,
                'periode_id' => $periodeId,
                'menu' => $menu,
                'perubahan' => $perubahan ?: null,
            ],
        );
    }

    /** Status realisasi yang mudah dibaca: "TW III" atau "belum terealisasi". */
    public static function statusRealisasi(?int $tw): string
    {
        return $tw === null ? 'belum terealisasi' : 'TW '.(self::ROMAWI[$tw] ?? $tw);
    }

    private static function jenis(RincianN $n): string
    {
        if ($n->isTambahan()) {
            return 'rincian tambahan n (berlaku mulai TW '.(self::ROMAWI[$n->triwulan_mulai_berlaku] ?? $n->triwulan_mulai_berlaku).')';
        }

        return $n->isDilepas() ? 'rincian target yang dilepas' : 'rincian target';
    }

    private static function angka(?float $nilai): string
    {
        return $nilai === null ? 'kosong' : rtrim(rtrim(number_format($nilai, 2, ',', '.'), '0'), ',');
    }
}
