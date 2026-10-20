<?php

namespace App\Services;

use App\Models\Berkas;
use App\Models\Kegiatan;
use App\Models\RtlEvaluasi;
use App\Models\SalinanBuktiRtl;
use App\Models\StorageAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Menyalin bukti capaian kegiatan yang tertaut ke poin RTL (kegiatan.rtl_evaluasi_id)
 * ke folder Evaluasi-RTL poin itu di Google Drive, supaya kedua folder benar-benar
 * berisi berkas yang sama — bukan sekadar menampilkan nama berkas yang sama.
 *
 * Aturan:
 * - Relasi dari ID (rtl_evaluasi_id), tidak pernah dari kemiripan teks.
 * - Satu baris salinan_bukti_rtl per (berkas, poin RTL); salinan yang sudah mutakhir
 *   tidak disalin lagi, dan sebelum menyalin dicari dulu salinan berpenanda di Drive
 *   (kasus Drive sukses tapi database gagal mencatat) — tidak pernah dobel.
 * - Status verifikasi TIDAK disalin: salinan hanya letak fisik, status tetap satu
 *   milik berkas sumber.
 * - Gagal menyalin tidak menggagalkan unggahan sumber — baris ditandai "gagal" dan
 *   dicoba lagi (tombol Coba lagi / perintah bukti:sinkron-rtl).
 */
class SinkronBuktiRtlService
{
    public function __construct(protected FolderStructureService $folder, protected GoogleDriveService $drive) {}

    /** Penanda appProperties salinan — kode berkas sumber + poin RTL tujuan. */
    public static function penanda(Berkas $sumber, int $rtlId): string
    {
        return "{$sumber->kode}~RTL{$rtlId}";
    }

    /**
     * Samakan salinan satu berkas dengan tautan RTL kegiatannya sekarang: salinan ke
     * poin RTL yang sudah tidak tertaut dibuang, salinan ke poin yang tertaut dibuat
     * bila belum ada/basi. Tidak pernah melempar exception.
     */
    public function sinkronkanBerkas(Berkas $berkas): void
    {
        $kegiatan = $berkas->ref;

        if (! $kegiatan instanceof Kegiatan) {
            return;
        }

        // Dipanggil dari dalam transaksi unggahan sumber — dibungkus savepoint sendiri
        // supaya galat database di sini (mis. bentrok unik dua permintaan bersamaan)
        // tidak ikut membatalkan berkas sumber yang sudah berhasil masuk Drive.
        try {
            DB::transaction(fn () => $this->sinkronkanDalamTransaksi($berkas, $kegiatan));
        } catch (\Throwable $e) {
            Log::warning("Sinkronisasi salinan Evaluasi-RTL berkas {$berkas->kode} gagal: {$e->getMessage()}");
        }
    }

    protected function sinkronkanDalamTransaksi(Berkas $berkas, Kegiatan $kegiatan): void
    {
        $rtlId = $kegiatan->rtl_evaluasi_id ? (int) $kegiatan->rtl_evaluasi_id : null;

        SalinanBuktiRtl::where('berkas_id', $berkas->id)
            ->when($rtlId, fn ($q) => $q->where('rtl_evaluasi_id', '!=', $rtlId))
            ->get()
            ->each(fn (SalinanBuktiRtl $basi) => $this->buangSatu($basi, keSampah: true, lemparGagal: false));

        if (! $rtlId) {
            return;
        }

        $salinan = SalinanBuktiRtl::firstOrCreate(
            ['berkas_id' => $berkas->id, 'rtl_evaluasi_id' => $rtlId],
            ['status' => SalinanBuktiRtl::STATUS_MENUNGGU],
        );

        // Sumber belum masuk Drive: cukup diantre — disalin begitu sumbernya tersusul
        // ke Drive (Berkas::sinkronUlangKeDrive() memanggil ulang method ini).
        if (! $berkas->drive_file_id || $salinan->mutakhir($berkas)) {
            return;
        }

        $this->salin($salinan, $berkas, $kegiatan);
    }

    /** sinkronkanBerkas() untuk seluruh bukti satu kegiatan (mis. tautan RTL-nya berubah). */
    public function sinkronkanKegiatan(Kegiatan $kegiatan): void
    {
        $kegiatan->loadMissing('berkas');

        foreach ($kegiatan->berkas as $berkas) {
            $this->sinkronkanBerkas($berkas->setRelation('ref', $kegiatan));
        }
    }

    /**
     * Buat/perbarui salinan fisik di Drive. true = tersalin.
     */
    public function salin(SalinanBuktiRtl $salinan, Berkas $sumber, ?Kegiatan $kegiatan = null): bool
    {
        $kegiatan ??= $sumber->ref;
        $poin = RtlEvaluasi::with('masterIku')->find($salinan->rtl_evaluasi_id);

        if (! $kegiatan instanceof Kegiatan || ! $poin || ! $sumber->drive_file_id) {
            return false;
        }

        $kegiatan->loadMissing('periode');

        // Satu baris salinan hanya diproses satu proses pada satu waktu (permintaan
        // pengguna vs perintah terjadwal) — tanpa ini keduanya bisa sama-sama belum
        // menemukan salinan berpenanda lalu sama-sama menyalin. Yang kalah cukup
        // mundur; baris tetap tercatat dan dicoba lagi oleh proses berikutnya.
        $kunci = Cache::lock("salinan-bukti-rtl:{$salinan->id}", 120);

        if (! $kunci->get()) {
            return false;
        }

        try {
            $salinan->refresh();

            if ($salinan->mutakhir($sumber)) {
                return true;
            }

            return $this->salinTerkunci($salinan, $sumber, $kegiatan, $poin);
        } finally {
            $kunci->release();
        }
    }

    protected function salinTerkunci(SalinanBuktiRtl $salinan, Berkas $sumber, Kegiatan $kegiatan, RtlEvaluasi $poin): bool
    {
        try {
            // Salinan lama dari berkas sumber versi sebelumnya — dibuang ke Sampah dulu
            // supaya folder Evaluasi-RTL tidak menyimpan dua versi berbeda.
            if ($salinan->drive_file_id && $salinan->sumber_drive_file_id !== $sumber->drive_file_id) {
                $this->drive->trashFile($salinan->drive_file_id, $salinan->storageAccount);
                $salinan->update(['drive_file_id' => null, 'sumber_drive_file_id' => null]);
            }

            $penanda = self::penanda($sumber, $poin->id);

            try {
                $sudahAda = $this->drive->cariBerkasBerpenanda(FolderStructureService::PENANDA_KODE, $penanda);
            } catch (\Throwable) {
                $sudahAda = null;
            }

            $hasil = $sudahAda
                ? ['drive_file_id' => $sudahAda['id'], 'storage_account_id' => StorageAccount::aktif()?->id]
                : $this->folder->salinBerkasKeRtl($sumber, $kegiatan, $poin, $penanda);

            $salinan->update([
                'drive_file_id' => $hasil['drive_file_id'],
                'storage_account_id' => $hasil['storage_account_id'],
                'sumber_drive_file_id' => $sumber->drive_file_id,
                'status' => SalinanBuktiRtl::STATUS_TERSALIN,
                'galat' => null,
                'percobaan' => $salinan->percobaan + 1,
                'tersalin_pada' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning("Gagal menyalin bukti {$sumber->kode} ke Evaluasi-RTL poin {$poin->id}: {$e->getMessage()}");

            $salinan->update([
                'status' => SalinanBuktiRtl::STATUS_GAGAL,
                'galat' => Str::limit($e->getMessage(), 500),
                'percobaan' => $salinan->percobaan + 1,
            ]);

            return false;
        }
    }

    /**
     * Buang seluruh salinan Evaluasi-RTL satu berkas SEBELUM berkas sumbernya dibuang
     * (Berkas::buangSalinan()). Melempar exception bila Drive gagal — pemanggil lalu
     * membatalkan penghapusan sumber, jadi tidak ada yang terhapus sebelah.
     *
     * Barisnya TIDAK dihapus di sini, hanya dikosongkan (status menunggu): bila
     * pembuangan berkas sumber sesudahnya gagal, sumber tetap ada dan salinannya
     * dibuat ulang oleh cobaLagi(); bila berhasil, baris ikut terhapus bersama baris
     * berkas sumbernya (foreign key cascade).
     */
    public function buangSalinanBerkas(Berkas $berkas, bool $keSampah = false): void
    {
        foreach (SalinanBuktiRtl::where('berkas_id', $berkas->id)->get() as $salinan) {
            if ($salinan->drive_file_id) {
                try {
                    $keSampah
                        ? $this->drive->trashFile($salinan->drive_file_id, $salinan->storageAccount)
                        : $this->drive->deleteFile($salinan->drive_file_id, $salinan->storageAccount);
                } catch (\Throwable $e) {
                    Log::warning("Gagal membuang salinan Evaluasi-RTL {$salinan->drive_file_id}: {$e->getMessage()}");

                    throw $e;
                }
            }

            $salinan->update([
                'drive_file_id' => null,
                'sumber_drive_file_id' => null,
                'status' => SalinanBuktiRtl::STATUS_MENUNGGU,
            ]);
        }
    }

    /**
     * Folder kegiatan bertahapan diganti namanya di Drive (Kegiatan::gantiNamaFolder())
     * → salinan bukti kegiatan itu dipindah ke folder tahapan bernama baru di
     * Evaluasi-RTL, supaya susunannya tetap sama dengan folder Kegiatan. Folder
     * tahapan lama yang kosong dibiarkan (folder tidak pernah dihapus otomatis).
     * Best-effort: gagal dicatat di log & kolom galat, tidak melempar.
     */
    public function pindahkanSalinanKegiatan(Kegiatan $kegiatan): void
    {
        if ($kegiatan->jenis !== 'survei_sensus' || blank($kegiatan->tahapan_survei)) {
            return;
        }

        $kegiatan->loadMissing(['berkas', 'periode']);

        foreach (SalinanBuktiRtl::whereIn('berkas_id', $kegiatan->berkas->pluck('id'))->whereNotNull('drive_file_id')->get() as $salinan) {
            $sumber = $kegiatan->berkas->firstWhere('id', $salinan->berkas_id);
            $poin = RtlEvaluasi::with('masterIku')->find($salinan->rtl_evaluasi_id);

            if (! $sumber || ! $poin || (int) $kegiatan->rtl_evaluasi_id !== (int) $poin->id) {
                continue;
            }

            try {
                $this->drive->moveFile($salinan->drive_file_id, $this->folder->folderSalinanRtl($sumber, $kegiatan, $poin));
            } catch (\Throwable $e) {
                Log::warning("Gagal memindahkan salinan Evaluasi-RTL {$salinan->drive_file_id}: {$e->getMessage()}");
                $salinan->update(['galat' => Str::limit('Gagal dipindah ke folder tahapan baru: '.$e->getMessage(), 500)]);
            }
        }
    }

    /** Ganti nama salinan mengikuti berkas sumber (best-effort, tidak melempar). */
    public function gantiNamaSalinan(Berkas $berkas, string $namaFile): void
    {
        SalinanBuktiRtl::where('berkas_id', $berkas->id)->whereNotNull('drive_file_id')->get()
            ->each(function (SalinanBuktiRtl $salinan) use ($namaFile) {
                try {
                    $this->drive->renameFile($salinan->drive_file_id, $namaFile);
                } catch (\Throwable $e) {
                    Log::warning("Gagal mengganti nama salinan Evaluasi-RTL {$salinan->drive_file_id}: {$e->getMessage()}");
                }
            });
    }

    /**
     * Coba lagi salinan yang gagal/tertunda/basi.
     *
     * @return array{berhasil: int, gagal: int}
     */
    public function cobaLagi(int $batas = 50, ?int $berkasId = null): array
    {
        $hasil = ['berhasil' => 0, 'gagal' => 0];

        $antrean = SalinanBuktiRtl::with('berkas.ref')
            ->join('berkas', 'berkas.id', '=', 'salinan_bukti_rtl.berkas_id')
            ->leftJoin('kegiatan', 'kegiatan.id', '=', 'berkas.ref_id')
            ->when($berkasId, fn ($q) => $q->where('salinan_bukti_rtl.berkas_id', $berkasId))
            ->where(fn ($q) => $q->where('salinan_bukti_rtl.status', '!=', SalinanBuktiRtl::STATUS_TERSALIN)
                ->orWhereNull('salinan_bukti_rtl.drive_file_id')
                ->orWhereNull('salinan_bukti_rtl.sumber_drive_file_id')
                ->orWhereColumn('salinan_bukti_rtl.sumber_drive_file_id', '!=', 'berkas.drive_file_id')
                // Salinan basi: kegiatannya sudah tidak tertaut ke poin ini (mis.
                // pembuangan ke Sampah saat tautan diganti sempat gagal).
                ->orWhereNull('kegiatan.rtl_evaluasi_id')
                ->orWhereColumn('kegiatan.rtl_evaluasi_id', '!=', 'salinan_bukti_rtl.rtl_evaluasi_id'))
            ->whereNotNull('berkas.drive_file_id')
            ->select('salinan_bukti_rtl.*')
            ->oldest('salinan_bukti_rtl.id')
            ->limit($batas)
            ->get();

        foreach ($antrean as $salinan) {
            $sumber = $salinan->berkas;
            $kegiatan = $sumber?->ref;

            // Kegiatan sudah tidak tertaut ke poin ini — salinannya dibuang, bukan disalin.
            if (! $kegiatan instanceof Kegiatan || (int) $kegiatan->rtl_evaluasi_id !== (int) $salinan->rtl_evaluasi_id) {
                $this->buangSatu($salinan, keSampah: true, lemparGagal: false);

                continue;
            }

            $this->salin($salinan, $sumber, $kegiatan) ? $hasil['berhasil']++ : $hasil['gagal']++;
        }

        return $hasil;
    }

    /**
     * Bukti kegiatan tertaut RTL yang belum punya baris salinan sama sekali (mis.
     * diunggah sebelum fitur ini ada). Hanya mendaftarkan barisnya (status menunggu)
     * — penyalinannya lewat cobaLagi(). Tidak mengubah berkas/kegiatan apa pun.
     *
     * @return int jumlah baris salinan baru yang didaftarkan
     */
    public function daftarkanBuktiLama(bool $jalankan, int $batas = 500): int
    {
        $sudahTerdaftar = SalinanBuktiRtl::query()->get(['berkas_id', 'rtl_evaluasi_id'])
            ->map(fn ($s) => "{$s->berkas_id}:{$s->rtl_evaluasi_id}")
            ->flip();

        $berkas = Berkas::query()
            ->where('ref_type', Kegiatan::class)
            ->whereNotNull('drive_file_id')
            ->whereIn('ref_id', Kegiatan::whereNotNull('rtl_evaluasi_id')->select('id'))
            ->with('ref')
            ->oldest('id')
            ->get()
            ->reject(fn (Berkas $b) => $sudahTerdaftar->has("{$b->id}:{$b->ref->rtl_evaluasi_id}"))
            ->take($batas);

        if ($jalankan) {
            foreach ($berkas as $b) {
                SalinanBuktiRtl::firstOrCreate(
                    ['berkas_id' => $b->id, 'rtl_evaluasi_id' => $b->ref->rtl_evaluasi_id],
                    ['status' => SalinanBuktiRtl::STATUS_MENUNGGU],
                );
            }
        }

        return $berkas->count();
    }

    protected function buangSatu(SalinanBuktiRtl $salinan, bool $keSampah, bool $lemparGagal): void
    {
        try {
            if ($salinan->drive_file_id) {
                $keSampah
                    ? $this->drive->trashFile($salinan->drive_file_id, $salinan->storageAccount)
                    : $this->drive->deleteFile($salinan->drive_file_id, $salinan->storageAccount);
            }

            $salinan->delete();
        } catch (\Throwable $e) {
            Log::warning("Gagal membuang salinan Evaluasi-RTL {$salinan->drive_file_id}: {$e->getMessage()}");

            if ($lemparGagal) {
                throw $e;
            }
        }
    }
}
