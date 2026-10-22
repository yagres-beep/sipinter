<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bagian kustom bisa diatur penuh lewat layar (tanpa edit kode): peran pengisi,
     * kolom isian tambahan (seperti tabel), label uraian, dan apakah serta bagaimana
     * tampil di notula (judul + format baris dengan {variabel}).
     */
    public function up(): void
    {
        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->string('peran_pengisi', 20)->default('ketua_tim')->after('deskripsi');
            $table->string('label_uraian', 100)->nullable()->after('peran_pengisi');
            $table->json('kolom')->nullable()->after('label_uraian');
            $table->boolean('masuk_notula')->default(true)->after('bukti_wajib');
            $table->string('judul_notula', 255)->nullable()->after('masuk_notula');
            $table->text('format_notula')->nullable()->after('judul_notula');
        });

        Schema::table('bagian_kustom_poin', function (Blueprint $table) {
            $table->json('nilai_kolom')->nullable()->after('teks');
        });
    }

    public function down(): void
    {
        Schema::table('bagian_kustom_poin', function (Blueprint $table) {
            $table->dropColumn('nilai_kolom');
        });

        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->dropColumn(['peran_pengisi', 'label_uraian', 'kolom', 'masuk_notula', 'judul_notula', 'format_notula']);
        });
    }
};
