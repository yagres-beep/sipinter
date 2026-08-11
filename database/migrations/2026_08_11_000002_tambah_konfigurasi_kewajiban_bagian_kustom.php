<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ganti wajib_akhir_triwulan (boolean tunggal) dengan pengaturan yang lebih
     * granular per bagian kustom: SEBERAPA SERING wajib diisi (frekuensi_wajib —
     * opsional/setiap bulan/akhir triwulan saja), dan APAKAH bukti dukung wajib
     * dilampirkan per poin (bukti_wajib — sebelumnya selalu wajib tanpa terkecuali).
     */
    public function up(): void
    {
        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->string('frekuensi_wajib')->default('opsional')->after('deskripsi');
            $table->boolean('bukti_wajib')->default(true)->after('frekuensi_wajib');
        });

        DB::table('bagian_kustom')->where('wajib_akhir_triwulan', true)->update(['frekuensi_wajib' => 'akhir_triwulan']);

        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->dropColumn('wajib_akhir_triwulan');
        });
    }

    public function down(): void
    {
        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->boolean('wajib_akhir_triwulan')->default(false);
        });

        DB::table('bagian_kustom')->where('frekuensi_wajib', 'akhir_triwulan')->update(['wajib_akhir_triwulan' => true]);

        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->dropColumn(['frekuensi_wajib', 'bukti_wajib']);
        });
    }
};
