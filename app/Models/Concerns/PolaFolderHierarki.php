<?php

namespace App\Models\Concerns;

use App\Models\FolderConfig;
use Illuminate\Support\Collection;

/**
 * Logika baca pola_json (bentuk {hierarki:[...], kategori:[...]}) yang SAMA dipakai
 * oleh FolderConfig (pola global) maupun IkuFolderConfig (pola override per-IKU) —
 * lihat FolderStructureService::configUntukIku() untuk cara keduanya dipertukarkan.
 */
trait PolaFolderHierarki
{
    /**
     * Urutan level hierarki yang AKTIF (RF-11), siap dipakai FolderStructureService.
     * "tahun" dipaksa selalu ada & selalu di posisi pertama.
     *
     * @return list<string> mis. ['tahun', 'triwulan', 'bulan', 'iku']
     */
    public function hierarkiAktif(): array
    {
        $sisanya = collect($this->pola_json['hierarki'] ?? [])
            ->where('aktif', true)
            ->pluck('level')
            ->reject(fn ($level) => $level === FolderConfig::LEVEL_TAHUN)
            ->values()
            ->all();

        return [FolderConfig::LEVEL_TAHUN, ...$sisanya];
    }

    /**
     * @return list<array{nama: string, wajib: bool, subfolder_per_kegiatan: bool}>
     */
    public function kategoriList(): array
    {
        return $this->pola_json['kategori'] ?? [];
    }

    /**
     * Nama kategori yang dikonfigurasi untuk punya subfolder per kegiatan (RF-13).
     */
    public function kategoriDenganSubfolderKegiatan(): array
    {
        return collect($this->kategoriList())
            ->where('subfolder_per_kegiatan', true)
            ->pluck('nama')
            ->all();
    }
}
