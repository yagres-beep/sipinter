<?php

namespace App\Support;

use App\Models\Berkas;
use App\Models\SalinanBuktiRtl;
use App\Services\GoogleDriveService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Pemeriksaan konsistensi bukti dukung antara tabel `berkas`, disk lokal server, dan
 * Google Drive. SEMUA metode di sini HANYA MEMBACA — tidak ada yang mengubah database,
 * berkas lokal, maupun Drive. Pembersihan (bila perlu) ada di perintah terpisah
 * `bukti:bersihkan-lokal-yatim`, yang memeriksa ulang rujukan sebelum menghapus.
 */
class KonsistensiBukti
{
    /** Folder disk lokal tempat bukti dukung disimpan sementara sebelum/selain Drive. */
    public const FOLDER_BUKTI = ['bukti-capaian', 'bukti-evaluasi-rtl', 'bukti-bagian-kustom'];

    /**
     * Baris berkas yang belum punya salinan Drive DAN salinan lokalnya sudah tidak ada
     * — tidak bisa dipulihkan dari server; perlu diunggah ulang pengguna.
     *
     * @return array<int, array{kode: string, nama_file: string, kategori: string, dibuat: string}>
     */
    public function tanpaSalinan(): array
    {
        $hasil = [];

        Berkas::whereNull('drive_file_id')->orderBy('id')->chunk(500, function ($daftar) use (&$hasil) {
            foreach ($daftar as $berkas) {
                if ($berkas->path && Storage::disk('local')->exists($berkas->path)) {
                    continue;
                }

                $hasil[] = $this->ringkas($berkas);
            }
        });

        return $hasil;
    }

    /**
     * Berkas yang masih menunggu disusulkan ke Drive (salinan lokal masih ada).
     *
     * @return array<int, array{kode: string, nama_file: string, kategori: string, dibuat: string}>
     */
    public function menungguSinkron(): array
    {
        $hasil = [];

        Berkas::belumDiDrive()->orderBy('id')->chunk(500, function ($daftar) use (&$hasil) {
            foreach ($daftar as $berkas) {
                if (Storage::disk('local')->exists($berkas->path)) {
                    $hasil[] = $this->ringkas($berkas);
                }
            }
        });

        return $hasil;
    }

    /**
     * Baris berkas yang pemiliknya (kegiatan / poin / RTL) sudah tidak ada.
     *
     * @return array<int, array{kode: string, nama_file: string, kategori: string, dibuat: string}>
     */
    public function rujukanHilang(): array
    {
        $hasil = [];

        foreach (Berkas::query()->distinct()->pluck('ref_type')->filter() as $tipe) {
            if (! class_exists($tipe) || ! is_subclass_of($tipe, Model::class)) {
                foreach (Berkas::where('ref_type', $tipe)->get() as $berkas) {
                    $hasil[] = $this->ringkas($berkas);
                }

                continue;
            }

            /** @var Model $model */
            $model = new $tipe;

            Berkas::where('ref_type', $tipe)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')
                    ->from($model->getTable())
                    ->whereColumn($model->getTable().'.'.$model->getKeyName(), 'berkas.ref_id'))
                ->orderBy('id')
                ->each(function (Berkas $berkas) use (&$hasil) {
                    $hasil[] = $this->ringkas($berkas);
                });
        }

        return $hasil;
    }

    /**
     * Berkas lokal di folder bukti yang tidak dirujuk baris `berkas` mana pun.
     *
     * @return array<int, array{path: string, ukuran: int, diubah: Carbon}>
     */
    public function lokalYatim(): array
    {
        $disk = Storage::disk('local');
        $hasil = [];

        foreach (self::FOLDER_BUKTI as $folder) {
            if (! $disk->exists($folder)) {
                continue;
            }

            foreach (array_chunk($disk->allFiles($folder), 500) as $potongan) {
                $dirujuk = Berkas::whereIn('path', $potongan)->pluck('path')->flip();

                foreach ($potongan as $path) {
                    if ($dirujuk->has($path)) {
                        continue;
                    }

                    $hasil[] = [
                        'path' => $path,
                        'ukuran' => (int) $disk->size($path),
                        'diubah' => Carbon::createFromTimestamp($disk->lastModified($path)),
                    ];
                }
            }
        }

        return $hasil;
    }

    /**
     * Berkas yang tercatat punya salinan Drive tetapi berkasnya tidak ditemukan atau
     * sudah dibuang ke tempat sampah Drive. Dibatasi $batas berkas terbaru (satu
     * panggilan API per berkas).
     *
     * @return array<int, array{kode: string, nama_file: string, kategori: string, dibuat: string}>
     */
    public function salinanDriveHilang(GoogleDriveService $drive, int $batas): array
    {
        $hasil = [];

        foreach (Berkas::whereNotNull('drive_file_id')->latest('id')->limit($batas)->get() as $berkas) {
            if ($drive->infoBerkas($berkas->drive_file_id) === null) {
                $hasil[] = $this->ringkas($berkas);
            }
        }

        return $hasil;
    }

    /**
     * Berkas di Drive yang ditandai SIPINTER (appProperties sipinter=1 + sipinter_kode) tetapi
     * tidak dirujuk baris `berkas` mana pun dengan id Drive yang sama — mis. unggahan
     * yang berhasil di Drive tetapi pencatatannya di database gagal, atau salinan
     * ganda. Berkas unggahan SEBELUM penanda ini dipasang tidak bisa dideteksi di sini.
     *
     * @return array<int, array{drive_file_id: string, nama: string, kode: string, alasan: string}>
     */
    public function driveYatim(GoogleDriveService $drive, int $batas): array
    {
        $hasil = [];

        foreach ($drive->daftarBerkasBertanda($batas) as $file) {
            $kode = (string) ($file['kode'] ?? '');

            // Salinan bukti Kegiatan di folder Evaluasi-RTL (penanda "<kode>~RTL<id>",
            // lihat SinkronBuktiRtlService::penanda()) dirujuk tabel salinan_bukti_rtl.
            if (SalinanBuktiRtl::where('drive_file_id', $file['id'])->exists()) {
                continue;
            }

            if (str_contains($kode, '~RTL')) {
                $hasil[] = ['drive_file_id' => $file['id'], 'nama' => $file['nama'], 'kode' => $kode, 'alasan' => 'salinan Evaluasi-RTL yang tidak tercatat di database'];

                continue;
            }

            $berkas = $kode !== '' ? Berkas::where('kode', $kode)->first() : null;

            if ($berkas && $berkas->drive_file_id === $file['id']) {
                continue;
            }

            $hasil[] = [
                'drive_file_id' => $file['id'],
                'nama' => $file['nama'],
                'kode' => $kode,
                'alasan' => match (true) {
                    $berkas === null => 'kode berkas tidak ada di database',
                    $berkas->drive_file_id === null => 'database belum mencatat salinan Drive ini (aman dipakai ulang oleh sinkron)',
                    default => 'salinan ganda — database merujuk berkas Drive lain',
                },
            ];
        }

        return $hasil;
    }

    /**
     * @return array{kode: string, nama_file: string, kategori: string, dibuat: string}
     */
    protected function ringkas(Berkas $berkas): array
    {
        return [
            'kode' => (string) $berkas->kode,
            'nama_file' => (string) $berkas->nama_file,
            'kategori' => (string) $berkas->kategori,
            'dibuat' => (string) $berkas->created_at?->wita()->format('Y-m-d H:i'),
        ];
    }
}
