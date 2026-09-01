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
        // Sama seperti Kegiatan::status_dokumen — RTL Baru (rencana untuk triwulan
        // berikutnya) sekarang bisa tersimpan sebagai draft (lihat
        // PengisianKegiatan::simpanBagianIsian()) SEBELUM benar-benar diajukan ke Tim
        // SAKIP. Tanpa kolom ini, begitu satu baris tersimpan (draft ATAU diajukan),
        // rtlTriwulanBerikutnyaSudahAda() sama-sama menganggapnya "sudah ditetapkan"
        // dan langsung mengunci Bagian 5 jadi hanya-baca — padahal isian yang lain
        // (Kegiatan, Kendala & Solusi) masih bisa diedit bebas selama belum diajukan.
        //
        // default 'diajukan' SENGAJA dipakai (bukan 'draft') supaya seluruh baris LAMA
        // yang sudah ada sebelum migrasi ini (semuanya dibuat lewat alur ajukanIsian()
        // yang lama, jadi sudah pasti benar-benar diajukan) tetap dianggap final/hanya-
        // baca seperti sebelumnya — tidak ada backfill terpisah yang diperlukan.
        Schema::table('rtl_evaluasi', function (Blueprint $table) {
            $table->enum('status_dokumen', ['draft', 'diajukan'])->default('diajukan')->after('batas_waktu');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rtl_evaluasi', function (Blueprint $table) {
            $table->dropColumn('status_dokumen');
        });
    }
};
