<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rumus capaian kustom, khusus IKU metode_capaian 'langsung' (Non %) -- kosong
 * (default) berarti tetap memakai rumus baku Capaian::hitungPersentase()
 * (realisasi÷alokasi×100, dibatasi batas_maksimal_persen) seperti sekarang, sama
 * sekali tidak mengubah IKU yang sudah ada. Diisi Tim SAKIP lewat Master IKU --
 * lihat App\Services\FormulaCapaianService &amp; App\Models\CapaianTahunan::
 * capaianTriwulanan()/capaianSetahun().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->text('formula_capaian')->nullable()->after('pakai_rincian_n');
        });
    }

    public function down(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->dropColumn('formula_capaian');
        });
    }
};
