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
        Schema::table('kegiatan', function (Blueprint $table) {
            // Menautkan kegiatan ke poin RTL triwulan berjalan yang "dilaksanakan" olehnya
            // (dipilih lewat dropdown uraian kegiatan) — dipakai untuk mengecek RTL mana
            // yang belum terlaksana sebelum triwulan boleh diajukan ke Tim SAKIP.
            $table->foreignId('rtl_evaluasi_id')->nullable()->after('iku_id')
                ->constrained('rtl_evaluasi')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kegiatan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rtl_evaluasi_id');
        });
    }
};
