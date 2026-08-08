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
        // Definisi bagian tambahan yang bisa dibuat Tim SAKIP tanpa mengubah kode
        // (mis. "Manajemen Risiko") — muncul sebagai bagian baru di Isian Kegiatan
        // (Ketua Tim) begitu aktif, dan otomatis ikut masuk ke Bagian I notula.
        Schema::create('bagian_kustom', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->text('deskripsi')->nullable();
            // true = minimal 1 poin wajib diisi saat mengajukan isian pada bulan
            // TERAKHIR triwulan (sama seperti pola RTL baru); false = selalu opsional.
            $table->boolean('wajib_akhir_triwulan')->default(false);
            // Bagian yang dinonaktifkan tidak lagi muncul di form isian BARU, tapi
            // data lama (bagian_kustom_poin) tetap tersimpan & tetap tampil di notula.
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bagian_kustom');
    }
};
