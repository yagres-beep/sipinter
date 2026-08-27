<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Kolom "Jenis (IKU atau Proksi)" dihapus atas permintaan RF: seluruh
        // indikator kini diperlakukan setara (dihitung penuh) — tidak ada lagi
        // kategori "Proksi" yang dikecualikan dari Capaian Kinerja/Dasbor Capaian.
        // jenis_periode TIDAK ikut dihapus (masih dipakai, lihat MasterIku::pakaiTriwulanan()).
        Schema::table('master_iku', function (Blueprint $table) {
            $table->dropColumn('jenis_iku');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->enum('jenis_iku', ['iku', 'proksi'])->default('iku')->after('metode_capaian');
        });
    }
};
