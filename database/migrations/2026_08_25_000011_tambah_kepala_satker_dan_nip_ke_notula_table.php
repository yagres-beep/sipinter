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
            // NIP berpasangan dengan Pimpinan Rapat/Notulis — otomatis terisi di
            // formulir Detail Rapat saat namanya dipilih dari daftar pengguna (lihat
            // KompilasiNotula::daftarPegawai()), tapi tetap bisa diisi manual.
            $table->string('nip_pimpinan_rapat')->nullable()->after('pimpinan_rapat');
            $table->string('nip_notulis')->nullable()->after('notulis');

            // Kepala Satker: nama & NIP terpisah dari "Pimpinan Rapat" — dicatat di
            // Detail Rapat sebagai metadata rapat, BUKAN sumber blok TTD pada notula
            // final (itu tetap memakai Notula::disetujuiOleh, yakni Kepala yang benar-
            // benar menyetujui — lihat NotulaService::dataNotulaUtuh()).
            $table->string('kepala_satker')->nullable()->after('nip_notulis');
            $table->string('nip_kepala_satker')->nullable()->after('kepala_satker');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notula', function (Blueprint $table) {
            $table->dropColumn(['nip_pimpinan_rapat', 'nip_notulis', 'kepala_satker', 'nip_kepala_satker']);
        });
    }
};
