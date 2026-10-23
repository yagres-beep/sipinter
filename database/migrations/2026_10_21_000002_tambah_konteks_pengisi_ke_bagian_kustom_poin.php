<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Konteks tiap poin bagian kustom: di menu mana dan oleh peran apa poin diisi,
     * serta siapa yang membuat/terakhir mengubahnya. Dipakai untuk memisahkan data
     * Ketua Tim (Isian Kegiatan), Tim SAKIP, dan Kepala pada bagian yang sama.
     *
     * Semua kolom nullable dan data lama TIDAK diubah. Poin lama (menu NULL) dibaca
     * sebagai isian Ketua Tim di Isian Kegiatan, karena sebelum fitur peran &
     * penempatan menu hanya jalur itu yang membuat poin (lihat
     * BagianKustomPoin::scopeKonteksIsianKegiatan()).
     */
    public function up(): void
    {
        Schema::table('bagian_kustom_poin', function (Blueprint $table) {
            if (! Schema::hasColumn('bagian_kustom_poin', 'menu')) {
                $table->string('menu', 40)->nullable()->after('periode_id');
            }

            if (! Schema::hasColumn('bagian_kustom_poin', 'peran_pengisi')) {
                $table->string('peran_pengisi', 20)->nullable()->after('menu');
            }

            if (! Schema::hasColumn('bagian_kustom_poin', 'diisi_oleh')) {
                $table->foreignId('diisi_oleh')->nullable()->after('peran_pengisi')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('bagian_kustom_poin', 'diubah_oleh')) {
                $table->foreignId('diubah_oleh')->nullable()->after('diisi_oleh')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('bagian_kustom_poin', function (Blueprint $table) {
            $table->index(['bagian_kustom_id', 'periode_id', 'menu', 'peran_pengisi'], 'bagian_kustom_poin_konteks_index');
        });
    }

    public function down(): void
    {
        Schema::table('bagian_kustom_poin', function (Blueprint $table) {
            $table->dropIndex('bagian_kustom_poin_konteks_index');
            $table->dropConstrainedForeignId('diubah_oleh');
            $table->dropConstrainedForeignId('diisi_oleh');
            $table->dropColumn(['menu', 'peran_pengisi']);
        });
    }
};
