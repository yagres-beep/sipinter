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
        // Baris tunggal (id=1) berisi waktu pengingat WA terjadwal yang bisa diubah
        // Tim SAKIP (lihat App\Models\PengaturanPengingat, routes/console.php,
        // PengingatDeadlineIkuCommand) — dibuat sebagai tabel, bukan konstanta kode,
        // sama seperti pola pengaturan_capaian.
        Schema::create('pengaturan_pengingat', function (Blueprint $table) {
            $table->id();
            $table->time('jam_kirim')->default('08:00:00');
            $table->unsignedTinyInteger('deadline_h_minus')->default(3);
            $table->timestamps();
        });

        DB::table('pengaturan_pengingat')->insert([
            'jam_kirim' => '08:00:00',
            'deadline_h_minus' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pengaturan_pengingat');
    }
};
