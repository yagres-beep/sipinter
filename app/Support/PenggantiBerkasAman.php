<?php

namespace App\Support;

use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Memasang berkas baru (mis. PDF notula hasil render) ke path tujuan yang MUNGKIN sudah
 * berisi berkas lama, dengan jaminan berkas lama tidak hilang bila pemasangan atau
 * langkah lanjutannya (pembaruan database) gagal -- termasuk bila proses PHP mati di
 * tengah jalan (lihat PemulihPenggantianBerkas & perintah notula:penggantian-berkas).
 *
 * Filesystem dan database TIDAK membentuk satu transaksi atomik. Yang dijamin:
 * (1) berkas lama tidak pernah dihapus sebelum DB berhasil diperbarui dan berkas baru
 * terverifikasi; (2) setiap tahap tercatat di JURNAL (JSON per operasi, ID unik)
 * SEBELUM tahap berikutnya dimulai, berisi hash berkas lama/baru dan kondisi DB
 * sebelum/sesudah yang diharapkan -- sehingga operasi yang terputus bisa diputuskan
 * arah pemulihannya dari bukti, bukan dari umur berkas.
 *
 * Perilaku Windows (PHP 8.2, sudah diuji): rename() menimpa tujuan yang sedang DIBUKA
 * gagal ("Access is denied", cuma false + warning), sedangkan rename() berkas terbuka
 * itu ke NAMA LAIN berhasil -- karena itu berkas lama dipindah dulu ke nama cadangan.
 * Tiap pemindahan dicoba ulang terbatas ($percobaan x $jedaMs) untuk kunci sesaat.
 *
 * Urutan pasang() -- dijalankan di bawah kunci eksklusif per path tujuan (flock,
 * berlaku lintas proses), jadi dua operasi atas berkas yang sama tidak bisa bertumpuk:
 *   1. validasi sumber (ada, tidak kosong, format sesuai $opsi) & siapkan folder
 *   2. tulis jurnal [disiapkan]                    -- belum ada berkas yang disentuh
 *   3. berkas lama -> cadangan, jurnal [lama_dicadangkan]
 *   4. sumber -> tujuan, verifikasi hash, jurnal [baru_terpasang]
 *   5. $lanjutan() (update DB dalam transaksi)
 *   6. jurnal [db_selesai], hapus cadangan, hapus jurnal
 * Gagal di 1-4 atau $lanjutan melempar: berkas baru dibuang, cadangan dikembalikan,
 * sumber dihapus, jurnal dihapus -- atau, bila pemulihan sendiri gagal, jurnal
 * DIPERTAHANKAN berstatus gagal_pulih dan cadangan TIDAK dihapus.
 */
class PenggantiBerkasAman
{
    public const TAHAP_DISIAPKAN = 'disiapkan';

    public const TAHAP_LAMA_DICADANGKAN = 'lama_dicadangkan';

    /** Berkas baru terpasang; $lanjutan (DB) mungkin belum/sedang/sudah jalan. */
    public const TAHAP_BARU_TERPASANG = 'baru_terpasang';

    /** $lanjutan sukses; tinggal membersihkan cadangan. */
    public const TAHAP_DB_SELESAI = 'db_selesai';

    /** Pemulihan saat runtime gagal; DB tidak berubah (lanjutan gagal/belum jalan). */
    public const TAHAP_GAGAL_PULIH = 'gagal_pulih';

    /** Operasi sukses tapi cadangan tidak bisa dihapus (mis. masih dibuka di Windows). */
    public const TAHAP_CADANGAN_TERTINGGAL = 'cadangan_tertinggal';

    private string $dirKerja;

    public function __construct(
        ?string $dirKerja = null,
        private int $percobaan = 3,
        private int $jedaMs = 200,
        private int $tungguKunciMs = 10000,
    ) {
        $this->dirKerja = rtrim($dirKerja ?? storage_path('app/private/penggantian-berkas'), '/\\');
    }

    /**
     * @template T
     *
     * @param  string  $sumber  berkas sementara yang SUDAH lengkap (dihapus bila gagal)
     * @param  (callable(): T)|null  $lanjutan  dijalankan setelah berkas baru terpasang,
     *                                          WAJIB transaksional (DB::transaction) --
     *                                          bila melempar, berkas lama dipulihkan
     * @param  array{awalan?: string, akhiran_memuat?: string, verifikasi?: array, label?: string}  $opsi
     *                                                                                                     - awalan: byte pembuka wajib sumber (mis. '%PDF-')
     *                                                                                                     - akhiran_memuat: teks yang wajib ada di 2 KB terakhir (mis. '%%EOF')
     *                                                                                                     - verifikasi: kondisi DB sebelum/sesudah $lanjutan, dipakai
     *                                                                                                     PemulihPenggantianBerkas (lihat formatnya di sana)
     *                                                                                                     - label: keterangan singkat operasi untuk log/laporan
     * @return T|null
     */
    public function pasang(string $sumber, string $tujuan, ?callable $lanjutan = null, array $opsi = []): mixed
    {
        $nama = basename($tujuan);

        try {
            $kunci = $this->ambilKunci($tujuan);
        } catch (Throwable $e) {
            $this->hapusSumberAtauCatat($sumber);

            throw $e;
        }

        try {
            return $this->pasangTerkunci($sumber, $tujuan, $nama, $lanjutan, $opsi);
        } finally {
            $this->lepasKunci($kunci);
        }
    }

    private function pasangTerkunci(string $sumber, string $tujuan, string $nama, ?callable $lanjutan, array $opsi): mixed
    {
        // 1. Validasi & persiapan -- belum ada berkas lama yang disentuh.
        try {
            $hashBaru = $this->validasiSumber($sumber, $nama, $opsi);
            $this->pastikanFolder(dirname($tujuan));
            $hashLama = is_file($tujuan) ? $this->hashBerkas($tujuan) : null;
            if (file_exists($tujuan) && $hashLama === null) {
                throw new RuntimeException("Berkas lama {$nama} tidak bisa dibaca -- tidak diganti.");
            }
        } catch (Throwable $e) {
            $this->hapusSumberAtauCatat($sumber);

            throw $e;
        }

        $id = (new DateTimeImmutable)->format('YmdHis').'-'.Str::lower(Str::random(10));
        $jurnal = [
            'id' => $id,
            'versi' => 1,
            'tahap' => self::TAHAP_DISIAPKAN,
            'label' => $opsi['label'] ?? null,
            'tujuan' => $tujuan,
            'sumber' => $sumber,
            'cadangan' => $hashLama !== null ? $tujuan.'.cadangan-'.$id : null,
            'hash_lama' => $hashLama,
            'hash_baru' => $hashBaru,
            'verifikasi' => $opsi['verifikasi'] ?? null,
            'pid' => getmypid(),
            'host' => gethostname() ?: null,
            'dibuat_pada' => $this->sekarang(),
            'diperbarui_pada' => $this->sekarang(),
            'catatan' => [],
        ];

        // 2. Jurnal WAJIB tersimpan sebelum berkas apa pun dipindah.
        if (! $this->simpanJurnal($jurnal)) {
            $this->hapusSumberAtauCatat($sumber);

            throw new RuntimeException("Catatan operasi penggantian {$nama} tidak bisa ditulis (folder {$this->dirKerja} tidak bisa ditulisi) -- berkas lama tidak diubah.");
        }

        // 3. Berkas lama -> cadangan.
        if ($jurnal['cadangan'] !== null) {
            if (! $this->pindahkanDenganUlang($tujuan, $jurnal['cadangan'])) {
                $this->hapusSumberAtauCatat($sumber);
                $this->hapusJurnal($id);

                throw new RuntimeException("Berkas lama {$nama} tidak bisa diganti (terkunci atau izin ditolak) -- berkas lama tetap dipakai. Tutup pratinjau/unduhan berkas itu lalu coba lagi.");
            }
            $this->titik('lama_dicadangkan', $jurnal);

            if (! $this->perbaruiTahap($jurnal, self::TAHAP_LAMA_DICADANGKAN)) {
                throw $this->batalkan($jurnal, "Catatan operasi penggantian {$nama} tidak bisa diperbarui -- dibatalkan, berkas lama dipertahankan.");
            }
        }

        // 4. Sumber -> tujuan, lalu verifikasi isi yang benar-benar terpasang.
        if (! $this->pindahkanDenganUlang($sumber, $tujuan)) {
            throw $this->batalkan($jurnal, "Berkas baru {$nama} gagal dipasang di server (terkunci, izin folder ditolak, atau disk penuh) -- berkas lama dipertahankan.");
        }
        $this->titik('baru_dipindahkan', $jurnal);

        if ($this->hashBerkas($tujuan) !== $hashBaru) {
            throw $this->batalkan($jurnal, "Berkas baru {$nama} tidak utuh setelah dipasang (isi berbeda dari hasil render) -- berkas lama dipertahankan.");
        }

        if (! $this->perbaruiTahap($jurnal, self::TAHAP_BARU_TERPASANG)) {
            throw $this->batalkan($jurnal, "Catatan operasi penggantian {$nama} tidak bisa diperbarui -- dibatalkan, berkas lama dipertahankan.");
        }
        $this->titik('baru_terpasang', $jurnal);

        // 5. Lanjutan (DB). Exception-nya diteruskan apa adanya setelah berkas lama pulih.
        try {
            $hasil = $lanjutan !== null ? $lanjutan() : null;
        } catch (Throwable $e) {
            throw $this->batalkan($jurnal, null, $e);
        }
        $this->titik('lanjutan_selesai', $jurnal);

        // 6. Bersih-bersih. Gagal di sini TIDAK membatalkan apa pun -- DB sudah konsisten
        // dengan berkas baru; cadangan cuma dibiarkan dan dicatat untuk dibersihkan nanti.
        if (! $this->perbaruiTahap($jurnal, self::TAHAP_DB_SELESAI)) {
            Log::warning("Jurnal penggantian berkas {$id} tidak bisa ditandai db_selesai; perintah notula:penggantian-berkas akan memverifikasinya ke DB.");
        }
        $this->titik('db_selesai', $jurnal);

        if ($jurnal['cadangan'] !== null && ! $this->hapusDenganUlang($jurnal['cadangan'])) {
            $jurnal['catatan'][] = 'Cadangan tidak bisa dihapus setelah operasi sukses.';
            $this->perbaruiTahap($jurnal, self::TAHAP_CADANGAN_TERTINGGAL);
            Log::warning("Cadangan berkas lama tidak bisa dihapus setelah penggantian sukses (operasi {$id}); jalankan notula:penggantian-berkas --pulihkan untuk membersihkannya.", ['cadangan' => $jurnal['cadangan']]);

            return $hasil;
        }

        if (! $this->hapusJurnal($id)) {
            Log::warning("Jurnal penggantian berkas {$id} sudah selesai tapi tidak bisa dihapus.");
        }

        return $hasil;
    }

    /**
     * Kembalikan kondisi sebelum operasi (berkas baru dibuang, cadangan dikembalikan,
     * sumber dihapus) lalu kembalikan exception untuk dilempar pemanggil. Bila pemulihan
     * gagal, jurnal dipertahankan sebagai gagal_pulih dan cadangan TIDAK dihapus --
     * pesannya digabung dengan penyebab awal supaya tidak ada yang tertutupi.
     */
    private function batalkan(array &$jurnal, ?string $pesan, ?Throwable $penyebab = null): Throwable
    {
        $masalah = $this->kembalikanBerkasLama($jurnal);

        if ($masalah === null) {
            if (! $this->hapusJurnal($jurnal['id'])) {
                Log::warning("Jurnal penggantian berkas {$jurnal['id']} sudah dipulihkan tapi tidak bisa dihapus.");
            }

            return $penyebab ?? new RuntimeException($pesan);
        }

        $jurnal['catatan'][] = $masalah;
        $this->perbaruiTahap($jurnal, self::TAHAP_GAGAL_PULIH);
        Log::critical("Pemulihan berkas lama gagal (operasi {$jurnal['id']}): {$masalah}", ['tujuan' => $jurnal['tujuan'], 'cadangan' => $jurnal['cadangan']]);

        $sebab = $penyebab?->getMessage() ?? $pesan;

        return new RuntimeException(
            'Berkas lama '.basename($jurnal['tujuan']).' gagal dipulihkan otomatis'
            .($jurnal['cadangan'] !== null ? '; salinannya aman di '.basename($jurnal['cadangan']) : '')
            ." (operasi {$jurnal['id']}) -- hubungi admin. Penyebab awal: {$sebab}",
            0,
            $penyebab
        );
    }

    /**
     * @return string|null null bila berhasil, atau uraian masalah
     */
    private function kembalikanBerkasLama(array $jurnal): ?string
    {
        $masalah = [];

        if (is_file($jurnal['sumber']) && ! $this->hapusDenganUlang($jurnal['sumber'])) {
            $masalah[] = 'berkas sementara '.basename($jurnal['sumber']).' tidak bisa dihapus';
        }

        $hashTujuan = is_file($jurnal['tujuan']) ? $this->hashBerkas($jurnal['tujuan']) : null;

        if ($jurnal['cadangan'] !== null && is_file($jurnal['cadangan'])) {
            // Cadangan dipindah balik (menimpa berkas baru bila sempat terpasang), lalu
            // isinya diverifikasi -- cadangan yang gagal dipindah TIDAK disentuh lagi.
            if (! $this->pindahkanDenganUlang($jurnal['cadangan'], $jurnal['tujuan'])) {
                $masalah[] = 'cadangan tidak bisa dikembalikan ke '.basename($jurnal['tujuan']);
            } elseif ($this->hashBerkas($jurnal['tujuan']) !== $jurnal['hash_lama']) {
                $masalah[] = 'isi berkas setelah dipulihkan tidak sama dengan berkas lama';
            }
        } elseif ($jurnal['cadangan'] !== null && $hashTujuan !== $jurnal['hash_lama']) {
            $masalah[] = 'cadangan hilang dan berkas di tujuan bukan berkas lama';
        } elseif ($jurnal['cadangan'] === null && $hashTujuan !== null) {
            // Sebelumnya memang tidak ada berkas, dan path ini dikunci operasi ini sejak
            // awal -- berkas di sana pasti hasil operasi ini (baru atau rusak): dibuang.
            if (! $this->hapusDenganUlang($jurnal['tujuan'])) {
                $masalah[] = 'berkas baru yang batal dipakai tidak bisa dihapus';
            }
        }

        return $masalah === [] ? null : implode('; ', $masalah);
    }

    /**
     * Hook untuk menyimulasikan proses mati di tengah operasi (pengujian). Tidak
     * melakukan apa-apa di kode produksi.
     */
    protected function titik(string $nama, array $jurnal): void {}

    // ------------------------------------------------------------------
    // Validasi
    // ------------------------------------------------------------------

    /**
     * @return string sha256 isi sumber
     */
    private function validasiSumber(string $sumber, string $nama, array $opsi): string
    {
        clearstatcache(true, $sumber);
        $ukuran = is_file($sumber) ? filesize($sumber) : false;
        if ($ukuran === false || $ukuran === 0) {
            throw new RuntimeException("Berkas baru untuk {$nama} kosong atau tidak ditemukan -- berkas lama tidak diubah.");
        }

        $awalan = $opsi['awalan'] ?? null;
        $akhiran = $opsi['akhiran_memuat'] ?? null;
        if ($awalan !== null || $akhiran !== null) {
            $h = @fopen($sumber, 'rb');
            if ($h === false) {
                throw new RuntimeException("Berkas baru untuk {$nama} tidak bisa dibaca -- berkas lama tidak diubah.");
            }
            $kepala = $awalan !== null ? (string) fread($h, strlen($awalan)) : '';
            $ekor = '';
            if ($akhiran !== null) {
                fseek($h, max(0, $ukuran - 2048));
                $ekor = (string) stream_get_contents($h);
            }
            fclose($h);

            if (($awalan !== null && $kepala !== $awalan) || ($akhiran !== null && ! str_contains($ekor, $akhiran))) {
                throw new RuntimeException("Berkas baru untuk {$nama} bukan berkas yang valid (format/akhir berkas tidak sesuai, kemungkinan terpotong) -- berkas lama tidak diubah.");
            }
        }

        $hash = $this->hashBerkas($sumber);
        if ($hash === null) {
            throw new RuntimeException("Berkas baru untuk {$nama} tidak bisa dibaca -- berkas lama tidak diubah.");
        }

        return $hash;
    }

    /**
     * @throws RuntimeException bila folder tidak bisa dibuat atau tidak bisa ditulisi
     */
    public function pastikanFolder(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Folder penyimpanan tidak bisa dibuat: {$dir}. Periksa izin tulis folder storage di server.");
        }

        if (! is_writable($dir)) {
            throw new RuntimeException("Folder penyimpanan tidak bisa ditulisi: {$dir}. Periksa izin tulis folder storage di server.");
        }
    }

    /** @internal */
    public function hashBerkas(string $path): ?string
    {
        clearstatcache(true, $path);
        if (! is_file($path)) {
            return null;
        }

        $hash = @hash_file('sha256', $path);

        return $hash === false ? null : $hash;
    }

    // ------------------------------------------------------------------
    // Kunci per path tujuan
    // ------------------------------------------------------------------

    /**
     * @return resource
     */
    private function ambilKunci(string $tujuan)
    {
        $batas = microtime(true) + $this->tungguKunciMs / 1000;

        do {
            $kunci = $this->cobaKunci($tujuan);
            if ($kunci !== null) {
                return $kunci;
            }
            usleep(100_000);
        } while (microtime(true) < $batas);

        throw new RuntimeException('Berkas '.basename($tujuan).' sedang diganti oleh proses lain. Coba lagi beberapa saat lagi.');
    }

    /**
     * Kunci eksklusif NON-blocking atas path tujuan (berkas kunci di {dirKerja}/kunci,
     * BUKAN di folder tujuan, supaya folder notula tidak berisi berkas asing).
     *
     * @internal
     *
     * @return resource|null null bila sedang dipegang proses/operasi lain
     */
    public function cobaKunci(string $tujuan)
    {
        $dirKunci = $this->dirKerja.DIRECTORY_SEPARATOR.'kunci';
        $this->pastikanFolder($dirKunci);

        $h = @fopen($dirKunci.DIRECTORY_SEPARATOR.$this->kunciNama($tujuan).'.lock', 'c');
        if ($h === false) {
            throw new RuntimeException("Berkas kunci penggantian tidak bisa dibuat di {$dirKunci}. Periksa izin tulis folder storage di server.");
        }

        if (! flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);

            return null;
        }

        return $h;
    }

    /**
     * Apakah path tujuan sedang dikunci operasi lain -- TANPA membuat berkas apa pun
     * (dipakai mode periksa yang harus read-only).
     *
     * @internal
     */
    public function sedangDikunci(string $tujuan): bool
    {
        $path = $this->dirKerja.DIRECTORY_SEPARATOR.'kunci'.DIRECTORY_SEPARATOR.$this->kunciNama($tujuan).'.lock';
        if (! is_file($path)) {
            return false;
        }

        $h = @fopen($path, 'r');
        if ($h === false) {
            return true;
        }
        $bebas = flock($h, LOCK_EX | LOCK_NB);
        if ($bebas) {
            flock($h, LOCK_UN);
        }
        fclose($h);

        return ! $bebas;
    }

    /** @internal */
    public function lepasKunci($kunci): void
    {
        if (is_resource($kunci)) {
            flock($kunci, LOCK_UN);
            fclose($kunci);
        }
    }

    private function kunciNama(string $tujuan): string
    {
        $normal = str_replace('\\', '/', $tujuan);
        if (PHP_OS_FAMILY === 'Windows') {
            $normal = strtolower($normal);
        }

        return sha1($normal);
    }

    // ------------------------------------------------------------------
    // Jurnal
    // ------------------------------------------------------------------

    /** @internal */
    public function dirJurnal(): string
    {
        return $this->dirKerja.DIRECTORY_SEPARATOR.'jurnal';
    }

    /**
     * Tulis jurnal lewat berkas sementara + rename, dicek hasilnya.
     *
     * @internal
     */
    public function simpanJurnal(array $jurnal): bool
    {
        try {
            $this->pastikanFolder($this->dirJurnal());
        } catch (RuntimeException) {
            return false;
        }

        $path = $this->dirJurnal().DIRECTORY_SEPARATOR.$jurnal['id'].'.json';
        $sementara = $path.'.'.Str::lower(Str::random(6)).'.tmp';
        $isi = json_encode($jurnal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($isi === false || @file_put_contents($sementara, $isi) !== strlen($isi)) {
            @unlink($sementara);

            return false;
        }

        if (! $this->pindahkanDenganUlang($sementara, $path)) {
            @unlink($sementara);

            return false;
        }

        return true;
    }

    private function perbaruiTahap(array &$jurnal, string $tahap): bool
    {
        $jurnal['tahap'] = $tahap;
        $jurnal['diperbarui_pada'] = $this->sekarang();

        return $this->simpanJurnal($jurnal);
    }

    /** @internal */
    public function hapusJurnal(string $id): bool
    {
        return $this->hapusDenganUlang($this->dirJurnal().DIRECTORY_SEPARATOR.$id.'.json');
    }

    /**
     * @internal
     *
     * @return list<array> jurnal yang masih ada (operasi belum tuntas), urut ID
     */
    public function daftarJurnal(): array
    {
        $hasil = [];
        foreach (glob($this->dirJurnal().DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
            $isi = @file_get_contents($path);
            $data = $isi !== false ? json_decode($isi, true) : null;
            $hasil[] = is_array($data) && isset($data['id'], $data['tujuan'], $data['tahap'])
                ? $data
                : ['id' => pathinfo($path, PATHINFO_FILENAME), 'rusak' => true, 'path_jurnal' => $path];
        }

        usort($hasil, fn ($a, $b) => strcmp($a['id'], $b['id']));

        return $hasil;
    }

    // ------------------------------------------------------------------
    // Operasi berkas tingkat rendah -- nilai kembalian SELALU dicek pemanggil
    // ------------------------------------------------------------------

    /** @internal */
    public function pindahkanDenganUlang(string $dari, string $ke): bool
    {
        for ($i = 1; $i <= max(1, $this->percobaan); $i++) {
            clearstatcache();
            if ($this->pindahkan($dari, $ke)) {
                clearstatcache();

                return is_file($ke);
            }
            if ($i < $this->percobaan && $this->jedaMs > 0) {
                usleep($this->jedaMs * 1000);
            }
        }

        return false;
    }

    /** @internal */
    public function hapusDenganUlang(string $path): bool
    {
        for ($i = 1; $i <= max(1, $this->percobaan); $i++) {
            clearstatcache(true, $path);
            if ($this->hapus($path)) {
                return true;
            }
            if ($i < $this->percobaan && $this->jedaMs > 0) {
                usleep($this->jedaMs * 1000);
            }
        }

        return false;
    }

    private function hapusSumberAtauCatat(string $sumber): void
    {
        if (! $this->hapusDenganUlang($sumber)) {
            Log::warning('Berkas sementara tidak bisa dihapus: '.basename($sumber), ['path' => $sumber]);
        }
    }

    /** Seam pengujian. */
    protected function pindahkan(string $dari, string $ke): bool
    {
        return @rename($dari, $ke);
    }

    /** Seam pengujian. */
    protected function hapus(string $path): bool
    {
        clearstatcache(true, $path);

        return ! file_exists($path) || (@unlink($path) && ! file_exists($path));
    }

    private function sekarang(): string
    {
        return (new DateTimeImmutable)->format(DATE_ATOM);
    }
}
