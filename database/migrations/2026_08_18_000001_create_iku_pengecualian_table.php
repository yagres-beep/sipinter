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
        // Pengecualian penanggung jawab OTOMATIS (via tim) per IKU — dipakai saat
        // sebuah tim beranggotakan beberapa Ketua Tim tapi hanya sebagian yang
        // benar-benar bertanggung jawab atas satu IKU tertentu. Mengecualikan
        // seseorang di sini TIDAK mengeluarkannya dari keanggotaan tim (user_tim);
        // ia tetap dihitung untuk IKU lain di tim yang sama.
        Schema::create('iku_pengecualian', function (Blueprint $table) {
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
        Schema::dropIfExists('iku_pengecualian');
    }
};
