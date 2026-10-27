<?php

use App\Support\MigrasiKunciSingkat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relasi isian antarperiode, hanya MENAMBAH kolom nullable ke bagian_kustom_poin:
     * - lanjutan_dari_id: poin periode sebelumnya yang dilanjutkan oleh poin ini.
     * - akar_id: poin PERTAMA dari rangkaian lanjutan (identitas objek yang tetap
     *   antarperiode). NULL = poin ini sendiri adalah akarnya (semua poin lama).
     *
     * Satu poin hanya boleh dilanjutkan sekali ke periode yang sama (unique) — mencegah
     * salinan ganda walau dua pengguna menekan "Lanjutkan" bersamaan. Kunci asing tanpa
     * aksi hapus (NO ACTION): poin yang sudah dilanjutkan tidak bisa dihapus sendirian,
     * jadi relasi riwayat tidak terputus diam-diam.
     *
     * Kunci tabel ditunggu sebentar lalu dicoba ulang (MigrasiKunciSingkat); semua
     * langkah dalam satu transaksi per percobaan.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        MigrasiKunciSingkat::jalankan(function () {
            Schema::table('bagian_kustom_poin', function (Blueprint $table) {
                if (! Schema::hasColumn('bagian_kustom_poin', 'lanjutan_dari_id')) {
                    $table->foreignId('lanjutan_dari_id')->nullable()->after('periode_id')->constrained('bagian_kustom_poin');
                }

                if (! Schema::hasColumn('bagian_kustom_poin', 'akar_id')) {
                    $table->foreignId('akar_id')->nullable()->after('lanjutan_dari_id')->constrained('bagian_kustom_poin');
                }
            });

            Schema::table('bagian_kustom_poin', function (Blueprint $table) {
                $table->unique(['lanjutan_dari_id', 'periode_id'], 'bagian_kustom_poin_lanjutan_unik');
                $table->index('akar_id', 'bagian_kustom_poin_akar_index');
            });
        });
    }

    public function down(): void
    {
        Schema::table('bagian_kustom_poin', function (Blueprint $table) {
            $table->dropUnique('bagian_kustom_poin_lanjutan_unik');
            $table->dropIndex('bagian_kustom_poin_akar_index');
            $table->dropConstrainedForeignId('akar_id');
            $table->dropConstrainedForeignId('lanjutan_dari_id');
        });
    }
};
