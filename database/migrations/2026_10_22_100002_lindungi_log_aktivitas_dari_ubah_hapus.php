<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Perlindungan tingkat database untuk log_aktivitas: baris log hanya boleh DITAMBAH.
 * UPDATE dan DELETE (dan TRUNCATE di PostgreSQL) ditolak trigger, termasuk lewat query
 * builder / DB::table / SQL mentah dari aplikasi — bukan hanya lewat model Eloquent.
 *
 * Batasan (didokumentasikan, bukan janji kekebalan mutlak): pemilik tabel atau
 * administrator database dengan hak tinggi tetap bisa menonaktifkan/menghapus trigger
 * ini lalu mengubah log. Perlindungan ini menahan kesalahan kode dan penyalahgunaan
 * lewat aplikasi, bukan penyalahgunaan kredensial admin database.
 *
 * Hanya menambah fungsi + trigger; tidak mengubah data atau tabel lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => $this->upPgsql(),
            'sqlite' => $this->upSqlite(),
            default => null,
        };
    }

    public function down(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS log_aktivitas_tanpa_ubah_hapus ON log_aktivitas;
                DROP TRIGGER IF EXISTS log_aktivitas_tanpa_truncate ON log_aktivitas;
                DROP FUNCTION IF EXISTS log_aktivitas_tolak_ubah();
                SQL),
            'sqlite' => DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS log_aktivitas_tanpa_ubah;
                DROP TRIGGER IF EXISTS log_aktivitas_tanpa_hapus;
                SQL),
            default => null,
        };
    }

    protected function upPgsql(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION log_aktivitas_tolak_ubah() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = ''
            AS $$
            BEGIN
                RAISE EXCEPTION 'log_aktivitas hanya boleh ditambah; % ditolak', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            DROP TRIGGER IF EXISTS log_aktivitas_tanpa_ubah_hapus ON log_aktivitas;
            CREATE TRIGGER log_aktivitas_tanpa_ubah_hapus
                BEFORE UPDATE OR DELETE ON log_aktivitas
                FOR EACH ROW EXECUTE FUNCTION log_aktivitas_tolak_ubah();

            DROP TRIGGER IF EXISTS log_aktivitas_tanpa_truncate ON log_aktivitas;
            CREATE TRIGGER log_aktivitas_tanpa_truncate
                BEFORE TRUNCATE ON log_aktivitas
                FOR EACH STATEMENT EXECUTE FUNCTION log_aktivitas_tolak_ubah();
            SQL);
    }

    protected function upSqlite(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS log_aktivitas_tanpa_ubah
                BEFORE UPDATE ON log_aktivitas
                BEGIN SELECT RAISE(ABORT, 'log_aktivitas hanya boleh ditambah; UPDATE ditolak'); END;

            CREATE TRIGGER IF NOT EXISTS log_aktivitas_tanpa_hapus
                BEFORE DELETE ON log_aktivitas
                BEGIN SELECT RAISE(ABORT, 'log_aktivitas hanya boleh ditambah; DELETE ditolak'); END;
            SQL);
    }
};
