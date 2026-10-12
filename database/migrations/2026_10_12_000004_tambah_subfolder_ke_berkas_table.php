<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subfolder khusus di dalam folder kegiatan tempat berkas bukti ini diunggah
     * (mis. "Undangan", "Dokumentasi") — null = langsung di folder kegiatan.
     */
    public function up(): void
    {
        Schema::table('berkas', function (Blueprint $table) {
            $table->string('subfolder', 100)->nullable()->after('nama_file');
        });
    }

    public function down(): void
    {
        Schema::table('berkas', function (Blueprint $table) {
            $table->dropColumn('subfolder');
        });
    }
};
