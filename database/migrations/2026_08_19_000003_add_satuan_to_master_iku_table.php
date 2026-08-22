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
        Schema::table('master_iku', function (Blueprint $table) {
            // Satuan angka target/realisasi IKU ini (mis. "Persen", "Poin", "Dokumen") —
            // murni label tampilan di form Verifikasi Capaian & Dasbor Kinerja, TIDAK
            // mengubah rumus capaian (lihat Capaian::hitungPersentase(), yang selalu
            // memakai rasio realisasi/target terlepas dari satuannya). Diisi manual
            // lewat form Master IKU, TIDAK lewat kolom template Excel — sama seperti
            // "sasaran" (lihat migrasi create_master_iku_table).
            $table->string('satuan')->default('Persen')->after('sasaran');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->dropColumn('satuan');
        });
    }
};
