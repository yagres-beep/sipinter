<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hanya MENAMBAH tabel & kolom — tidak ada baris lama yang diubah/dihapus.
     */
    public function up(): void
    {
        // Salinan fisik bukti capaian kegiatan di folder Evaluasi-RTL (lihat
        // App\Services\SinkronBuktiRtlService). Satu baris = satu berkas sumber milik
        // kegiatan yang tertaut ke satu poin RTL lewat kegiatan.rtl_evaluasi_id —
        // BUKAN baris `berkas` kedua: status verifikasinya tetap satu, milik berkas
        // sumber. Unik per (berkas, poin RTL) supaya sinkronisasi ulang tidak dobel.
        Schema::create('salinan_bukti_rtl', function (Blueprint $table) {
            $table->id();
            $table->foreignId('berkas_id')->constrained('berkas')->cascadeOnDelete();
            $table->foreignId('rtl_evaluasi_id')->constrained('rtl_evaluasi')->cascadeOnDelete();
            // ID Drive berkas sumber saat disalin — beda dengan berkas.drive_file_id
            // sekarang berarti salinannya basi dan harus dibuat ulang.
            $table->string('sumber_drive_file_id')->nullable();
            $table->string('drive_file_id')->nullable();
            $table->foreignId('storage_account_id')->nullable()->constrained('storage_account')->nullOnDelete();
            // menunggu = belum dicoba/sedang diantre, tersalin, gagal (dicoba lagi otomatis).
            $table->string('status', 12)->default('menunggu');
            $table->unsignedSmallInteger('percobaan')->default(0);
            $table->text('galat')->nullable();
            $table->timestamp('tersalin_pada')->nullable();
            $table->timestamps();

            $table->unique(['berkas_id', 'rtl_evaluasi_id']);
            $table->index('status');
        });

        // Tahapan tujuan bukti realisasi RTL yang diunggah mandiri (folder
        // Evaluasi-RTL/<poin RTL>/<tahapan>/<subfolder>). Bukti kegiatan tidak
        // memakainya — tahapannya melekat di baris kegiatan masing-masing.
        // periode_id = bulan isian tempat bukti realisasi RTL diunggah mandiri, untuk
        // folder Bulan (bila kategori memakai subfolder per bulan) — sama dengan salinan
        // dari Kegiatan yang memakai bulan kegiatannya. NULL (semua bukti lama & bukti
        // lain) = perilaku lama, memakai periode poin RTL.
        Schema::table('berkas', function (Blueprint $table) {
            $table->string('tahapan', 100)->nullable()->after('subfolder');
            $table->foreignId('periode_id')->nullable()->after('tahapan')->constrained('periode')->nullOnDelete();
        });

        // Jejak perubahan status Sesuai/Tidak Sesuai per berkas oleh Tim SAKIP, supaya
        // status yang tampil di Kegiatan maupun Evaluasi RTL bisa ditelusuri asalnya.
        // berkas_id nullOnDelete + kode/nama disalin: riwayat tetap terbaca walau
        // berkasnya sudah diganti/dihapus.
        Schema::create('riwayat_verifikasi_berkas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('berkas_id')->nullable()->constrained('berkas')->nullOnDelete();
            $table->string('berkas_kode', 40)->nullable();
            $table->string('nama_file')->nullable();
            $table->string('status_lama', 20)->nullable();
            $table->string('status_baru', 20);
            $table->text('catatan')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['berkas_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riwayat_verifikasi_berkas');

        Schema::table('berkas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('periode_id');
            $table->dropColumn('tahapan');
        });

        Schema::dropIfExists('salinan_bukti_rtl');
    }
};
