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
        Schema::create('berkas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ref_id');
            $table->string('ref_type');
            $table->index(['ref_type', 'ref_id']);
            // 'notula' dipakai khusus arsip PDF final notula gabungan ke Drive (RF-44a) —
            // bukan bukti dukung kegiatan seperti 4 kategori lainnya.
            $table->enum('kategori', ['capaian', 'solusi', 'evaluasi_rtl', 'bukti_dukung_sakip', 'notula']);
            $table->string('nama_file');
            $table->string('path')->nullable();
            $table->string('drive_file_id')->nullable();
            $table->foreignId('storage_account_id')->nullable()->constrained('storage_account')->cascadeOnUpdate()->nullOnDelete();
            $table->enum('status_verifikasi', ['menunggu', 'terverifikasi', 'ditolak'])->default('menunggu');
            $table->text('catatan')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('berkas');
    }
};
