<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indikator Proksi yang ditambahkan Tim SAKIP sendiri saat verifikasi IKU (pola sama
 * seperti Rincian Output) — menggantikan tabel proksi tetap yang dulu hanya dicetak
 * untuk indikator berteks "SAKIP". Disimpan per IKU per TRIWULAN (bukan per Capaian,
 * karena satu IKU bisa punya beberapa baris Capaian dalam triwulan yang sama).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indikator_proksi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iku_id')->constrained('master_iku')->cascadeOnDelete();
            $table->unsignedSmallInteger('tahun');
            $table->unsignedTinyInteger('triwulan');
            $table->string('uraian')->nullable();
            $table->string('target')->nullable();
            $table->string('realisasi')->nullable();
            $table->timestamps();

            $table->index(['iku_id', 'tahun', 'triwulan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indikator_proksi');
    }
};
