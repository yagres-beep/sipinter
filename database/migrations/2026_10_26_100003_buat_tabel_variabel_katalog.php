<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Katalog variabel yang bisa dipakai ulang oleh kolom Bagian Kustom mana pun. Kolom
     * yang menautkan variabel mengikuti jenis, format angka, dan aturan validasinya;
     * nilainya tetap disimpan per poin seperti kolom biasa. Tabel baru — tidak ada data
     * lama yang disentuh.
     */
    public function up(): void
    {
        Schema::create('variabel_katalog', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 60)->unique();
            $table->string('nama', 150);
            $table->string('tipe', 30)->default('teks');
            $table->string('satuan', 30)->nullable();
            $table->unsignedTinyInteger('desimal')->nullable();
            $table->string('pemisah', 10)->nullable();
            $table->boolean('ribuan')->default(true);
            $table->string('min', 30)->nullable();
            $table->string('maks', 30)->nullable();
            $table->unsignedInteger('panjang_min')->nullable();
            $table->unsignedInteger('panjang_maks')->nullable();
            $table->json('opsi')->nullable();
            $table->string('sumber', 30)->default('isian');
            $table->text('keterangan')->nullable();
            $table->boolean('aktif')->default(true);
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('diubah_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variabel_katalog');
    }
};
