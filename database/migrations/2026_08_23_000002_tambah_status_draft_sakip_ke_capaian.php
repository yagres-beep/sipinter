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
        // Status baru "draft_sakip" (Draft SAKIP) — checkpoint sementara saat Tim
        // SAKIP sudah mulai menandai sebagian berkas diterima/ditolak tapi belum
        // memutuskan hasil akhirnya (Verifikasi Selesai atau Kembalikan ke Ketua
        // Tim), lewat aksi "Simpan Sementara". Lihat App\Models\Capaian::
        // STATUS_DRAFT_SAKIP, App\Livewire\VerifikasiCapaian::simpanSementara().
        //
        // Postgres MENOLAK "ALTER COLUMN ... TYPE ... CHECK (...)" digabung dalam
        // satu klausa (itulah yang dihasilkan ->change() bawaan Laravel untuk
        // enum) — CHECK constraint di Postgres wajib lewat ADD CONSTRAINT
        // terpisah, jadi drop+add constraint manual di sini utk pgsql. SQLite
        // (dipakai tes) tidak kenal DROP CONSTRAINT sama sekali — tapi ->change()
        // bawaan Laravel SUDAH menangani itu dengan rebuild tabel otomatis,
        // makanya tetap dipakai apa adanya di cabang sqlite.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE capaian DROP CONSTRAINT capaian_status_check');
            DB::statement("ALTER TABLE capaian ADD CONSTRAINT capaian_status_check CHECK (status IN ('draft', 'draft_sakip', 'diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'))");

            DB::statement('ALTER TABLE riwayat_status_capaian DROP CONSTRAINT riwayat_status_capaian_status_check');
            DB::statement("ALTER TABLE riwayat_status_capaian ADD CONSTRAINT riwayat_status_capaian_status_check CHECK (status IN ('draft_sakip', 'diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'))");

            return;
        }

        Schema::table('capaian', function (Blueprint $table) {
            $table->enum('status', ['draft', 'draft_sakip', 'diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'])
                ->default('draft')
                ->change();
        });

        Schema::table('riwayat_status_capaian', function (Blueprint $table) {
            $table->enum('status', ['draft_sakip', 'diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Tarik baris "draft_sakip" (kalau ada) ke "diajukan" dulu — itulah status
        // sebelum Tim SAKIP mulai menandai apa pun — supaya constraint versi lama
        // (tanpa draft_sakip) tidak gagal dipasang gara-gara data yang sudah ada.
        DB::table('capaian')->where('status', 'draft_sakip')->update(['status' => 'diajukan']);
        DB::table('riwayat_status_capaian')->where('status', 'draft_sakip')->update(['status' => 'diajukan']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE capaian DROP CONSTRAINT capaian_status_check');
            DB::statement("ALTER TABLE capaian ADD CONSTRAINT capaian_status_check CHECK (status IN ('draft', 'diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'))");

            DB::statement('ALTER TABLE riwayat_status_capaian DROP CONSTRAINT riwayat_status_capaian_status_check');
            DB::statement("ALTER TABLE riwayat_status_capaian ADD CONSTRAINT riwayat_status_capaian_status_check CHECK (status IN ('diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'))");

            return;
        }

        Schema::table('capaian', function (Blueprint $table) {
            $table->enum('status', ['draft', 'diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'])
                ->default('draft')
                ->change();
        });

        Schema::table('riwayat_status_capaian', function (Blueprint $table) {
            $table->enum('status', ['diajukan', 'diverifikasi', 'dikembalikan', 'disetujui'])->change();
        });
    }
};
