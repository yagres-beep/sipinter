<?php

use App\Support\MigrasiKunciSingkat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hanya MENAMBAH kolom nullable — tidak ada data lama yang diubah atau dihapus.
     *
     * bagian_kustom.versi_konfigurasi: nomor versi definisi bagian, naik satu setiap kali
     *   definisinya diubah. NULL = versi 1 (bagian lama yang belum pernah diubah).
     * bagian_kustom_poin.versi_konfigurasi: versi definisi saat isi poin terakhir disimpan
     *   (penelusuran data lama terhadap struktur formulirnya). NULL = data sebelum fitur ini.
     * bagian_kustom_poin.tahap_periksa: tahap pemeriksaan yang sedang menunggu (alur
     *   persetujuan berjenjang). NULL = tahap 1 / alur satu tahap (perilaku lama).
     * riwayat_konfigurasi_bagian_kustom.versi: versi definisi SETELAH perubahan dicatat.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        MigrasiKunciSingkat::jalankan(function () {
            Schema::table('bagian_kustom', function (Blueprint $table) {
                if (! Schema::hasColumn('bagian_kustom', 'versi_konfigurasi')) {
                    $table->unsignedInteger('versi_konfigurasi')->nullable();
                }
            });
        });

        MigrasiKunciSingkat::jalankan(function () {
            Schema::table('bagian_kustom_poin', function (Blueprint $table) {
                if (! Schema::hasColumn('bagian_kustom_poin', 'versi_konfigurasi')) {
                    $table->unsignedInteger('versi_konfigurasi')->nullable();
                }

                if (! Schema::hasColumn('bagian_kustom_poin', 'tahap_periksa')) {
                    $table->unsignedSmallInteger('tahap_periksa')->nullable();
                }
            });
        });

        MigrasiKunciSingkat::jalankan(function () {
            Schema::table('riwayat_konfigurasi_bagian_kustom', function (Blueprint $table) {
                if (! Schema::hasColumn('riwayat_konfigurasi_bagian_kustom', 'versi')) {
                    $table->unsignedInteger('versi')->nullable();
                }
            });
        });
    }

    public function down(): void
    {
        Schema::table('riwayat_konfigurasi_bagian_kustom', function (Blueprint $table) {
            $table->dropColumn('versi');
        });

        Schema::table('bagian_kustom_poin', function (Blueprint $table) {
            $table->dropColumn(['versi_konfigurasi', 'tahap_periksa']);
        });

        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->dropColumn('versi_konfigurasi');
        });
    }
};
