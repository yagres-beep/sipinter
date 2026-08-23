<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Target Tahunan + Alokasi/Realisasi Triwulanan SATU nilai per (iku_id, tahun) —
        // menggantikan target_pk/target_tw/realisasi di tabel `capaian` yang sebelumnya
        // harus diketik ulang Tim SAKIP di SETIAP bulan (12x/tahun) padahal nilainya
        // konstan per tahun/triwulan (lihat App\Models\CapaianTahunan). Kolom lama di
        // `capaian` SENGAJA tidak dihapus — dibiarkan sebagai arsip, aplikasi berhenti
        // membacanya lewat jalur ini.
        Schema::create('capaian_tahunan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iku_id')->constrained('master_iku')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedSmallInteger('tahun');
            $table->decimal('target_tahunan', 15, 2)->nullable();
            $table->decimal('alokasi_tw1', 15, 2)->nullable();
            $table->decimal('alokasi_tw2', 15, 2)->nullable();
            $table->decimal('alokasi_tw3', 15, 2)->nullable();
            $table->decimal('alokasi_tw4', 15, 2)->nullable();
            $table->decimal('realisasi_tw1', 15, 2)->nullable();
            $table->decimal('realisasi_tw2', 15, 2)->nullable();
            $table->decimal('realisasi_tw3', 15, 2)->nullable();
            $table->decimal('realisasi_tw4', 15, 2)->nullable();
            $table->timestamps();

            $table->unique(['iku_id', 'tahun']);
        });

        $this->backfillDariCapaianLama();
    }

    /**
     * Isi capaian_tahunan dari data `capaian` (per bulan) yang sudah ada di produksi,
     * supaya angka yang sudah pernah diketik Tim SAKIP tidak hilang/reset ke kosong:
     * - target_tahunan  = nilai target_pk TERBESAR pada tahun itu (dulu diketik ulang
     *   tiap bulan, seharusnya konstan).
     * - alokasi_twN      = selisih target_tw (kumulatif) triwulan N dengan triwulan
     *   N-1 — karena target_tw lama disimpan sebagai NILAI KUMULATIF yang diketik
     *   berulang tiap bulan dalam triwulan yang sama.
     * - realisasi_twN    = jumlah realisasi seluruh baris `capaian` pada triwulan N
     *   (realisasi lama memang nilai per-bulan, bukan kumulatif).
     */
    private function backfillDariCapaianLama(): void
    {
        $rows = DB::table('capaian')
            ->join('periode', 'capaian.periode_id', '=', 'periode.id')
            ->select('capaian.iku_id', 'periode.tahun', 'periode.triwulan', 'capaian.target_pk', 'capaian.target_tw', 'capaian.realisasi')
            ->get()
            ->groupBy(fn ($r) => $r->iku_id.'-'.$r->tahun);

        $now = now();

        foreach ($rows as $key => $group) {
            [$ikuId, $tahun] = explode('-', $key, 2);

            $targetTahunan = $group->pluck('target_pk')->filter()->max();

            $perTriwulan = $group->groupBy('triwulan');
            $baris = ['iku_id' => (int) $ikuId, 'tahun' => (int) $tahun, 'target_tahunan' => $targetTahunan, 'created_at' => $now, 'updated_at' => $now];

            $prevCum = 0.0;
            foreach ([1, 2, 3, 4] as $tw) {
                $g = $perTriwulan->get($tw);

                if ($g) {
                    $cum = (float) ($g->pluck('target_tw')->filter()->max() ?? 0);
                    $baris["alokasi_tw{$tw}"] = round(max($cum - $prevCum, 0), 2);
                    $prevCum = max($cum, $prevCum);

                    $realisasiTerisi = $g->pluck('realisasi')->filter();
                    $baris["realisasi_tw{$tw}"] = $realisasiTerisi->isEmpty() ? null : round((float) $realisasiTerisi->sum(), 2);
                } else {
                    $baris["alokasi_tw{$tw}"] = null;
                    $baris["realisasi_tw{$tw}"] = null;
                }
            }

            DB::table('capaian_tahunan')->insert($baris);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capaian_tahunan');
    }
};
