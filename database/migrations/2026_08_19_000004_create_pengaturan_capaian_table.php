<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Baris tunggal (id=1) berisi parameter rumus capaian kinerja triwulanan yang
        // ditetapkan Tim SAKIP (lihat App\Models\PengaturanCapaian, Capaian::hitungPersentase())
        // — dibuat sebagai tabel, bukan konstanta kode, supaya bisa diubah Tim SAKIP
        // sendiri lewat halaman Pengaturan Rumus Capaian kalau rumus resmi berubah lagi
        // di kemudian hari, tanpa perlu rilis kode baru.
        Schema::create('pengaturan_capaian', function (Blueprint $table) {
            $table->id();
            $table->decimal('batas_maksimal_persen', 6, 2)->default(120);
            $table->timestamps();
        });

        DB::table('pengaturan_capaian')->insert([
            'batas_maksimal_persen' => 120,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pengaturan_capaian');
    }
};
