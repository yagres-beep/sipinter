<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menandai IKU bertipe 'rasio' yang realisasi Pembilang (X)-nya diisi lewat daftar
 * rincian item (App\Models\RincianN) bukan angka manual -- lihat migrasi
 * 2026_08_27_000014_buat_tabel_rincian_n. Default false supaya SELURUH IKU yang
 * sudah ada hari ini tetap memakai isian angka manual seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->boolean('pakai_rincian_n')->default(false)->after('metode_capaian');
        });
    }

    public function down(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->dropColumn('pakai_rincian_n');
        });
    }
};
