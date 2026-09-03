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
        // Tim penanggung jawab IKU — SATU IKU boleh punya LEBIH DARI SATU tim (RF baru:
        // sebelumnya master_iku.tim cuma satu string, ternyata satu IKU bisa ditangani
        // beberapa tim sekaligus). Pola persis sama seperti user_tim (nama tim string
        // bebas, bukan tabel tim tersendiri) supaya konsisten dengan cara nama tim sudah
        // dipakai di seluruh sistem. master_iku.tim (kolom lama) TETAP ada sebagai jalur
        // tulis ringkas (mis. import Excel, fixture tes) yang otomatis disinkronkan ke
        // sini lewat App\Models\MasterIku::booted() — lihat migrasi backfill setelah ini.
        Schema::create('iku_tim', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iku_id')->constrained('master_iku')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('tim');
            $table->timestamps();

            $table->unique(['iku_id', 'tim']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('iku_tim');
    }
};
