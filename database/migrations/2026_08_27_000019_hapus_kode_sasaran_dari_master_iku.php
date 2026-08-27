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
        // Kode Sasaran dihapus atas permintaan RF: tidak dipakai Notula (hanya Nama
        // Sasaran/"sasaran" yang dicetak & dipakai mengelompokkan tabel "Kesiapan
        // per Sasaran") maupun bagian lain aplikasi mana pun.
        Schema::table('master_iku', function (Blueprint $table) {
            $table->dropColumn('kode_sasaran');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->string('kode_sasaran')->nullable()->after('kode');
        });
    }
};
