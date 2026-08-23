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
        // Batas normalisasi Capaian PK pada rumus Penilaian Kinerja Organisasi (PKO,
        // sheet resmi "LK_Kabkot" kolom AJ) — 110, TERPISAH dari batas_maksimal_persen
        // (120) yang dipakai Capaian::hitungPersentase() untuk rumus Capaian %
        // biasa. Lihat App\Models\Capaian::normalisasiCapaianPk().
        Schema::table('pengaturan_capaian', function (Blueprint $table) {
            $table->decimal('batas_normalisasi_pko', 15, 2)->default(110);
        });

        // Nilai SAKIP (dari Inspektorat) — satu angka per tahun untuk SELURUH
        // organisasi (bukan per-IKU), menentukan Predikat SAKIP -> % koreksi pada
        // rumus PKO. Lihat App\Models\NilaiSakip, App\Models\Capaian::predikatSakip().
        Schema::create('nilai_sakip', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('tahun')->unique();
            $table->decimal('nilai', 5, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nilai_sakip');

        Schema::table('pengaturan_capaian', function (Blueprint $table) {
            $table->dropColumn('batas_normalisasi_pko');
        });
    }
};
