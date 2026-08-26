<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sheet Master_IKU pada fitur import Excel (spek bagian 6) TIDAK memuat kolom
     * Tim/Penanggung Jawab sama sekali — kedua kolom itu diisi belakangan lewat form
     * manual Master IKU setelah indikator masuk lewat import. `tim`/`penanggung_jawab`
     * karenanya perlu bisa kosong sementara sampai diisi manual.
     */
    public function up(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->string('tim')->nullable()->change();
            $table->string('penanggung_jawab')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->string('tim')->nullable(false)->change();
            $table->string('penanggung_jawab')->nullable(false)->change();
        });
    }
};
