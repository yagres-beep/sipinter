<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak aktivitas (audit trail) — siapa melakukan apa, kapan, pada objek mana, dan
     * apakah berhasil atau ditolak. Melengkapi riwayat_status_capaian (status alur
     * verifikasi Capaian) untuk aktivitas yang belum tercatat di mana pun: pengelolaan
     * Bagian Kustom, pengisian poinnya, bukti dukungnya, dan penandaan verifikasinya.
     *
     * Hanya MENAMBAH tabel baru; tidak menyentuh tabel atau data lain. Kolom rujukan
     * (user_id, bagian_kustom_id, iku_id, periode_id, objek_id) sengaja TANPA foreign
     * key: catatan audit tidak boleh ikut berubah/terhapus ketika objeknya dihapus.
     * Nama pengguna, peran, dan keterangan disimpan sebagai salinan saat kejadian.
     */
    public function up(): void
    {
        Schema::create('log_aktivitas', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at')->useCurrent();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('nama_pengguna', 255)->nullable();
            $table->string('peran', 50)->nullable();

            $table->string('aksi', 60);
            $table->string('status', 10)->default('berhasil');

            $table->string('objek_tipe', 60)->nullable();
            $table->unsignedBigInteger('objek_id')->nullable();

            $table->string('menu', 40)->nullable();
            $table->unsignedBigInteger('bagian_kustom_id')->nullable();
            $table->unsignedBigInteger('iku_id')->nullable();
            $table->unsignedBigInteger('periode_id')->nullable();

            $table->text('keterangan');
            $table->json('perubahan')->nullable();
            $table->string('korelasi', 36)->nullable();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['bagian_kustom_id', 'created_at']);
            $table->index(['iku_id', 'periode_id']);
            $table->index(['objek_tipe', 'objek_id']);
            $table->index('korelasi');
            // Saringan halaman baca log (jenis tindakan / hasil) + urutan terbaru.
            $table->index(['aksi', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_aktivitas');
    }
};
