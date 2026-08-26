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
        // Sama seperti KendalaSolusi::status_verifikasi — sebelumnya realisasi RTL
        // triwulan sebelumnya hanya dibandingkan visual tanpa status verifikasi.
        // Kolom ini hanya relevan bila realisasi sudah dilaporkan Ketua Tim (realisasi
        // tidak kosong) — lihat App\Livewire\VerifikasiCapaian::rtlBisaDiverifikasi().
        Schema::table('rtl_evaluasi', function (Blueprint $table) {
            $table->enum('status_verifikasi', ['menunggu', 'terverifikasi', 'ditolak'])->default('menunggu')->after('realisasi');
            $table->text('catatan')->nullable()->after('status_verifikasi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rtl_evaluasi', function (Blueprint $table) {
            $table->dropColumn(['status_verifikasi', 'catatan']);
        });
    }
};
