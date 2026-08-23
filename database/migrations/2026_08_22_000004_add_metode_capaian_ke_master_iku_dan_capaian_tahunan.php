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
        // Sesuai Kertas Kerja Pengukuran Kinerja Triwulanan resmi (sheet 2): IKU
        // bertipe % diisi Tim SAKIP lewat Pembilang (X)/Penyebut (Y) mentah per
        // triwulan, bukan persentase langsung — persentasenya dihitung otomatis
        // (X÷Y×100, lihat App\Models\CapaianTahunan::alokasiKumulatif()). IKU
        // bertipe Non % tetap diisi langsung seperti sekarang ('langsung', default).
        Schema::table('master_iku', function (Blueprint $table) {
            $table->enum('metode_capaian', ['langsung', 'rasio'])->default('langsung')->after('satuan');
            $table->string('deskripsi_x')->nullable()->after('metode_capaian');
            $table->string('deskripsi_y')->nullable()->after('deskripsi_x');
        });

        Schema::table('capaian_tahunan', function (Blueprint $table) {
            foreach (['x_alokasi', 'y_alokasi', 'x_realisasi', 'y_realisasi'] as $prefix) {
                foreach ([1, 2, 3, 4] as $tw) {
                    $table->decimal("{$prefix}_tw{$tw}", 15, 2)->nullable();
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('capaian_tahunan', function (Blueprint $table) {
            foreach (['x_alokasi', 'y_alokasi', 'x_realisasi', 'y_realisasi'] as $prefix) {
                foreach ([1, 2, 3, 4] as $tw) {
                    $table->dropColumn("{$prefix}_tw{$tw}");
                }
            }
        });

        Schema::table('master_iku', function (Blueprint $table) {
            $table->dropColumn(['metode_capaian', 'deskripsi_x', 'deskripsi_y']);
        });
    }
};
