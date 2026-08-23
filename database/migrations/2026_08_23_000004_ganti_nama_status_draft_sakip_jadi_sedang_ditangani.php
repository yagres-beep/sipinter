<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ganti nama status "draft_sakip" -> "sedang_ditangani" (istilah yang lebih
        // jelas: Tim SAKIP sudah mulai menandai/menyimpan sementara pemeriksaan ini —
        // lihat App\Models\Capaian::STATUS_SEDANG_DITANGANI). Baris yang SUDAH ada
        // dengan status lama ikut dipindahkan supaya tidak "hilang" dari constraint
        // baru maupun dari worklist Tim SAKIP.
        DB::table('capaian')->where('status', 'draft_sakip')->update(['status' => 'sedang_ditangani']);
        DB::table('riwayat_status_capaian')->where('status', 'draft_sakip')->update(['status' => 'sedang_ditangani']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE capaian DROP CONSTRAINT capaian_status_check');
            DB::statement("ALTER TABLE capaian ADD CONSTRAINT capaian_status_check CHECK (status IN ('draft', 'sedang_ditangani', 'diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'))");

            DB::statement('ALTER TABLE riwayat_status_capaian DROP CONSTRAINT riwayat_status_capaian_status_check');
            DB::statement("ALTER TABLE riwayat_status_capaian ADD CONSTRAINT riwayat_status_capaian_status_check CHECK (status IN ('sedang_ditangani', 'diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'))");

            return;
        }

        \Illuminate\Support\Facades\Schema::table('capaian', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->enum('status', ['draft', 'sedang_ditangani', 'diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'])
                ->default('draft')
                ->change();
        });

        \Illuminate\Support\Facades\Schema::table('riwayat_status_capaian', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->enum('status', ['sedang_ditangani', 'diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('capaian')->where('status', 'sedang_ditangani')->update(['status' => 'draft_sakip']);
        DB::table('riwayat_status_capaian')->where('status', 'sedang_ditangani')->update(['status' => 'draft_sakip']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE capaian DROP CONSTRAINT capaian_status_check');
            DB::statement("ALTER TABLE capaian ADD CONSTRAINT capaian_status_check CHECK (status IN ('draft', 'draft_sakip', 'diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'))");

            DB::statement('ALTER TABLE riwayat_status_capaian DROP CONSTRAINT riwayat_status_capaian_status_check');
            DB::statement("ALTER TABLE riwayat_status_capaian ADD CONSTRAINT riwayat_status_capaian_status_check CHECK (status IN ('draft_sakip', 'diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'))");

            return;
        }

        \Illuminate\Support\Facades\Schema::table('capaian', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->enum('status', ['draft', 'draft_sakip', 'diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'])
                ->default('draft')
                ->change();
        });

        \Illuminate\Support\Facades\Schema::table('riwayat_status_capaian', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->enum('status', ['draft_sakip', 'diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'])->change();
        });
    }
};
