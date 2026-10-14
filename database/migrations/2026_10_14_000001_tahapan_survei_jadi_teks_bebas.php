<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tahapan kegiatan tidak lagi terbatas pada Persiapan/Pelaksanaan/Pengolahan/
     * Diseminasi — Ketua Tim bisa menambah tahapan sendiri (lihat
     * PengisianKegiatan::tambahTahapan()), jadi kolomnya jadi teks bebas. Postgres
     * menyimpan enum() sebagai CHECK constraint ({table}_{column}_check) yang harus
     * dibuang manual; SQLite (tes) cukup lewat change() yang me-rebuild tabel.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE kegiatan DROP CONSTRAINT IF EXISTS kegiatan_tahapan_survei_check');
            DB::statement('ALTER TABLE kegiatan ALTER COLUMN tahapan_survei TYPE VARCHAR(100)');

            return;
        }

        Schema::table('kegiatan', function (Blueprint $table) {
            $table->string('tahapan_survei', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('kegiatan')
            ->whereNotNull('tahapan_survei')
            ->whereNotIn('tahapan_survei', ['persiapan', 'pelaksanaan', 'pengolahan', 'diseminasi'])
            ->update(['tahapan_survei' => null]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE kegiatan ADD CONSTRAINT kegiatan_tahapan_survei_check CHECK (tahapan_survei IN ('persiapan', 'pelaksanaan', 'pengolahan', 'diseminasi'))");

            return;
        }

        Schema::table('kegiatan', function (Blueprint $table) {
            $table->enum('tahapan_survei', ['persiapan', 'pelaksanaan', 'pengolahan', 'diseminasi'])->nullable()->change();
        });
    }
};
