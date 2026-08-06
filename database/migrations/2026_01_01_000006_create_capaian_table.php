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
        Schema::create('capaian', function (Blueprint $table) {
            $table->id();
            // Capaian merepresentasikan angka capaian IKU pada satu periode (RF-38) —
            // bukan per kegiatan, karena satu IKU boleh punya banyak kegiatan (RF-19)
            // yang berbagi satu set Target PK/Target TW/Realisasi/Capaian %.
            $table->foreignId('iku_id')->constrained('master_iku')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('periode_id')->constrained('periode')->cascadeOnUpdate()->cascadeOnDelete();
            $table->text('analisis_capaian')->nullable();
            $table->decimal('target_pk', 15, 2)->nullable();
            $table->decimal('target_tw', 15, 2)->nullable();
            $table->decimal('realisasi', 15, 2)->nullable();
            $table->decimal('persentase_capaian', 6, 2)->nullable();
            $table->timestamps();

            $table->unique(['iku_id', 'periode_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capaian');
    }
};
