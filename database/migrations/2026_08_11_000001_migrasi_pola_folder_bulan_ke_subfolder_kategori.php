<?php

use App\Models\FolderConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill data pola_json LAMA (folder Bulan sebagai tingkat hierarki sendiri) ke
     * bentuk BARU (folder Bulan jadi subfolder di dalam kategori tertentu saja) —
     * lihat App\Models\Concerns\PolaFolderHierarki. Kategori yang sebelumnya berada
     * "di bawah" tingkat Bulan yang aktif diberi subfolder_per_bulan=true supaya jalur
     * folder yang sudah dibuat di Drive sebelumnya tetap konsisten dilanjutkan.
     */
    public function up(): void
    {
        foreach (['folder_config', 'iku_folder_config'] as $table) {
            DB::table($table)->orderBy('id')->get(['id', 'pola_json'])->each(function ($baris) use ($table) {
                $pola = json_decode($baris->pola_json, true);

                if (! is_array($pola)) {
                    return;
                }

                DB::table($table)->where('id', $baris->id)->update([
                    'pola_json' => json_encode($this->migrasikanPola($pola)),
                ]);
            });
        }
    }

    protected function migrasikanPola(array $pola): array
    {
        $hierarki = $pola['hierarki'] ?? [];

        $bulanAktifSebelumnya = collect($hierarki)
            ->contains(fn ($h) => ($h['level'] ?? null) === FolderConfig::LEVEL_BULAN && ($h['aktif'] ?? false));

        $pola['hierarki'] = collect($hierarki)
            ->reject(fn ($h) => ($h['level'] ?? null) === FolderConfig::LEVEL_BULAN)
            ->values()
            ->all();

        $pola['kategori'] = collect($pola['kategori'] ?? [])
            ->map(function ($k) use ($bulanAktifSebelumnya) {
                $k['subfolder_per_bulan'] = $bulanAktifSebelumnya;

                return $k;
            })
            ->all();

        return $pola;
    }

    public function down(): void
    {
        // Tidak dibalik — perubahan bentuk pola_json bersifat maju saja (bulan sebagai
        // subfolder kategori adalah desain final), sama seperti migrasi backfill lain.
    }
};
