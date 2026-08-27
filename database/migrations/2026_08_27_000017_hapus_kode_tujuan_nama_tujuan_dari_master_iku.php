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
        // Kode/Nama Tujuan dihapus atas permintaan RF: dianggap duplikat konsep
        // dengan Sasaran (yang sudah dipakai untuk pengelompokan "Kesiapan per
        // Sasaran" di Kompilasi Notula & Dasbor Capaian) dan tidak dipakai di
        // tempat lain. kode_sasaran TIDAK ikut dihapus.
        Schema::table('master_iku', function (Blueprint $table) {
            $table->dropColumn(['kode_tujuan', 'nama_tujuan']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->string('kode_tujuan')->nullable()->after('kode');
            $table->string('nama_tujuan')->nullable()->after('kode_tujuan');
        });
    }
};
