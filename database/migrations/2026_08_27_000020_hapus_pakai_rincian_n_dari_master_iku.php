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
        // Rincian Item (N) kini SELALU aktif untuk semua IKU bermetode Rasio (%),
        // tidak lagi opsional lewat centang manual -- lihat App\Models\MasterIku::
        // pakaiRasio(), sekarang satu-satunya sumber kebenaran (dulu ada DUA: kolom
        // ini + metode_capaian, rawan tidak sinkron). Konsekuensi: IKU % yang
        // SEBELUMNYA masih pakai angka Alokasi Y manual (belum sempat diisi Rincian
        // N sama sekali) akan tampil 0 item di tab Target Tahunan sampai diisi ulang
        // manual -- diterima sebagai bagian dari RF ini (bukan bug).
        Schema::table('master_iku', function (Blueprint $table) {
            $table->dropColumn('pakai_rincian_n');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->boolean('pakai_rincian_n')->default(false)->after('metode_capaian');
        });
    }
};
