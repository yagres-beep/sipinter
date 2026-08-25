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
            // Kota tempat notula ditandatangani (baris "Kota, tanggal" pada blok TTD) —
            // TERPISAH dari `tempat` (venue rapat, mis. "Aula BPS Kabupaten Buton Utara")
            // karena keduanya bisa berbeda: rapatnya di aula, tapi notula ditandatangani
            // atas nama kota/kabupaten (mis. "Kulisusu, 17 Juli 2026").
            $table->string('kota_ttd')->nullable()->after('nip_kepala_satker');

            // Tautan "Lampiran Basis Data IKU" — sama untuk seluruh IKU pada satu
            // dokumen (per tahun), diisi sekali lewat Detail Rapat dan dicetak ulang
            // di bawah tiap blok "Dasar Hitung dan Basis Data Realisasi IKU" Bagian I.
            $table->string('link_lampiran_basis_data')->nullable()->after('kota_ttd');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notula', function (Blueprint $table) {
            $table->dropColumn(['kota_ttd', 'link_lampiran_basis_data']);
        });
    }
};
