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
        // Satu baris tabel LAKIN — biasanya (tapi tidak wajib) mengikuti satu Master IKU;
        // iku_id nullable supaya Tim SAKIP tetap bisa menambah baris bebas (custom) yang
        // tidak mengacu ke IKU manapun, sesuai kebutuhan "tambah/kurangin sendiri".
        Schema::create('lakin_baris', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lakin_id')->constrained('lakin')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('iku_id')->nullable()->constrained('master_iku')->cascadeOnUpdate()->nullOnDelete();
            $table->string('sasaran')->nullable();
            $table->text('indikator');
            $table->decimal('target', 15, 2)->nullable();
            $table->decimal('realisasi', 15, 2)->nullable();
            $table->decimal('capaian_persen', 6, 2)->nullable();
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lakin_baris');
    }
};
