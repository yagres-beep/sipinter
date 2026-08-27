<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daftar item pembentuk Penyebut (Y) satu IKU 'rasio' pada satu tahun (mis. daftar
 * seluruh publikasi yang direncanakan tahun ini) -- diisi sekali di awal tahun lewat
 * App\Livewire\TargetTahunan. Jumlah baris ini (COUNT) menggantikan input manual
 * Alokasi Y. `triwulan_realisasi` null berarti item belum direalisasikan; begitu
 * diisi (lewat App\Livewire\VerifikasiCapaian, dipilih dari daftar ini) menandai
 * ITEM itu direalisasikan pada triwulan tsb -- jumlah item per triwulan menggantikan
 * input manual Realisasi Pembilang (X). Hanya relevan bila
 * MasterIku::pakai_rincian_n bernilai true (lihat migrasi
 * 2026_08_27_000013_tambah_pakai_rincian_n_ke_master_iku).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rincian_n', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iku_id')->constrained('master_iku')->cascadeOnDelete();
            $table->unsignedSmallInteger('tahun');
            $table->string('uraian');
            $table->unsignedTinyInteger('triwulan_realisasi')->nullable();
            $table->timestamps();

            $table->index(['iku_id', 'tahun']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rincian_n');
    }
};
