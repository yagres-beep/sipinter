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
        Schema::table('lakin_baris', function (Blueprint $table) {
            $table->text('kegiatan')->nullable()->after('realisasi');
            $table->text('solusi_kendala')->nullable()->after('kegiatan');
            $table->text('rtl')->nullable()->after('solusi_kendala');
            $table->string('pic')->nullable()->after('rtl');
            $table->date('batas_waktu_tindak_lanjut')->nullable()->after('pic');
            $table->string('link_bukti_dukung_kinerja')->nullable()->after('batas_waktu_tindak_lanjut');
            $table->string('link_rtl_sebelumnya')->nullable()->after('link_bukti_dukung_kinerja');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lakin_baris', function (Blueprint $table) {
            $table->dropColumn([
                'kegiatan',
                'solusi_kendala',
                'rtl',
                'pic',
                'batas_waktu_tindak_lanjut',
                'link_bukti_dukung_kinerja',
                'link_rtl_sebelumnya',
            ]);
        });
    }
};
