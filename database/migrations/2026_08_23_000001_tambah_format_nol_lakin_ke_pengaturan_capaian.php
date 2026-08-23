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
        // Pilihan tampilan angka Target/Realisasi/Capaian % bernilai 0 pada tabel
        // Rekap Kinerja Tahunan (LAKIN) & unduhan Excel-nya — Tim SAKIP bisa memilih
        // ditulis sebagai "0" apa adanya (default, sama seperti perilaku sebelum ini
        // ada) atau sebagai "-" (dianggap sama seperti belum ada data). Lihat
        // App\Models\PengaturanCapaian::formatAngka()/formatPersen().
        Schema::table('pengaturan_capaian', function (Blueprint $table) {
            $table->boolean('tampilkan_nol_sebagai_strip')->default(false)->after('batas_normalisasi_pko');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pengaturan_capaian', function (Blueprint $table) {
            $table->dropColumn('tampilkan_nol_sebagai_strip');
        });
    }
};
