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
        // Pengguna yang mengunggah berkas -- dipakai untuk menentukan siapa yang
        // boleh mengganti nama berkas (pengunggahnya sendiri atau Tim SAKIP, lihat
        // Berkas::bisaDiubahNamaOleh()). Nullable: berkas lama dibuat sebelum kolom
        // ini ada, dan akun pengunggah bisa saja dihapus belakangan.
        Schema::table('berkas', function (Blueprint $table) {
            $table->foreignId('diunggah_oleh')->nullable()->after('storage_account_id')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('berkas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diunggah_oleh');
        });
    }
};
