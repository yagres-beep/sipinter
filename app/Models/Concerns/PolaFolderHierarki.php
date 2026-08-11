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
     * "tahun" dipaksa selalu ada & selalu di posisi pertama. "bulan" TIDAK PERNAH
     * muncul di sini (walau ada di data lama) — folder Bulan sekarang jadi SUBFOLDER
     * di dalam kategori tertentu (lihat kategoriDenganSubfolderBulan()), bukan lagi
     * tingkat sendiri yang berlaku sama rata untuk semua kategori.
     *
     * @return list<string> mis. ['tahun', 'triwulan', 'iku']
     */
    public function hierarkiAktif(): array
    {
        $sisanya = collect($this->pola_json['hierarki'] ?? [])
            ->where('aktif', true)
            ->pluck('level')
            ->reject(fn ($level) => in_array($level, [FolderConfig::LEVEL_TAHUN, FolderConfig::LEVEL_BULAN], true))
            ->values()
            ->all();

        return [FolderConfig::LEVEL_TAHUN, ...$sisanya];
    }

    /**
     * @return list<array{nama: string, wajib: bool, subfolder_per_kegiatan: bool, subfolder_per_bulan: bool}>
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

    /**
     * Nama kategori yang dikonfigurasi punya subfolder Bulan di dalamnya (mis.
     * "Capaian", "Bukti-Dukung-SAKIP") — kategori lain (mis. "Evaluasi-RTL") langsung
     * menampung berkas tanpa dipecah per bulan. Tim SAKIP mengatur ini per-kategori
     * lewat menu Struktur Folder, sejajar dengan "subfolder per kegiatan".
     */
    public function kategoriDenganSubfolderBulan(): array
    {
        return collect($this->kategoriList())
            ->where('subfolder_per_bulan', true)
            ->pluck('nama')
            ->all();
    }
}
