<?php

namespace App\Support;

use App\Models\BagianKustom;

/**
 * Pemeriksaan kelengkapan konfigurasi satu Bagian Kustom, dan peta "di menu mana
 * peran X mengisi/membaca bagian ini". Dipakai bersama oleh halaman Pengelolaan
 * Bagian (indikator & daftar perbaikan) dan halaman Isian Bagian Kustom (penjelasan
 * mengapa sebuah bagian tidak tampil, plus tautan ke menu tempat bagian itu diisi).
 *
 * Murni membaca konfigurasi — tidak mengubah data apa pun.
 */
final class KelengkapanBagianKustom
{
    public const GALAT = 'galat';

    public const SARAN = 'saran';

    /**
     * Daftar masalah konfigurasi. "galat" = bagian tidak bisa dipakai sebagaimana
     * dimaksud; "saran" = bisa dipakai, tetapi kemungkinan belum sesuai harapan.
     * Setiap butir menyebut tab editor tempat memperbaikinya.
     *
     * @return array<int, array{tingkat: string, pesan: string, tab: string}>
     */
    public static function periksa(BagianKustom $bagian, ?int $tahun = null, ?int $triwulan = null): array
    {
        $tahun ??= (int) now()->year;
        $triwulan ??= BagianKustom::triwulanDariBulan((int) now()->month);

        $peran = array_values($bagian->peran ?? []);
        $pembaca = array_values($bagian->peran_pembaca ?? []);
        $penempatan = array_values($bagian->penempatan ?? []);
        $alur = $bagian->alurKerja();
        $pemeriksa = AlurBagianKustom::pakaiVerifikasi($alur) ? $alur['pemeriksa'] : [];
        $hasil = [];

        if ($peran === []) {
            $hasil[] = self::butir(self::GALAT, 'Belum ada peran pengisi, jadi bagian ini tidak bisa diisi siapa pun. Pilih minimal satu peran pengisi.', 'dasar');
        }

        if ($penempatan === []) {
            $hasil[] = self::butir(self::GALAT, 'Belum ditempatkan di menu mana pun, jadi bagian ini tidak tampil. Centang minimal satu menu.', 'dasar');
        }

        foreach ($peran as $p) {
            if ($penempatan !== [] && self::menuIsi($bagian, $p) === []) {
                $hasil[] = self::butir(self::GALAT, self::nama($p).' dipilih sebagai pengisi, tetapi tidak bisa membuka menu tempat bagian ini ditempatkan ('.$bagian->labelPenempatan().'). Tambahkan menu yang bisa dibuka '.self::nama($p).'.', 'dasar');
            }
        }

        foreach ($pembaca as $p) {
            if (! in_array($p, $peran, true) && $penempatan !== [] && self::menuBaca($bagian, $p) === []) {
                $hasil[] = self::butir(self::SARAN, self::nama($p).' dipilih sebagai pembaca, tetapi tidak bisa membuka menu tempat bagian ini ditempatkan.', 'dasar');
            }
        }

        $terlibat = array_values(array_unique([...$peran, ...$pembaca, ...$pemeriksa]));

        foreach ($penempatan as $menu) {
            if (! array_intersect($terlibat, MenuBagianKustom::peranDiizinkan($menu))) {
                $hasil[] = self::butir(self::SARAN, 'Menu '.MenuBagianKustom::label($menu).' tidak dibuka oleh peran pengisi, pembaca, maupun pemeriksa bagian ini, jadi bagian tidak tampil di sana. Hapus centang menu itu atau tambahkan peran yang sesuai.', 'dasar');
            }
        }

        $kolomAktif = $bagian->kolomBerlaku(null, null);

        if ($kolomAktif === []) {
            $hasil[] = self::butir(self::SARAN, 'Belum ada kolom isian aktif — formulir hanya berisi IKU dan "'.$bagian->labelUraianEfektif().'". Tambahkan kolom (langsung atau di subbagian) bila perlu isian lain.', 'isian');
        }

        $kunciTerpakai = array_unique(array_column($kolomAktif, 'subbagian'));

        foreach ($bagian->subbagianList() as $s) {
            if ($s['aktif'] && ! in_array($s['kunci'], $kunciTerpakai, true)) {
                $hasil[] = self::butir(self::SARAN, 'Subbagian "'.$s['nama'].'" belum punya kolom aktif, jadi tidak tampil di formulir.', 'isian');
            }
        }

        if (! $bagian->berlakuPada($tahun, $triwulan)) {
            $hasil[] = self::butir(self::SARAN, 'Tidak berlaku pada periode berjalan (TW '.['I', 'II', 'III', 'IV'][$triwulan - 1].' '.$tahun.'); pengaturan periode: '.$bagian->labelPeriodeBerlaku().'.', 'alur');
        }

        if ($bagian->masuk_notula && filled($bagian->format_notula)) {
            $asing = TemplateBagianKustom::variabelTidakDikenal((string) $bagian->format_notula, array_column($bagian->kolomList(), 'kunci'));

            if ($asing !== []) {
                $hasil[] = self::butir(self::GALAT, 'Format notula memakai variabel yang tidak ada: '.implode(', ', $asing).'.', 'notula');
            }
        }

        return $hasil;
    }

    /** Ada butir bertingkat galat. */
    public static function adaGalat(array $masalah): bool
    {
        return collect($masalah)->contains('tingkat', self::GALAT);
    }

    /**
     * Menu tempat $peran MENGISI bagian ini (ditempatkan di sana dan bisa dibuka peran itu).
     *
     * @return array<int, string>
     */
    public static function menuIsi(BagianKustom $bagian, string $peran): array
    {
        return array_values(array_filter($bagian->penempatan ?? [], fn ($m) => $bagian->tampilDi($peran, $m)));
    }

    /**
     * Menu tempat $peran hanya MEMBACA bagian ini.
     *
     * @return array<int, string>
     */
    public static function menuBaca(BagianKustom $bagian, string $peran): array
    {
        return array_values(array_filter($bagian->penempatan ?? [], fn ($m) => $bagian->bisaDibacaDi($peran, $m)));
    }

    /**
     * Menu tempat $peran MEMERIKSA isian bagian ini (alur verifikasi).
     *
     * @return array<int, string>
     */
    public static function menuPeriksa(BagianKustom $bagian, string $peran): array
    {
        $alur = $bagian->alurKerja();

        if (! AlurBagianKustom::pakaiVerifikasi($alur) || ! in_array($peran, $alur['pemeriksa'], true)) {
            return [];
        }

        return array_values(array_filter(
            $bagian->penempatan ?? [],
            fn ($m) => in_array($peran, MenuBagianKustom::peranDiizinkan($m), true)
        ));
    }

    /**
     * Tautan menu tempat $peran memakai bagian ini, selain $kecuali. Hanya menu yang
     * benar-benar bisa dibuka peran tersebut (tidak ada tautan yang berujung 403).
     *
     * @return array<int, array{menu: string, label: string, url: string, mode: string}>
     */
    public static function tautanMenu(BagianKustom $bagian, string $peran, ?string $kecuali = null): array
    {
        $hasil = [];
        $mode = [];

        foreach (self::menuIsi($bagian, $peran) as $m) {
            $mode[$m] = 'isi';
        }

        foreach (self::menuPeriksa($bagian, $peran) as $m) {
            $mode[$m] ??= 'periksa';
        }

        foreach (self::menuBaca($bagian, $peran) as $m) {
            $mode[$m] ??= 'baca';
        }

        foreach ($mode as $menu => $jenis) {
            $rute = MenuBagianKustom::katalog()[$menu]['route'] ?? null;

            if ($menu === $kecuali || $rute === null || ! \Illuminate\Support\Facades\Route::has($rute)) {
                continue;
            }

            $hasil[] = ['menu' => $menu, 'label' => MenuBagianKustom::label($menu), 'url' => route($rute), 'mode' => $jenis];
        }

        return $hasil;
    }

    private static function nama(string $peran): string
    {
        return BagianKustom::NAMA_PERAN[$peran] ?? $peran;
    }

    /** @return array{tingkat: string, pesan: string, tab: string} */
    private static function butir(string $tingkat, string $pesan, string $tab): array
    {
        return ['tingkat' => $tingkat, 'pesan' => $pesan, 'tab' => $tab];
    }
}
