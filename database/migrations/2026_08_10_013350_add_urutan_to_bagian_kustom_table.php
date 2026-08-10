<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bagian_kustom', function (Blueprint $table) {
            // Urutan tampil di Isian Kegiatan & Bagian I notula — bisa digeser bebas
            // oleh Tim SAKIP (naikkan/turunkan), tidak lagi mengikuti urutan dibuat.
            $table->unsignedInteger('urutan')->default(0)->after('aktif');
        });

        // Isi nilai awal mengikuti urutan id yang sudah ada, supaya tidak semua 0.
        DB::table('bagian_kustom')->orderBy('id')->get(['id'])->each(
            fn ($baris, $index) => DB::table('bagian_kustom')->where('id', $baris->id)->update(['urutan' => $index])
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->dropColumn('urutan');
        });
    }
};
