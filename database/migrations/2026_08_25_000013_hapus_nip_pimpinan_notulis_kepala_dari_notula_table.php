<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batal jalan: Template Notula resmi (termasuk versi "Update Prioritas") TIDAK
 * pernah menampilkan NIP untuk Pimpinan Rapat, Notulis, maupun Kepala Satker di
 * mana pun pada dokumen — baik di tabel header maupun blok TTD. Kolom-kolom ini
 * ditambahkan migrasi 2026_08_25_000011 lalu ternyata tidak dipakai sama sekali.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notula', function (Blueprint $table) {
            $table->dropColumn(['nip_pimpinan_rapat', 'nip_notulis', 'nip_kepala_satker']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notula', function (Blueprint $table) {
            $table->string('nip_pimpinan_rapat')->nullable()->after('pimpinan_rapat');
            $table->string('nip_notulis')->nullable()->after('notulis');
            $table->string('nip_kepala_satker')->nullable()->after('kepala_satker');
        });
    }
};
