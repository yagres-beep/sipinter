<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catat pengguna terakhir yang mengubah satu rincian n/N (uraian, status
 * realisasi, atau pelepasan dari target). Riwayat lengkapnya ada di log_aktivitas.
 *
 * Aditif: kolom baru nullable, baris lama tetap null (pengubah sebelumnya memang
 * tidak tercatat) -- tidak ada nilai lama yang diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rincian_n', function (Blueprint $table) {
            $table->foreignId('diubah_oleh')->nullable()->after('dibuat_oleh')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rincian_n', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diubah_oleh');
        });
    }
};
