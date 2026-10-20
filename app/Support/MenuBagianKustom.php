<?php

namespace App\Support;

use App\Models\BagianKustom;

/**
 * Katalog menu tempat bagian kustom bisa ditempatkan. Setiap menu hanya bisa dibuka
 * peran tertentu (sesuai gerbang rute), jadi pilihan di Data Master otomatis dibatasi.
 *
 * - "pengisian" memakai form Isian Kegiatan yang sudah ada (diisi per IKU & kegiatan).
 * - Menu lain menampilkan bagian kustom di bagian paling bawah halaman, lewat
 *   komponen IsianBagianKustom (diisi per IKU & bulan).
 */
final class MenuBagianKustom
{
    public const PENGISIAN = 'pengisian';

    /**
     * @return array<string, array{label: string, route: string, peran: array<int, string>}>
     */
    public static function katalog(): array
    {
        $ketua = BagianKustom::PERAN_KETUA_TIM;
        $sakip = BagianKustom::PERAN_TIM_SAKIP;
        $kepala = BagianKustom::PERAN_KEPALA;

        return [
            'dasbor' => ['label' => 'Dasbor', 'route' => 'dashboard', 'peran' => [$ketua, $sakip, $kepala]],
            'pengisian' => ['label' => 'Isian Kegiatan', 'route' => 'pengisian.index', 'peran' => [$ketua]],
            'rtl_evaluasi' => ['label' => 'RTL & Evaluasi', 'route' => 'rtl-evaluasi.index', 'peran' => [$ketua]],
            'notula_riwayat' => ['label' => 'Notula (riwayat)', 'route' => 'notula-riwayat.index', 'peran' => [$ketua, $kepala]],
            'verifikasi' => ['label' => 'Verifikasi & Notula', 'route' => 'verifikasi.index', 'peran' => [$sakip]],
            'notula' => ['label' => 'Notula (kompilasi)', 'route' => 'notula.index', 'peran' => [$sakip]],
            'master_iku' => ['label' => 'Data Master & Konfigurasi', 'route' => 'master-iku.index', 'peran' => [$sakip]],
            'persetujuan' => ['label' => 'Persetujuan', 'route' => 'persetujuan.index', 'peran' => [$kepala]],
            'dasbor_kinerja' => ['label' => 'Dasbor Kinerja', 'route' => 'dasbor-kinerja.index', 'peran' => [$sakip, $kepala]],
            'lakin' => ['label' => 'Rekap Kinerja Tahunan', 'route' => 'lakin.index', 'peran' => [$ketua, $sakip, $kepala]],
            'isian_bagian_kustom' => ['label' => 'Isian Bagian Kustom', 'route' => 'bagian-kustom.index', 'peran' => [$sakip, $kepala]],
        ];
    }

    public static function label(string $menu): string
    {
        return self::katalog()[$menu]['label'] ?? $menu;
    }

    /**
     * Peran yang boleh membuka menu ini.
     *
     * @return array<int, string>
     */
    public static function peranDiizinkan(string $menu): array
    {
        return self::katalog()[$menu]['peran'] ?? [];
    }

    /**
     * Menu yang boleh dibuka satu peran.
     *
     * @return array<int, string>
     */
    public static function untukPeran(string $peran): array
    {
        return array_keys(array_filter(self::katalog(), fn ($m) => in_array($peran, $m['peran'], true)));
    }

    /**
     * Kunci menu dari nama rute yang sedang dibuka (null bila bukan menu katalog).
     */
    public static function dariRoute(?string $namaRute): ?string
    {
        if ($namaRute === null) {
            return null;
        }

        foreach (self::katalog() as $kunci => $menu) {
            if ($menu['route'] === $namaRute) {
                return $kunci;
            }
        }

        return null;
    }
}
