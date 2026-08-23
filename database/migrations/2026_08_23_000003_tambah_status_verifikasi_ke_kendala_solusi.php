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
        // Kendala & Solusi sekarang punya alur terima/tolak sendiri oleh Tim SAKIP,
        // sama persis polanya dengan Berkas::status_verifikasi (lihat migrasi
        // 2026_01_01_000010_create_berkas_table.php) — "menunggu" (baru diajukan,
        // belum diperiksa), "terverifikasi" (diterima, terkunci di sisi Ketua Tim),
        // "ditolak" (dikembalikan berikut catatan, Ketua Tim boleh mengedit ulang
        // pasangan ini). Lihat App\Livewire\VerifikasiCapaian::tandaiKendalaSesuai()/
        // tandaiKendalaTolak(), App\Livewire\PengisianKegiatan::muatKendalaBlocks().
        Schema::table('kendala_solusi', function (Blueprint $table) {
            $table->enum('status_verifikasi', ['menunggu', 'terverifikasi', 'ditolak'])->default('menunggu')->after('solusi');
            $table->text('catatan')->nullable()->after('status_verifikasi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kendala_solusi', function (Blueprint $table) {
            $table->dropColumn(['status_verifikasi', 'catatan']);
        });
    }
};
