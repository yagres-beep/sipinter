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
        // Status alur verifikasi RESMI satu Capaian (satu IKU+bulan) — SUMBER KEBENARAN
        // TUNGGAL yang ditampilkan di dasbor/daftar/detail verifikasi, menggantikan cara
        // lama yang menyimpulkan status dari kumpulan status_dokumen Kegiatan di bawahnya
        // (bisa campur-aduk kalau kegiatan diajukan bertahap, lihat App\Models\Capaian).
        // Kegiatan tetap punya status_dokumen SENDIRI (dipakai untuk kunci-edit per baris
        // di form Ketua Tim), tapi status yang DITAMPILKAN ke pengguna selalu satu ini.
        Schema::table('capaian', function (Blueprint $table) {
            $table->enum('status', ['draft', 'diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'])
                ->default('draft')
                ->after('periode_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('capaian', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
