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
        Schema::create('master_iku', function (Blueprint $table) {
            $table->id();
            $table->string('kode')->unique();
            $table->text('indikator');
            $table->string('tim');
            $table->string('penanggung_jawab');
            // Sasaran (tujuan strategis) — dipakai untuk mengelompokkan tabel "Kesiapan
            // per Sasaran" pada halaman Kompilasi Notula (RF-42c). Diisi manual lewat
            // form Master IKU, TIDAK lewat kolom template Excel (RF-05 tidak mencakupnya).
            $table->string('sasaran')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_iku');
    }
};
