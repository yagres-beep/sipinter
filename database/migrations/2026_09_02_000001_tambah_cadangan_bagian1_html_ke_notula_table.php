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
            // Salinan bagian1_html SEBELUM ditimpa tombol "Susun Ulang Otomatis"
            // (lihat KompilasiNotula::susunUlangOtomatis()) -- supaya suntingan manual
            // Tim SAKIP di pratinjau tidak hilang tanpa jejak kalau tombolnya kepencet
            // tidak sengaja, bisa dipulihkan lewat KompilasiNotula::pulihkanSuntinganBagian1().
            $table->longText('bagian1_html_cadangan')->nullable()->after('bagian1_html');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notula', function (Blueprint $table) {
            $table->dropColumn('bagian1_html_cadangan');
        });
    }
};
