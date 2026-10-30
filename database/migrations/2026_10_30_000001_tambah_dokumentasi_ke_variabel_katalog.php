<?php

use App\Support\MigrasiKunciSingkat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hanya MENAMBAH kolom nullable ke variabel_katalog — tidak ada data lama yang diubah
     * atau dihapus. Dokumentasi variabel buatan pengguna (definisi bersama kolom isian):
     * - kategori: pengelompokan di halaman Katalog Variabel (NULL = "Bagian Kustom").
     * - contoh: contoh nilai untuk dokumentasi (bukan data sungguhan).
     * - catatan: aturan penggunaan & keterbatasan.
     * - pengganti_kode: kode variabel pengganti bila variabel ini tidak disarankan lagi.
     *
     * Variabel SISTEM tidak disimpan di tabel ini (lihat App\Support\RegistriVariabel).
     */
    public $withinTransaction = false;

    public function up(): void
    {
        MigrasiKunciSingkat::jalankan(function () {
            Schema::table('variabel_katalog', function (Blueprint $table) {
                if (! Schema::hasColumn('variabel_katalog', 'kategori')) {
                    $table->string('kategori', 40)->nullable();
                }

                if (! Schema::hasColumn('variabel_katalog', 'contoh')) {
                    $table->string('contoh', 255)->nullable();
                }

                if (! Schema::hasColumn('variabel_katalog', 'catatan')) {
                    $table->text('catatan')->nullable();
                }

                if (! Schema::hasColumn('variabel_katalog', 'pengganti_kode')) {
                    $table->string('pengganti_kode', 60)->nullable();
                }
            });
        });
    }

    public function down(): void
    {
        Schema::table('variabel_katalog', function (Blueprint $table) {
            $table->dropColumn(['kategori', 'contoh', 'catatan', 'pengganti_kode']);
        });
    }
};
