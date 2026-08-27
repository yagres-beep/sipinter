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
        // Penanggung Jawab (nama orang, isian bebas) dihapus atas permintaan RF —
        // tidak pernah dibaca di luar form/tabel Master IKU sendiri; PIC sesungguhnya
        // (reminder, penugasan, dsb.) sudah dihitung otomatis dari keanggotaan Tim,
        // lihat MasterIku::semuaPenanggungJawab(). Tabel Daftar Master IKU cukup
        // menampilkan Tim sebagai penanggung jawab.
        Schema::table('master_iku', function (Blueprint $table) {
            $table->dropColumn('penanggung_jawab');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->string('penanggung_jawab')->nullable()->after('tim');
        });
    }
};
