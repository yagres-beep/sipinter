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
        // Menyimpan catatan penolakan TERAKHIR milik bukti yang sudah dihapus Ketua Tim
        // (lihat App\Livewire\PengisianKegiatan::hapusBuktiLama() dkk.) — begitu bukti
        // "Tidak Sesuai" dihapus, DB row-nya (beserta catatan aslinya) hilang, jadi Tim
        // SAKIP tidak akan tahu lagi APA yang tadinya salah saat memeriksa bukti
        // pengganti. Kolom ini jadi pengingat pengganti yang menempel di Kegiatan/poin
        // itu sendiri (bukan di berkas), tampil ke Ketua Tim MAUPUN Tim SAKIP sampai
        // ditandai "Sesuai" lagi (dikosongkan otomatis saat itu terjadi).
        Schema::table('kegiatan', function (Blueprint $table) {
            $table->text('catatan_bukti_dihapus')->nullable()->after('catatan_uraian');
        });

        Schema::table('rtl_evaluasi', function (Blueprint $table) {
            $table->text('catatan_bukti_dihapus')->nullable()->after('catatan');
        });

        Schema::table('bagian_kustom_poin', function (Blueprint $table) {
            $table->text('catatan_bukti_dihapus')->nullable()->after('catatan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kegiatan', function (Blueprint $table) {
            $table->dropColumn('catatan_bukti_dihapus');
        });

        Schema::table('rtl_evaluasi', function (Blueprint $table) {
            $table->dropColumn('catatan_bukti_dihapus');
        });

        Schema::table('bagian_kustom_poin', function (Blueprint $table) {
            $table->dropColumn('catatan_bukti_dihapus');
        });
    }
};
