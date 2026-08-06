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
        Schema::create('rtl_evaluasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iku_id')->constrained('master_iku')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('periode_id')->constrained('periode')->cascadeOnUpdate()->restrictOnDelete();
            $table->text('rtl_teks');
            $table->string('berlaku_bulan')->nullable();
            $table->string('pic')->nullable();
            $table->date('batas_waktu')->nullable();
            // Narasi realisasi (RF-30: "ketua tim melaporkan realisasinya") — TEKS bebas,
            // bukan sekadar ya/tidak, karena RF-35 mencocokkan teks ini dengan rtl_teks
            // (kemiripan teks), yang hanya masuk akal bila keduanya sama-sama narasi.
            $table->text('realisasi')->nullable();
            // Hasil pencocokan kemiripan teks (RF-35), 3 tingkat sesuai SRS: cocok /
            // perlu ditinjau / tidak cocok. Diisi otomatis lalu dapat disesuaikan manual.
            $table->enum('status_cocok', ['cocok', 'perlu_ditinjau', 'tidak_cocok'])->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rtl_evaluasi');
    }
};
