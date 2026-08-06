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
        Schema::create('kendala_solusi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iku_id')->constrained('master_iku')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('periode_id')->constrained('periode')->cascadeOnUpdate()->restrictOnDelete();
            $table->text('kendala')->nullable();
            $table->text('solusi')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kendala_solusi');
    }
};
