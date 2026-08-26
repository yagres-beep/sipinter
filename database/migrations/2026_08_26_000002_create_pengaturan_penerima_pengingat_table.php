<?php

use App\Models\PengaturanPenerimaPengingat;
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
        // Satu baris per jenis pengingat WA (lihat App\Models\PengaturanPenerimaPengingat::JENIS)
        // berisi daftar peran/pseudo-peran yang jadi penerimanya — bisa diubah Tim SAKIP
        // lewat halaman Pengingat WA, menggantikan daftar penerima yang tadinya
        // dikodekan langsung di tiap Command/Listener pengingat.
        Schema::create('pengaturan_penerima_pengingat', function (Blueprint $table) {
            $table->id();
            $table->string('jenis')->unique();
            $table->json('roles');
            $table->timestamps();
        });

        $now = now();

        DB::table('pengaturan_penerima_pengingat')->insert(
            collect(PengaturanPenerimaPengingat::JENIS)->map(fn ($meta, $jenis) => [
                'jenis' => $jenis,
                'roles' => json_encode($meta['default']),
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all()
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pengaturan_penerima_pengingat');
    }
};
