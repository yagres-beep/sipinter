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
        // Nama folder Drive pilihan pengguna (Ketua Tim/Tim SAKIP) yang menggantikan
        // rekomendasi sistem (nama_folder_auto). Null = pakai nama_folder_auto, lihat
        // Kegiatan::namaFolder().
        Schema::table('kegiatan', function (Blueprint $table) {
            $table->string('nama_folder_kustom')->nullable()->after('nama_folder_auto');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kegiatan', function (Blueprint $table) {
            $table->dropColumn('nama_folder_kustom');
        });
    }
};
