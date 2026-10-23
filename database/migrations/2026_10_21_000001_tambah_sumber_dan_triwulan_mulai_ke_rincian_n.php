<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pisahkan dua jenis baris di rincian_n:
 * - sumber 'target'     : rincian N dari Target Tahunan, dihitung sebagai penyebut N.
 * - sumber 'verifikasi' : rincian tambahan n dari halaman Verifikasi, TIDAK pernah
 *                         masuk N, hanya menambah pembilang bila sudah terealisasi.
 *
 * triwulan_mulai_berlaku: rincian baru boleh direalisasikan/dihitung mulai TW ini.
 * Rincian target = 1 (berlaku sepanjang tahun). Tidak pernah diubah otomatis.
 *
 * dilepas_dari_target_at: rincian target yang dihapus dari Target Tahunan padahal
 * sudah direalisasikan -- barisnya dipertahankan (realisasi tetap tercatat), tapi
 * tidak lagi dihitung sebagai N.
 *
 * Baris lama otomatis menjadi 'target' dengan triwulan_mulai_berlaku 1, jadi
 * hitungan N dan n data historis tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rincian_n', function (Blueprint $table) {
            $table->string('sumber', 20)->default('target')->after('uraian');
            $table->unsignedTinyInteger('triwulan_mulai_berlaku')->default(1)->after('sumber');
            $table->timestamp('dilepas_dari_target_at')->nullable()->after('triwulan_realisasi');
            $table->foreignId('dibuat_oleh')->nullable()->after('dilepas_dari_target_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rincian_n', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dibuat_oleh');
            $table->dropColumn(['sumber', 'triwulan_mulai_berlaku', 'dilepas_dari_target_at']);
        });
    }
};
