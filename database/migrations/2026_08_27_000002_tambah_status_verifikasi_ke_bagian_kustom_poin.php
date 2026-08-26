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
        // Sama seperti KendalaSolusi::status_verifikasi — sebelumnya hanya bukti
        // dukung (berkas) poin Bagian Kustom yang diverifikasi, teks poinnya sendiri
        // tidak. Sekarang Tim SAKIP juga menandai sesuai/tidak sesuai teksnya.
        Schema::table('bagian_kustom_poin', function (Blueprint $table) {
            $table->enum('status_verifikasi', ['menunggu', 'terverifikasi', 'ditolak'])->default('menunggu')->after('teks');
            $table->text('catatan')->nullable()->after('status_verifikasi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bagian_kustom_poin', function (Blueprint $table) {
            $table->dropColumn(['status_verifikasi', 'catatan']);
        });
    }
};
