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

    public const INFO = 'info';

    public const LABEL_TINGKAT = [
        self::GALAT => 'Kesalahan — perlu diperbaiki',
        self::SARAN => 'Peringatan — bisa dipakai, periksa kembali',
        self::INFO => 'Informasi',
    ];

    /**
     * Daftar masalah konfigurasi. "galat" = bagian tidak bisa dipakai sebagaimana
     * dimaksud; "saran" = bisa dipakai, tetapi kemungkinan belum sesuai harapan;
     * "info" = keterangan/rekomendasi, bukan masalah.
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

        return [...$hasil, ...self::periksaStruktur($bagian), ...self::periksaAlur($bagian, $penempatan)];
    }

    /**
     * Struktur formulir: kode kolom unik, syarat tampil & rumus menunjuk kolom yang ada
     * dan aktif, teks informasi berisi, sumber referensi punya data.
     *
     * @return array<int, array{tingkat: string, pesan: string, tab: string}>
     */
    private static function periksaStruktur(BagianKustom $bagian): array
    {
        $hasil = [];
        $semua = $bagian->kolomList();
        $peta = collect($semua)->keyBy('kunci');

        foreach (collect($semua)->countBy('kunci')->filter(fn ($n) => $n > 1)->keys() as $kunci) {
            $hasil[] = self::butir(self::GALAT, 'Kode variabel kolom "'.$kunci.'" dipakai lebih dari satu kolom.', 'isian');
        }

        $diperiksa = [...array_map(fn ($k) => ['nama' => 'Kolom "'.$k['label'].'"', 'syarat' => $k['tampil_jika'] ?? null, 'aktif' => $k['aktif']], $semua),
            ...array_map(fn ($s) => ['nama' => 'Subbagian "'.$s['nama'].'"', 'syarat' => $s['tampil_jika'] ?? null, 'aktif' => $s['aktif']], $bagian->subbagianList())];

        foreach ($diperiksa as $d) {
            if (! $d['aktif'] || $d['syarat'] === null) {
                continue;
            }

            $acuan = $peta->get($d['syarat']['kunci']);

            if ($acuan === null) {
                $hasil[] = self::butir(self::GALAT, $d['nama'].' bersyarat pada kolom {'.$d['syarat']['kunci'].'} yang sudah tidak ada, jadi tidak pernah tampil.', 'isian');
            } elseif (! $acuan['aktif']) {
                $hasil[] = self::butir(self::SARAN, $d['nama'].' bersyarat pada kolom "'.$acuan['label'].'" yang nonaktif — syaratnya hanya terpenuhi oleh data lama.', 'isian');
            }
        }

        foreach ($semua as $k) {
            if (! $k['aktif']) {
                continue;
            }

            if ($k['tipe'] === 'hitung' && ($k['rumus'] ?? null) !== null) {
                $hilang = array_values(array_filter(RumusBagianKustom::variabel($k['rumus']), fn ($v) => ! $peta->has($v)));

                if ($hilang !== []) {
                    $hasil[] = self::butir(self::GALAT, 'Rumus kolom "'.$k['label'].'" memakai kolom yang tidak ada: {'.implode('}, {', $hilang).'}.', 'isian');
                }
            }

            if ($k['tipe'] === 'informasi' && ($k['isi_info'] ?? null) === null) {
                $hasil[] = self::butir(self::GALAT, 'Teks informasi "'.$k['label'].'" belum berisi teks.', 'isian');
            }

            if ($k['tipe'] === 'referensi' && ReferensiBagianKustom::opsi((string) $k['sumber']) === []) {
                $hasil[] = self::butir(self::SARAN, 'Kolom "'.$k['label'].'" memilih dari '.mb_strtolower(ReferensiBagianKustom::SUMBER[$k['sumber']] ?? $k['sumber']).', tetapi datanya masih kosong.', 'isian');
            }
        }

        $aktif = array_filter($semua, fn ($k) => $k['aktif'] && ! JenisKolomBagianKustom::tanpaIsian($k['tipe']));

        if ($aktif !== [] && ! collect($aktif)->contains('wajib', true)) {
            $hasil[] = self::butir(self::INFO, 'Belum ada kolom wajib — pengisi bisa menyimpan poin hanya dengan IKU dan '.mb_strtolower($bagian->labelUraianEfektif()).'.', 'isian');
        }

        return $hasil;
    }

    /**
     * Alur kerja: setiap tahap pemeriksaan punya pemeriksa yang bisa membuka menu
     * penempatan; keterangan alur Isian Kegiatan & notula.
     *
     * @param  array<int, string>  $penempatan
     * @return array<int, array{tingkat: string, pesan: string, tab: string}>
     */
    private static function periksaAlur(BagianKustom $bagian, array $penempatan): array
    {
        $hasil = [];
        $alur = $bagian->alurKerja();
        $menuBeralur = array_values(array_diff($penempatan, [MenuBagianKustom::PENGISIAN]));

        if (AlurBagianKustom::pakaiVerifikasi($alur)) {
            foreach ($alur['tahapan'] as $i => $tahap) {
                $bisaBuka = array_filter($tahap['pemeriksa'], fn ($p) => collect($menuBeralur)->contains(fn ($m) => in_array($p, MenuBagianKustom::peranDiizinkan($m), true)));

                if ($bisaBuka === []) {
                    $hasil[] = self::butir(self::GALAT, 'Tahap pemeriksaan '.($i + 1).' ("'.$tahap['label'].'") tidak punya pemeriksa yang bisa membuka menu penempatan, jadi pengajuan akan tertahan di tahap itu.', 'alur');
                }
            }

            if (in_array(MenuBagianKustom::PENGISIAN, $penempatan, true)) {
                $hasil[] = self::butir(self::INFO, 'Isian di menu Isian Kegiatan tetap mengikuti pengajuan Ketua Tim & Verifikasi Tim SAKIP; alur bagian ini berlaku di menu lainnya.', 'alur');
            }
        } elseif ($alur['notula'] === AlurBagianKustom::NOTULA_DISETUJUI && $menuBeralur !== []) {
            $hasil[] = self::butir(self::INFO, 'Notula dibatasi "hanya yang disetujui", sedangkan alur langsung sah — di menu selain Isian Kegiatan semua isian tersimpan dianggap disetujui.', 'notula');
        }

        return $hasil;
    }

    /** Jumlah butir yang perlu perhatian (galat + saran, tanpa informasi). */
    public static function jumlahMasalah(array $masalah): int
    {
        return collect($masalah)->whereIn('tingkat', [self::GALAT, self::SARAN])->count();
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
