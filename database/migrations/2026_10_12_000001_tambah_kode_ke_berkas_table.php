<?php

use App\Models\Berkas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kode publik berkas (mis. "CPN-202610-K7QM-2X9A") untuk URL pratinjau
     * /berkas/{kode}/buka, menggantikan id urut yang gampang ditebak — lihat
     * Berkas::buatKode(). Berkas lama diisi kodenya di sini (periode diambil dari
     * created_at-nya), berkas baru otomatis lewat hook creating di model.
     */
    public function up(): void
    {
        Schema::table('berkas', function (Blueprint $table) {
            $table->string('kode', 32)->nullable()->unique()->after('id');
        });

        DB::table('berkas')->whereNull('kode')->orderBy('id')->select(['id', 'kategori', 'created_at'])
            ->each(function ($baris) {
                DB::table('berkas')->where('id', $baris->id)->update([
                    'kode' => Berkas::buatKode($baris->kategori, $baris->created_at ? Carbon::parse($baris->created_at) : null),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('berkas', function (Blueprint $table) {
            $table->dropUnique(['kode']);
            $table->dropColumn('kode');
        });
    }
};
