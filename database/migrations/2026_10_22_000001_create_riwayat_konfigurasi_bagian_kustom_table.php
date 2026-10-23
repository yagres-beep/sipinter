<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekam jejak perubahan DEFINISI Bagian Kustom (buat, salin, ubah, aktif/nonaktif,
 * urutan, hapus) oleh Tim SAKIP. Terpisah dari data isian (bagian_kustom_poin) dan
 * tidak dipakai dalam perhitungan apa pun.
 *
 * bagian_kustom_id nullOnDelete: jejak tetap ada walau bagiannya kemudian dihapus
 * (nama bagian ikut tersimpan di kolom nama_bagian).
 *
 * Tabel baru saja — tidak ada tabel/data lama yang diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('riwayat_konfigurasi_bagian_kustom', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bagian_kustom_id')->nullable()->constrained('bagian_kustom')->nullOnDelete();
            $table->string('nama_bagian');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Salinan nama pelaku saat kejadian — tetap terbaca walau akunnya dihapus
            // (user_id menjadi NULL).
            $table->string('nama_pengguna')->nullable();
            $table->string('aksi', 20);
            $table->text('ringkasan');
            $table->json('sebelum')->nullable();
            $table->json('sesudah')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['bagian_kustom_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riwayat_konfigurasi_bagian_kustom');
    }
};
