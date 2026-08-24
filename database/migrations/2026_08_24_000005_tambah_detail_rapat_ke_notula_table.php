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
        Schema::table('notula', function (Blueprint $table) {
            $table->string('hari_tanggal')->nullable()->after('periode_id');
            $table->string('waktu')->nullable()->after('hari_tanggal');
            $table->string('tempat')->nullable()->after('waktu');
            $table->string('pimpinan_rapat')->nullable()->after('tempat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notula', function (Blueprint $table) {
            $table->dropColumn(['hari_tanggal', 'waktu', 'tempat', 'pimpinan_rapat']);
        });
    }
};
