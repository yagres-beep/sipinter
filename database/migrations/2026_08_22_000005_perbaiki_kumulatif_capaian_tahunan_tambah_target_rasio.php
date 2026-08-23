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
        // Target Tahunan IKU bertipe % (MasterIku::metode_capaian 'rasio') JUGA
        // rasio X/Y — pasangan TERPISAH dari X/Y per-TW manapun (sesuai sel
        // $K12=K13/K14*100 pada sheet resmi "LK_Kabkot" — beda dari $M12..$P12
        // yang masing-masing pakai pasangan X/Y TW-nya sendiri). Lihat
        // App\Models\CapaianTahunan::targetTahunan().
        Schema::table('capaian_tahunan', function (Blueprint $table) {
            $table->decimal('x_target', 15, 2)->nullable()->after('target_tahunan');
            $table->decimal('y_target', 15, 2)->nullable()->after('x_target');
        });

        $this->koreksiBarisTersimpan();
    }

    /**
     * Migration 2026_08_22_000003 membackfill capaian_tahunan dari `capaian` lama
     * pakai formula SALAH (alokasi_twN = delta antar-triwulan, realisasi_twN = jumlah
     * per-triwulan saja) — asumsi keliru bahwa kolom TW itu nilai MENTAH per-triwulan.
     * Audit ulang rumus MENTAH sheet resmi ("LK_Kabkot") membuktikan kolom TW
     * sebenarnya sudah berisi nilai KUMULATIF SAMPAI TRIWULAN ITU, dibaca APA ADANYA
     * (lihat App\Models\CapaianTahunan) — migration ini mengoreksi ULANG baris yang
     * sudah terlanjur di-backfill salah, dihitung ulang dari `capaian`+`periode`
     * (masih utuh, tidak pernah dihapus):
     * - alokasi_twN = MAX(target_tw) pada triwulan N SAJA (LANGSUNG, tanpa dikurangi
     *   triwulan sebelumnya — beda dari migration lama yang menghitung delta).
     * - realisasi_twN = SUM(realisasi) dari SELURUH triwulan 1 s.d. N (running sum
     *   lintas-triwulan, supaya jadi snapshot kumulatif yang benar — `realisasi` lama
     *   per-bulan memang bukan kumulatif, jadi penjumlahan lintas-triwulan di SINI,
     *   sekali saat konversi data lama, tetap perlu — beda dari rumus tampilan
     *   berjalan yang sudah tidak menjumlah apa pun lagi).
     */
    private function koreksiBarisTersimpan(): void
    {
        $baris = DB::table('capaian_tahunan')->get(['id', 'iku_id', 'tahun']);

        foreach ($baris as $row) {
            $capaianRows = DB::table('capaian')
                ->join('periode', 'capaian.periode_id', '=', 'periode.id')
                ->where('capaian.iku_id', $row->iku_id)
                ->where('periode.tahun', $row->tahun)
                ->select('periode.triwulan', 'capaian.target_tw', 'capaian.realisasi')
                ->get()
                ->groupBy('triwulan');

            $update = [];
            $realisasiBerjalan = 0.0;
            $realisasiAda = false;

            foreach ([1, 2, 3, 4] as $tw) {
                $g = $capaianRows->get($tw);

                if ($g) {
                    $update["alokasi_tw{$tw}"] = round((float) ($g->pluck('target_tw')->filter()->max() ?? 0), 2);

                    $realisasiTerisi = $g->pluck('realisasi')->filter();

                    if ($realisasiTerisi->isNotEmpty()) {
                        $realisasiBerjalan += (float) $realisasiTerisi->sum();
                        $realisasiAda = true;
                    }

                    $update["realisasi_tw{$tw}"] = $realisasiAda ? round($realisasiBerjalan, 2) : null;
                } else {
                    $update["alokasi_tw{$tw}"] = null;
                    $update["realisasi_tw{$tw}"] = null;
                }
            }

            DB::table('capaian_tahunan')->where('id', $row->id)->update($update);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Nilai alokasi_tw*/realisasi_tw* yang dikoreksi TIDAK dikembalikan ke versi
        // salah sebelumnya (tidak ada gunanya) — cukup hapus kolom baru.
        Schema::table('capaian_tahunan', function (Blueprint $table) {
            $table->dropColumn(['x_target', 'y_target']);
        });
    }
};
