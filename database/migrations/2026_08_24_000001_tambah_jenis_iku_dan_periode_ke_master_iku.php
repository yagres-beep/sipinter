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
        // Klasifikasi tambahan dari Kertas Kerja Pengukuran Kinerja Triwulanan resmi
        // (kolom "Jenis (IKU atau Proksi)" & "Jenis (Triwulanan atau Tahunan)"), belum
        // tersimpan sama sekali sebelum ini — semua IKU diperlakukan sama (Tahunan) di
        // App\Livewire\DasborCapaian::hitungPko(). jenis_periode dipakai untuk memilih
        // basis Normalisasi Capaian PK: 'triwulanan' → Capaian Terhadap Target
        // Triwulanan pada triwulan berjalan, 'tahunan' → Capaian Setahun TW IV (lihat
        // App\Models\MasterIku::pakaiTriwulanan()).
        Schema::table('master_iku', function (Blueprint $table) {
            $table->enum('jenis_iku', ['iku', 'proksi'])->default('iku')->after('metode_capaian');
            $table->enum('jenis_periode', ['triwulanan', 'tahunan'])->default('tahunan')->after('jenis_iku');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->dropColumn(['jenis_iku', 'jenis_periode']);
        });
    }
};
