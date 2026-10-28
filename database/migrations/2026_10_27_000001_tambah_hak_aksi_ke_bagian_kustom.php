<?php

use App\Support\MigrasiKunciSingkat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hanya MENAMBAH kolom JSON `hak_aksi` ke bagian_kustom: aksi yang boleh dilakukan
     * tiap peran pengisi (buat, ubah, hapus, unggah_bukti, hapus_bukti).
     *
     * NULL (semua bagian lama) = perilaku lama: setiap peran pengisi boleh semua aksi.
     * Tidak ada data lama yang diubah; kolom `peran` dan `peran_pembaca` tetap dipakai.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        MigrasiKunciSingkat::jalankan(function () {
            Schema::table('bagian_kustom', function (Blueprint $table) {
                if (! Schema::hasColumn('bagian_kustom', 'hak_aksi')) {
                    $table->json('hak_aksi')->nullable()->after('peran_pembaca');
                }
            });
        });
    }

    public function down(): void
    {
        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->dropColumn('hak_aksi');
        });
    }
};
