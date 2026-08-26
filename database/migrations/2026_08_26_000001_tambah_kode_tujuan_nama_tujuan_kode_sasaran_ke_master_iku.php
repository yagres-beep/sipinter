<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom hierarki Tujuan/Sasaran untuk fitur import Master IKU dari Excel —
     * `nama_sasaran` SENGAJA TIDAK dibuat kolom baru, memakai kolom `sasaran` yang
     * sudah ada (dipakai luas: form, dropdown, pengelompokan Dasbor Capaian).
     */
    public function up(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->string('kode_tujuan')->nullable()->after('kode');
            $table->string('nama_tujuan')->nullable()->after('kode_tujuan');
            $table->string('kode_sasaran')->nullable()->after('nama_tujuan');
        });
    }

    public function down(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->dropColumn(['kode_tujuan', 'nama_tujuan', 'kode_sasaran']);
        });
    }
};
