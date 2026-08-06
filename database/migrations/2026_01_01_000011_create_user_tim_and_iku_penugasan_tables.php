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
        // Keanggotaan Tim — satu Ketua Tim bisa merangkap lebih dari satu tim
        // (nama tim adalah string bebas, sama seperti master_iku.tim, bukan tabel
        // tim tersendiri — konsisten dengan cara nama tim sudah dipakai di seluruh sistem).
        Schema::create('user_tim', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('tim');
            $table->timestamps();

            $table->unique(['user_id', 'tim']);
        });

        // Penugasan IKU MANUAL — penugasan "otomatis via tim" TIDAK disimpan di sini,
        // cukup dihitung saat tampil (IKU dengan tim yang sama seperti keanggotaan tim
        // pengguna). Tabel ini hanya menyimpan penambahan/override manual di luar itu.
        Schema::create('iku_penugasan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iku_id')->constrained('master_iku')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['iku_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('iku_penugasan');
        Schema::dropIfExists('user_tim');
    }
};
