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
        // Uraian kegiatan sekarang punya alur terima/tolak sendiri oleh Tim SAKIP,
        // TERPISAH dari status_dokumen (yang menyimpulkan hasil dari bukti berkas
        // saja) — pola sama persis dengan KendalaSolusi::status_verifikasi (lihat
        // migrasi 2026_08_23_000003_tambah_status_verifikasi_ke_kendala_solusi.php).
        // Nama kolom disisipi "_uraian" supaya tidak tertukar dengan status_dokumen
        // (status keseluruhan kegiatan: draft/diajukan/diverifikasi/dst).
        Schema::table('kegiatan', function (Blueprint $table) {
            $table->enum('status_verifikasi_uraian', ['menunggu', 'terverifikasi', 'ditolak'])->default('menunggu')->after('uraian_kegiatan');
            $table->text('catatan_uraian')->nullable()->after('status_verifikasi_uraian');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kegiatan', function (Blueprint $table) {
            $table->dropColumn(['status_verifikasi_uraian', 'catatan_uraian']);
        });
    }
};
