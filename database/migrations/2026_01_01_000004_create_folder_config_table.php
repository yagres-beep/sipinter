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
        Schema::create('folder_config', function (Blueprint $table) {
            $table->id();
            $table->json('pola_json');
            $table->text('template_notula_penanda');
            // Path berkas .docx template notula (RF-41) yang diunggah Tim SAKIP, berisi
            // penanda {{iku}}, {{analisis_capaian}}, {{kendala_solusi_kumulatif}}, {{rtl}},
            // {{ttd_kepala}} — disimpan lokal, hanya sebagai referensi/arsip format resmi.
            $table->string('template_notula_path')->nullable();
            $table->string('template_notula_nama_asli')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('folder_config');
    }
};
