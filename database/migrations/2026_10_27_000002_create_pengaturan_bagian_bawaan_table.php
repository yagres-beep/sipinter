<?php

use App\Support\MigrasiKunciSingkat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Label tampilan bagian bawaan Isian Kegiatan (Kegiatan, Kendala & Solusi, Evaluasi
 * RTL, RTL Triwulan, IKU) yang bisa diganti Tim SAKIP. Satu baris per kode bagian
 * (App\Support\RegistriBagian). Tidak ada baris = label bawaan dari kode.
 *
 * Hanya tampilan di layar Isian Kegiatan: notula, DOCX, nama folder Drive, relasi
 * data, dan alur verifikasi tidak membaca tabel ini.
 *
 * Tabel baru saja — tidak ada tabel/data lama yang diubah.
 */
return new class extends Migration
{
    /** Kunci asing ke `users` butuh kunci tabel users sebentar — pakai MigrasiKunciSingkat. */
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('pengaturan_bagian_bawaan')) {
            return;
        }

        MigrasiKunciSingkat::jalankan(function () {
            Schema::create('pengaturan_bagian_bawaan', function (Blueprint $table) {
                $table->id();
                $table->string('kode', 40)->unique();
                $table->string('label_menu', 60)->nullable();
                $table->string('judul', 120)->nullable();
                $table->text('petunjuk')->nullable();
                $table->foreignId('diubah_oleh')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan_bagian_bawaan');
    }
};
