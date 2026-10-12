<?php

use App\Models\Capaian;
use App\Models\Periode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kode publik pengajuan IKU (mis. "IKU-1111-202610-K7QM") untuk URL
     * /verifikasi/{kode}, menggantikan id urut — lihat Capaian::buatKode().
     * Capaian lama diisi di sini dari kode IKU & periodenya; yang baru otomatis
     * lewat hook creating di model.
     */
    public function up(): void
    {
        Schema::table('capaian', function (Blueprint $table) {
            $table->string('kode', 48)->nullable()->unique()->after('id');
        });

        DB::table('capaian')
            ->leftJoin('master_iku', 'master_iku.id', '=', 'capaian.iku_id')
            ->whereNull('capaian.kode')
            ->orderBy('capaian.id')
            ->select(['capaian.id', 'capaian.periode_id', 'master_iku.kode as kode_iku'])
            ->each(function ($baris) {
                DB::table('capaian')->where('id', $baris->id)->update([
                    'kode' => Capaian::buatKode($baris->kode_iku, Periode::find($baris->periode_id)),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('capaian', function (Blueprint $table) {
            $table->dropUnique(['kode']);
            $table->dropColumn('kode');
        });
    }
};
