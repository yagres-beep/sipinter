<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu bagian kustom sekarang bisa dipasang untuk BANYAK peran sekaligus (peran)
     * dan di BANYAK menu (penempatan), bukan hanya satu peran pengisi dengan satu menu.
     * Data lama dikonversi: ketua_tim -> menu Isian Kegiatan, Tim SAKIP/Kepala -> menu
     * Isian Bagian Kustom.
     */
    public function up(): void
    {
        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->json('peran')->nullable()->after('deskripsi');
            $table->json('penempatan')->nullable()->after('peran');
        });

        foreach (DB::table('bagian_kustom')->get() as $baris) {
            $peran = $baris->peran_pengisi ?: 'ketua_tim';
            $menu = $peran === 'ketua_tim' ? 'pengisian' : 'isian_bagian_kustom';

            DB::table('bagian_kustom')->where('id', $baris->id)->update([
                'peran' => json_encode([$peran]),
                'penempatan' => json_encode([$menu]),
            ]);
        }

        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->dropColumn('peran_pengisi');
        });
    }

    public function down(): void
    {
        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->string('peran_pengisi', 20)->default('ketua_tim')->after('deskripsi');
        });

        foreach (DB::table('bagian_kustom')->get() as $baris) {
            $daftar = json_decode((string) $baris->peran, true) ?: ['ketua_tim'];

            DB::table('bagian_kustom')->where('id', $baris->id)->update(['peran_pengisi' => $daftar[0]]);
        }

        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->dropColumn(['peran', 'penempatan']);
        });
    }
};
