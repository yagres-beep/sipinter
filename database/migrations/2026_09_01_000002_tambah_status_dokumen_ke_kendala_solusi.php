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
        // Sama seperti RtlEvaluasi::status_dokumen (lihat migrasi
        // 2026_09_01_000001_tambah_status_dokumen_ke_rtl_evaluasi.php) — Kendala &
        // Solusi sekarang bisa tersimpan sebagai draft (lihat PengisianKegiatan::
        // simpanBagianIsian()) SEBELUM benar-benar diajukan ke Tim SAKIP, dan begitu
        // sudah diajukan, pasangannya terkunci hanya-baca di form Ketua Tim (lihat
        // PengisianKegiatan::muatKendalaBlocks()/kendalaAktif()) sampai Tim SAKIP
        // menandainya Sesuai/Tidak Sesuai — sebelumnya kolom ini tidak ada sama
        // sekali, jadi pasangan yang sudah diajukan (status_verifikasi masih
        // "menunggu") tetap bisa diedit bebas oleh Ketua Tim sampai benar-benar
        // diputuskan Tim SAKIP.
        //
        // default 'diajukan' SENGAJA dipakai (bukan 'draft') supaya seluruh baris LAMA
        // yang sudah ada sebelum migrasi ini (semuanya dibuat lewat alur ajukanIsian()
        // yang lama, jadi sudah pasti benar-benar diajukan) tetap dianggap final/hanya-
        // baca seperti sebelumnya — tidak ada backfill terpisah yang diperlukan.
        Schema::table('kendala_solusi', function (Blueprint $table) {
            $table->enum('status_dokumen', ['draft', 'diajukan'])->default('diajukan')->after('solusi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kendala_solusi', function (Blueprint $table) {
            $table->dropColumn('status_dokumen');
        });
    }
};
