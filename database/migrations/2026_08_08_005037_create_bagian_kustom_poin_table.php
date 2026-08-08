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
        // Satu baris = satu POIN yang diisi Ketua Tim untuk satu bagian kustom, pada
        // satu IKU dan satu periode (bulan) — pola sama seperti kendala_solusi/rtl_evaluasi.
        // Bukti dukung poin disimpan lewat tabel berkas (ref_type = self::class), WAJIB
        // ada per poin (divalidasi di PengisianKegiatan, bukan lewat DB constraint).
        Schema::create('bagian_kustom_poin', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bagian_kustom_id')->constrained('bagian_kustom')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('iku_id')->constrained('master_iku')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('periode_id')->constrained('periode')->cascadeOnUpdate()->restrictOnDelete();
            $table->text('teks');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bagian_kustom_poin');
    }
};
