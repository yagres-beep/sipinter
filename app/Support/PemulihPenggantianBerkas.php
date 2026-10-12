<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Memeriksa & memulihkan operasi PenggantiBerkasAman yang tidak tuntas (proses PHP
 * mati, server restart, crash) dari jurnalnya. Dipakai perintah notula:penggantian-berkas.
 *
 * Keputusan diambil dari BUKTI, bukan umur berkas:
 * - tahap terakhir yang tercatat di jurnal;
 * - hash berkas yang benar-benar ada di tujuan/cadangan/sumber vs hash lama/baru di jurnal;
 * - kondisi DB vs nilai "sebelum"/"sesudah" yang dicatat SEBELUM lanjutan dijalankan
 *   (kunci 'verifikasi' jurnal), untuk tahu apakah transaksi DB sempat ter-commit.
 *
 * Format 'verifikasi': ['model' => FQCN Eloquent, 'id' => kunci, 'sebelum' => [kolom => nilai],
 * 'sesudah' => [kolom => nilai]]; nilai berawalan "sha256:" dibandingkan dengan hash isi
 * kolom (untuk teks panjang seperti HTML). DB dianggap "diterapkan" hanya bila cocok dengan
 * 'sesudah' DAN tidak cocok dengan 'sebelum' -- bila keduanya sama-sama cocok (atau tidak
 * ada yang cocok), statusnya AMBIGU dan operasi diserahkan ke pemeriksaan manual.
 *
 * periksa() read-only: hanya membaca berkas/DB, tidak membuat/memindah/menghapus apa pun.
 * pulihkan() mengambil kunci path tujuan dulu (operasi yang masih berjalan dilewati),
 * menganalisis ulang di bawah kunci, lalu mengecek ulang hash tepat sebelum tiap langkah.
 * Bila ada keraguan di langkah mana pun, berhenti dan SEMUA berkas terkait dipertahankan.
 */
class PemulihPenggantianBerkas
{
    public const SEDANG_BERJALAN = 'sedang_berjalan';

    public const KEMBALIKAN_LAMA = 'kembalikan_lama';

    public const PERTAHANKAN_BARU = 'pertahankan_baru';

    public const MANUAL = 'periksa_manual';

    public const DB_DITERAPKAN = 'diterapkan';

    public const DB_BELUM = 'belum';

    public const DB_AMBIGU = 'ambigu';

    public const DB_TIDAK_DIKETAHUI = 'tidak_diketahui';

    public function __construct(private PenggantiBerkasAman $pengganti) {}

    /**
     * @return list<array{id: string, label: ?string, tujuan: ?string, tahap: ?string, dibuat_pada: ?string, db: string, keputusan: string, alasan: string, langkah: list<array>}>
     */
    public function periksa(): array
    {
        $semua = $this->pengganti->daftarJurnal();

        return array_map(fn (array $j) => $this->analisis($j, $semua, cekKunci: true), $semua);
    }

    /**
     * @param  list<string>  $hanyaId  kosong = semua
     * @return list<array{id: string, berhasil: bool, keputusan: string, pesan: string}>
     */
    public function pulihkan(array $hanyaId = []): array
    {
        $hasil = [];

        foreach ($this->pengganti->daftarJurnal() as $jurnal) {
            if ($hanyaId !== [] && ! in_array($jurnal['id'], $hanyaId, true)) {
                continue;
            }

            if (! empty($jurnal['rusak'])) {
                $hasil[] = ['id' => $jurnal['id'], 'berhasil' => false, 'keputusan' => self::MANUAL, 'pesan' => 'Jurnal rusak/tidak terbaca -- tidak disentuh.'];

                continue;
            }

            $kunci = $this->pengganti->cobaKunci($jurnal['tujuan']);
            if ($kunci === null) {
                $hasil[] = ['id' => $jurnal['id'], 'berhasil' => false, 'keputusan' => self::SEDANG_BERJALAN, 'pesan' => 'Operasi masih berjalan (kunci dipegang proses lain) -- dilewati.'];

                continue;
            }

            try {
                // Analisis ulang DI BAWAH kunci -- kondisi bisa berubah sejak periksa().
                $analisis = $this->analisis($jurnal, $this->pengganti->daftarJurnal(), cekKunci: false);
                $hasil[] = $this->jalankan($jurnal, $analisis);
            } finally {
                $this->pengganti->lepasKunci($kunci);
            }
        }

        return $hasil;
    }

    /**
     * @param  list<array>  $semuaJurnal
     */
    public function analisis(array $jurnal, array $semuaJurnal, bool $cekKunci): array
    {
        $dasar = [
            'id' => $jurnal['id'],
            'label' => $jurnal['label'] ?? null,
            'tujuan' => $jurnal['tujuan'] ?? null,
            'tahap' => $jurnal['tahap'] ?? null,
            'dibuat_pada' => $jurnal['dibuat_pada'] ?? null,
            'db' => self::DB_TIDAK_DIKETAHUI,
            'langkah' => [],
        ];

        if (! empty($jurnal['rusak'])) {
            return $dasar + ['keputusan' => self::MANUAL, 'alasan' => 'Jurnal rusak/tidak terbaca.'];
        }

        if ($cekKunci && $this->pengganti->sedangDikunci($jurnal['tujuan'])) {
            return $dasar + ['keputusan' => self::SEDANG_BERJALAN, 'alasan' => 'Kunci berkas tujuan masih dipegang -- operasi kemungkinan masih berjalan.'];
        }

        foreach ($semuaJurnal as $lain) {
            if (empty($lain['rusak']) && $lain['id'] !== $jurnal['id'] && $lain['tujuan'] === $jurnal['tujuan'] && strcmp($lain['id'], $jurnal['id']) > 0) {
                return $dasar + ['keputusan' => self::MANUAL, 'alasan' => "Ada operasi lebih baru ({$lain['id']}) atas berkas yang sama -- urutan pemulihan harus diputuskan manual."];
            }
        }

        $db = $this->statusDb($jurnal['verifikasi'] ?? null);
        $dasar['db'] = $db;

        [$arah, $alasanArah] = match ($jurnal['tahap']) {
            PenggantiBerkasAman::TAHAP_DISIAPKAN,
            PenggantiBerkasAman::TAHAP_LAMA_DICADANGKAN,
            PenggantiBerkasAman::TAHAP_GAGAL_PULIH => $db === self::DB_DITERAPKAN
                ? [null, 'Jurnal menyatakan lanjutan DB belum/tidak berhasil, tapi DB sudah berisi kondisi "sesudah" -- bertentangan.']
                : ['lama', 'Lanjutan DB belum dijalankan/sudah di-rollback; berkas lama dikembalikan.'],
            PenggantiBerkasAman::TAHAP_BARU_TERPASANG => match ($db) {
                self::DB_DITERAPKAN => ['baru', 'Transaksi DB terbukti sudah ter-commit; berkas baru dipertahankan.'],
                self::DB_BELUM => ['lama', 'Transaksi DB terbukti belum ter-commit; berkas lama dikembalikan.'],
                default => [null, 'Proses berhenti saat/menjelang transaksi DB dan kondisi DB tidak bisa membuktikan apakah sudah ter-commit ('.$db.').'],
            },
            PenggantiBerkasAman::TAHAP_DB_SELESAI,
            PenggantiBerkasAman::TAHAP_CADANGAN_TERTINGGAL => $db === self::DB_BELUM
                ? [null, 'Jurnal menyatakan DB sudah diperbarui, tapi DB masih berisi kondisi "sebelum" -- bertentangan.']
                : ['baru', 'Operasi sudah sukses; tinggal membersihkan cadangan.'],
            default => [null, 'Tahap jurnal tidak dikenal: '.$jurnal['tahap']],
        };

        if ($arah === null) {
            return $dasar + ['keputusan' => self::MANUAL, 'alasan' => $alasanArah];
        }

        [$langkah, $masalah] = $arah === 'lama' ? $this->rencanaLama($jurnal) : $this->rencanaBaru($jurnal);

        if ($masalah !== null) {
            return $dasar + ['keputusan' => self::MANUAL, 'alasan' => $alasanArah.' Namun: '.$masalah];
        }

        $langkah[] = ['op' => 'hapus_jurnal'];

        return array_merge($dasar, [
            'keputusan' => $arah === 'lama' ? self::KEMBALIKAN_LAMA : self::PERTAHANKAN_BARU,
            'alasan' => $alasanArah,
            'langkah' => $langkah,
        ]);
    }

    /**
     * @return array{0: list<array>, 1: ?string}
     */
    private function rencanaLama(array $j): array
    {
        $langkah = [];
        $h = fn (?string $p) => $p !== null ? $this->pengganti->hashBerkas($p) : null;
        $hT = $h($j['tujuan']);
        $hC = $h($j['cadangan']);
        $hS = $h($j['sumber']);

        if ($hS !== null) {
            if ($hS !== $j['hash_baru']) {
                return [[], 'berkas sementara berisi data yang tidak dikenal jurnal.'];
            }
            $langkah[] = ['op' => 'hapus', 'path' => $j['sumber'], 'hash_wajib' => $j['hash_baru']];
        }

        if ($j['hash_lama'] === null) {
            if ($hT === null) {
                return [$langkah, null];
            }
            if ($hT === $j['hash_baru']) {
                $langkah[] = ['op' => 'hapus', 'path' => $j['tujuan'], 'hash_wajib' => $j['hash_baru']];

                return [$langkah, null];
            }

            return [[], 'berkas di tujuan bukan milik operasi ini.'];
        }

        if ($hT === $j['hash_lama']) {
            if ($hC !== null) {
                if ($hC !== $j['hash_lama']) {
                    return [[], 'cadangan berisi data berbeda dari berkas lama.'];
                }
                $langkah[] = ['op' => 'hapus', 'path' => $j['cadangan'], 'hash_wajib' => $j['hash_lama']];
            }

            return [$langkah, null];
        }

        if ($hC === $j['hash_lama'] && ($hT === null || $hT === $j['hash_baru'])) {
            $langkah[] = ['op' => 'pindahkan', 'dari' => $j['cadangan'], 'ke' => $j['tujuan'], 'hash_wajib' => $j['hash_lama'], 'tujuan_boleh' => [null, $j['hash_baru']]];

            return [$langkah, null];
        }

        return [[], $hC === null
            ? 'cadangan tidak ditemukan dan berkas di tujuan bukan berkas lama.'
            : 'berkas di tujuan bukan berkas lama maupun berkas baru operasi ini.'];
    }

    /**
     * @return array{0: list<array>, 1: ?string}
     */
    private function rencanaBaru(array $j): array
    {
        $langkah = [];
        $h = fn (?string $p) => $p !== null ? $this->pengganti->hashBerkas($p) : null;

        if ($h($j['tujuan']) !== $j['hash_baru']) {
            return [[], 'berkas di tujuan bukan berkas baru operasi ini.'];
        }

        $hC = $h($j['cadangan']);
        if ($hC !== null) {
            if ($hC !== $j['hash_lama']) {
                return [[], 'cadangan berisi data berbeda dari berkas lama.'];
            }
            $langkah[] = ['op' => 'hapus', 'path' => $j['cadangan'], 'hash_wajib' => $j['hash_lama']];
        }

        $hS = $h($j['sumber']);
        if ($hS !== null) {
            if ($hS !== $j['hash_baru']) {
                return [[], 'berkas sementara berisi data yang tidak dikenal jurnal.'];
            }
            $langkah[] = ['op' => 'hapus', 'path' => $j['sumber'], 'hash_wajib' => $j['hash_baru']];
        }

        return [$langkah, null];
    }

    private function jalankan(array $jurnal, array $analisis): array
    {
        $hasil = ['id' => $jurnal['id'], 'keputusan' => $analisis['keputusan']];

        if (! in_array($analisis['keputusan'], [self::KEMBALIKAN_LAMA, self::PERTAHANKAN_BARU], true)) {
            return $hasil + ['berhasil' => false, 'pesan' => 'Tidak dipulihkan otomatis: '.$analisis['alasan']];
        }

        foreach ($analisis['langkah'] as $langkah) {
            $masalah = $this->jalankanLangkah($jurnal, $langkah);

            if ($masalah !== null) {
                $jurnal['catatan'][] = 'Pemulihan berhenti: '.$masalah;
                $this->pengganti->simpanJurnal($jurnal);
                Log::error("Pemulihan operasi penggantian berkas {$jurnal['id']} berhenti: {$masalah}");

                return $hasil + ['berhasil' => false, 'pesan' => "Pemulihan berhenti, berkas tersisa dipertahankan: {$masalah}"];
            }
        }

        Log::info("Operasi penggantian berkas {$jurnal['id']} dipulihkan ({$analisis['keputusan']}).", ['tujuan' => $jurnal['tujuan']]);

        return $hasil + ['berhasil' => true, 'pesan' => $analisis['alasan']];
    }

    private function jalankanLangkah(array $jurnal, array $langkah): ?string
    {
        switch ($langkah['op']) {
            case 'hapus':
                if ($this->pengganti->hashBerkas($langkah['path']) !== $langkah['hash_wajib']) {
                    return 'isi '.basename($langkah['path']).' berubah sejak dianalisis.';
                }

                return $this->pengganti->hapusDenganUlang($langkah['path']) ? null : basename($langkah['path']).' tidak bisa dihapus.';

            case 'pindahkan':
                if ($this->pengganti->hashBerkas($langkah['dari']) !== $langkah['hash_wajib']) {
                    return 'isi '.basename($langkah['dari']).' berubah sejak dianalisis.';
                }
                if (! in_array($this->pengganti->hashBerkas($langkah['ke']), $langkah['tujuan_boleh'], true)) {
                    return 'isi '.basename($langkah['ke']).' berubah sejak dianalisis.';
                }
                if (! $this->pengganti->pindahkanDenganUlang($langkah['dari'], $langkah['ke'])) {
                    return basename($langkah['dari']).' tidak bisa dipindahkan ke '.basename($langkah['ke']).' (cadangan dipertahankan).';
                }

                return $this->pengganti->hashBerkas($langkah['ke']) === $langkah['hash_wajib'] ? null : 'isi '.basename($langkah['ke']).' setelah dipindah tidak sesuai.';

            case 'hapus_jurnal':
                return $this->pengganti->hapusJurnal($jurnal['id']) ? null : 'jurnal tidak bisa dihapus.';
        }

        return 'langkah tidak dikenal.';
    }

    public function statusDb(?array $verifikasi): string
    {
        if (! is_array($verifikasi) || ! isset($verifikasi['model'], $verifikasi['id'], $verifikasi['sebelum'], $verifikasi['sesudah'])) {
            return self::DB_TIDAK_DIKETAHUI;
        }

        $kelas = $verifikasi['model'];
        if (! is_string($kelas) || ! class_exists($kelas) || ! is_subclass_of($kelas, Model::class)) {
            return self::DB_TIDAK_DIKETAHUI;
        }

        try {
            $model = $kelas::query()->find($verifikasi['id']);
        } catch (Throwable) {
            return self::DB_TIDAK_DIKETAHUI;
        }

        if ($model === null) {
            return self::DB_TIDAK_DIKETAHUI;
        }

        $cocok = function (array $harapan) use ($model): bool {
            foreach ($harapan as $kolom => $nilai) {
                $aktual = $model->getRawOriginal($kolom);
                if (is_string($nilai) && str_starts_with($nilai, 'sha256:')) {
                    if ($aktual === null || 'sha256:'.hash('sha256', (string) $aktual) !== $nilai) {
                        return false;
                    }
                } elseif ($nilai === null ? $aktual !== null : (string) $aktual !== (string) $nilai) {
                    return false;
                }
            }

            return true;
        };

        $sesudah = $cocok($verifikasi['sesudah']);
        $sebelum = $cocok($verifikasi['sebelum']);

        return match (true) {
            $sesudah && ! $sebelum => self::DB_DITERAPKAN,
            $sebelum && ! $sesudah => self::DB_BELUM,
            default => self::DB_AMBIGU,
        };
    }

    /**
     * Berkas *.cadangan-* / *.tmp di $dirs yang TIDAK dirujuk jurnal mana pun -- hanya
     * DILAPORKAN (mis. sisa versi kode lama tanpa jurnal), tidak pernah dihapus otomatis.
     *
     * @param  list<string>  $dirs
     * @return list<string>
     */
    public function berkasTanpaJurnal(array $dirs): array
    {
        $dirujuk = [];
        foreach ($this->pengganti->daftarJurnal() as $j) {
            foreach (['sumber', 'cadangan', 'tujuan'] as $k) {
                if (! empty($j[$k])) {
                    $dirujuk[str_replace('\\', '/', $j[$k])] = true;
                }
            }
        }

        $hasil = [];
        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $berkas) {
                $nama = $berkas->getFilename();
                if ($berkas->isFile() && (str_contains($nama, '.cadangan-') || str_ends_with($nama, '.tmp'))
                    && ! isset($dirujuk[str_replace('\\', '/', $berkas->getPathname())])) {
                    $hasil[] = $berkas->getPathname();
                }
            }
        }
        sort($hasil);

        return $hasil;
    }
}
