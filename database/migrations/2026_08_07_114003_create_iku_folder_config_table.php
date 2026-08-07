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
        // Pola folder KHUSUS untuk satu IKU tertentu (mis. IKU 1010/1011 punya folder
        // Bulan tambahan di dalam Capaian yang IKU lain tidak punya) — override opsional
        // di atas pola GLOBAL (FolderConfig::current()). IKU tanpa baris di sini tetap
        // memakai pola global seperti biasa; lihat FolderStructureService::configUntukIku().
        Schema::create('iku_folder_config', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iku_id')->unique()->constrained('master_iku')->cascadeOnUpdate()->cascadeOnDelete();
            $table->json('pola_json');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('iku_folder_config');
    }
};
