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
            // Nomor versi notula triwulan ini. Notula yang SUDAH disetujui Kepala tidak
            // lagi menjadi jalan buntu: begitu ada isian/bagian yang berubah setelahnya
            // (mis. Kepala mengembalikan satu isian IKU lewat halaman Persetujuan, atau
            // Tim SAKIP mengganti Bagian II/III), notula ditarik balik ke "draft" dan
            // versinya naik satu — lihat Notula::bukaVersiBaru(). Dipakai juga sebagai
            // penanda nama berkas ("...-v2.pdf", lihat Notula::namaUnduhan()) supaya PDF
            // final tiap versi tersimpan terpisah, tidak saling menimpa, baik di disk
            // lokal maupun arsip Drive.
            $table->unsignedInteger('versi')->default(1)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notula', function (Blueprint $table) {
            $table->dropColumn('versi');
        });
    }
};
