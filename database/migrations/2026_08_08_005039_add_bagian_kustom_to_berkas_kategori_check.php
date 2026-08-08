<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah nilai enum baru 'bagian_kustom' ke kolom berkas.kategori. Laravel/Postgres
     * merepresentasikan enum() sebagai CHECK constraint (bukan tipe ENUM asli). Postgres
     * tidak punya perintah "alter enum" langsung lewat Schema Builder, jadi drop lalu
     * buat ulang constraint-nya via SQL mentah (nama constraint mengikuti konvensi
     * default Postgres untuk constraint tak-bernama: {table}_{column}_check). Untuk
     * driver lain (SQLite, dipakai saat testing) cukup pakai change() bawaan Laravel —
     * SQLite JUGA menegakkan CHECK constraint pada enum(), tapi tidak mengenal sintaks
     * ALTER TABLE...DROP CONSTRAINT Postgres.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE berkas DROP CONSTRAINT IF EXISTS berkas_kategori_check');
            DB::statement("ALTER TABLE berkas ADD CONSTRAINT berkas_kategori_check CHECK (kategori IN ('capaian', 'solusi', 'evaluasi_rtl', 'bukti_dukung_sakip', 'notula', 'bagian_kustom'))");

            return;
        }

        Schema::table('berkas', function (Blueprint $table) {
            $table->enum('kategori', ['capaian', 'solusi', 'evaluasi_rtl', 'bukti_dukung_sakip', 'notula', 'bagian_kustom'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE berkas DROP CONSTRAINT IF EXISTS berkas_kategori_check');
            DB::statement("ALTER TABLE berkas ADD CONSTRAINT berkas_kategori_check CHECK (kategori IN ('capaian', 'solusi', 'evaluasi_rtl', 'bukti_dukung_sakip', 'notula'))");

            return;
        }

        Schema::table('berkas', function (Blueprint $table) {
            $table->enum('kategori', ['capaian', 'solusi', 'evaluasi_rtl', 'bukti_dukung_sakip', 'notula'])->change();
        });
    }
};
