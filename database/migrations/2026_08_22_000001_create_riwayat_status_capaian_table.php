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
        // Riwayat perubahan status alur verifikasi, dicatat di granularitas Capaian
        // (satu baris = satu IKU+periode, lihat App\Models\Capaian) BUKAN per Kegiatan
        // — supaya kegiatan tambahan yang diajukan belakangan pada IKU+bulan yang sama
        // tetap tergabung sebagai satu poin riwayat (satu Capaian), bukan duplikat per
        // kegiatan yang membingungkan saat statusnya berubah bersamaan.
        Schema::create('riwayat_status_capaian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capaian_id')->constrained('capaian')->cascadeOnUpdate()->cascadeOnDelete();
            $table->enum('status', ['diajukan', 'diverifikasi', 'dikembalikan', 'disetujui']);
            // Nullable — riwayat lama/hasil migrasi data tanpa aktor tetap bisa dicatat;
            // pengguna yang dihapus tidak ikut menghapus jejak riwayatnya (nullOnDelete).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('catatan')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('riwayat_status_capaian');
    }
};
