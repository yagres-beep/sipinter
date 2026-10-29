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
     * bagian_kustom:
     * - alur (JSON): alur kerja isian di menu selain Isian Kegiatan (langsung sah, atau
     *   draf → diajukan → disetujui/dikembalikan), siapa pengaju & pemeriksa, dan isian
     *   mana yang dicetak di notula. NULL = perilaku lama (langsung sah, notula semua).
     * - periode_berlaku (JSON): triwulan & rentang tahun bagian ini tampil untuk diisi.
     *   NULL = selalu tampil (perilaku lama).
     *
     * bagian_kustom_poin: jejak pengajuan & pemeriksaan. Status tetap memakai kolom lama
     * status_verifikasi (enum tidak diubah); draf = 'menunggu' tanpa diajukan_pada.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        MigrasiKunciSingkat::jalankan(function () {
            Schema::table('bagian_kustom', function (Blueprint $table) {
                if (! Schema::hasColumn('bagian_kustom', 'alur')) {
                    $table->json('alur')->nullable();
                }

                if (! Schema::hasColumn('bagian_kustom', 'periode_berlaku')) {
                    $table->json('periode_berlaku')->nullable();
                }
            });
        });

        MigrasiKunciSingkat::jalankan(function () {
            Schema::table('bagian_kustom_poin', function (Blueprint $table) {
                if (! Schema::hasColumn('bagian_kustom_poin', 'diajukan_pada')) {
                    $table->timestamp('diajukan_pada')->nullable();
                }

                if (! Schema::hasColumn('bagian_kustom_poin', 'diajukan_oleh')) {
                    $table->foreignId('diajukan_oleh')->nullable()->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn('bagian_kustom_poin', 'diperiksa_pada')) {
                    $table->timestamp('diperiksa_pada')->nullable();
                }

                if (! Schema::hasColumn('bagian_kustom_poin', 'diperiksa_oleh')) {
                    $table->foreignId('diperiksa_oleh')->nullable()->constrained('users')->nullOnDelete();
                }
            });
        });
    }

    public function down(): void
    {
        Schema::table('bagian_kustom_poin', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diperiksa_oleh');
            $table->dropConstrainedForeignId('diajukan_oleh');
            $table->dropColumn(['diajukan_pada', 'diperiksa_pada']);
        });

        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->dropColumn(['alur', 'periode_berlaku']);
        });
    }
};
