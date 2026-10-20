<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Realisasi (kumulatif s.d. TW ini) IKU 'langsung' (realisasi_tw1..4) diketik Tim
 * SAKIP dengan 5 angka di belakang koma (mis. 5,55556) -- sebelumnya decimal(15,2)
 * memotongnya jadi 5,56. Hanya MELEBARKAN skala (55.60 tetap 55.60000), tidak ada
 * data yang berubah nilai. down() mengembalikan ke 2 desimal (nilai dibulatkan).
 */
return new class extends Migration
{
    private const KOLOM = ['realisasi_tw1', 'realisasi_tw2', 'realisasi_tw3', 'realisasi_tw4'];

    public function up(): void
    {
        Schema::table('capaian_tahunan', function (Blueprint $table) {
            foreach (self::KOLOM as $kolom) {
                $table->decimal($kolom, 15, 5)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('capaian_tahunan', function (Blueprint $table) {
            foreach (self::KOLOM as $kolom) {
                $table->decimal($kolom, 15, 2)->nullable()->change();
            }
        });
    }
};
