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
        // Riwayat perubahan status alur persetujuan Notula (RF-44), satu baris per
        // transisi (kirim ke Kepala / disetujui / dikembalikan) — mirip pola
        // riwayat_status_capaian, dipakai untuk menampilkan Riwayat Tindakan di
        // halaman Persetujuan Notula milik Kepala.
        Schema::create('riwayat_status_notula', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notula_id')->constrained('notula')->cascadeOnUpdate()->cascadeOnDelete();
            $table->enum('status', ['draft', 'menunggu_persetujuan', 'disetujui', 'dikembalikan']);
            // Nullable — user yang menghapus akun tidak ikut menghapus jejak riwayatnya.
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
        Schema::dropIfExists('riwayat_status_notula');
    }
};
