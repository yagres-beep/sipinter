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
        // Satu baris = satu dokumen LAKIN (Laporan Kinerja) untuk satu TAHUN — dibentuk
        // Tim SAKIP dari data capaian yang sudah terverifikasi (lihat LakinBaris), lalu
        // bisa disesuaikan bebas (tambah/ubah/hapus baris) karena format LAKIN instansi
        // bisa berbeda-beda. Ketua Tim & Kepala hanya bisa melihat, tidak mengubah.
        Schema::create('lakin', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('tahun')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lakin');
    }
};
