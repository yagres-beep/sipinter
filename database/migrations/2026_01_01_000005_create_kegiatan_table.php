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
        Schema::create('kegiatan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iku_id')->constrained('master_iku')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('periode_id')->constrained('periode')->cascadeOnUpdate()->restrictOnDelete();
            $table->text('uraian_kegiatan');
            $table->enum('jenis', ['bukan_survei_sensus', 'survei_sensus']);
            $table->enum('tahapan_survei', ['persiapan', 'pelaksanaan', 'pengolahan', 'diseminasi'])->nullable();
            $table->string('nama_folder_auto')->nullable();
            // ID folder Drive milik kegiatan INI SAJA (RF-13), diisi sekali saat berkas
            // pertama diunggah (lazy creation, RF-14) lalu dipakai ulang untuk unggahan
            // berikutnya. Disimpan per baris (bukan dicari ulang lewat nama) supaya dua
            // kegiatan yang kebetulan menghasilkan nama_folder_auto sama tetap punya
            // folder Drive masing-masing yang terpisah — lihat FolderStructureService.
            $table->string('drive_folder_id')->nullable();
            $table->enum('status_dokumen', ['draft', 'diajukan', 'diverifikasi', 'disetujui', 'dikembalikan'])->default('draft');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kegiatan');
    }
};
