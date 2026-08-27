<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RF baru: satu Kegiatan bisa punya LEBIH DARI SATU Rincian Output (RO) — kolom
 * rincian_output/volume_ro/progres_persen langsung di tabel kegiatan (migrasi
 * 2026_08_25_000007 & 2026_08_26_000005) cuma menampung SATU RO per kegiatan.
 * Data lama dipindahkan (bukan dibuang) sebagai baris RO pertama tiap kegiatan
 * yang sudah terisi salah satu dari ketiga kolom itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rincian_output', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kegiatan_id')->constrained('kegiatan')->cascadeOnDelete();
            $table->string('uraian')->nullable();
            $table->string('volume_ro')->nullable();
            $table->decimal('progres_persen', 5, 2)->nullable();
            $table->timestamps();
        });

        $kegiatanBerRo = DB::table('kegiatan')
            ->whereNotNull('rincian_output')
            ->orWhereNotNull('volume_ro')
            ->orWhereNotNull('progres_persen')
            ->get(['id', 'rincian_output', 'volume_ro', 'progres_persen']);

        foreach ($kegiatanBerRo as $kegiatan) {
            DB::table('rincian_output')->insert([
                'kegiatan_id' => $kegiatan->id,
                'uraian' => $kegiatan->rincian_output,
                'volume_ro' => $kegiatan->volume_ro,
                'progres_persen' => $kegiatan->progres_persen,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('kegiatan', function (Blueprint $table) {
            $table->dropColumn(['rincian_output', 'volume_ro', 'progres_persen']);
        });
    }

    public function down(): void
    {
        Schema::table('kegiatan', function (Blueprint $table) {
            $table->string('rincian_output')->nullable()->after('tahapan_survei');
            $table->string('volume_ro')->nullable()->after('rincian_output');
            $table->decimal('progres_persen', 5, 2)->nullable()->after('volume_ro');
        });

        $roPertama = DB::table('rincian_output')
            ->orderBy('kegiatan_id')
            ->orderBy('id')
            ->get()
            ->unique('kegiatan_id');

        foreach ($roPertama as $ro) {
            DB::table('kegiatan')->where('id', $ro->kegiatan_id)->update([
                'rincian_output' => $ro->uraian,
                'volume_ro' => $ro->volume_ro,
                'progres_persen' => $ro->progres_persen,
            ]);
        }

        Schema::dropIfExists('rincian_output');
    }
};
