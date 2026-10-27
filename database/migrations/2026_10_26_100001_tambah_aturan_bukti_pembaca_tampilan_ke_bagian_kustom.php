<?php

use App\Support\MigrasiKunciSingkat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hanya MENAMBAH kolom JSON ke bagian_kustom. Data lama tidak diubah:
     * - aturan_bukti: aturan bukti dukung (tidak perlu/opsional/wajib, jumlah, format,
     *   ukuran, petunjuk). NULL = perilaku lama dari kolom bukti_wajib (lihat
     *   BagianKustom::aturanBukti()).
     * - peran_pembaca: peran yang hanya boleh MELIHAT isian bagian ini. NULL = tidak ada.
     * - tampilan: bentuk formulir isian (kartu/tabel) dan bentuk baca (daftar/tabel/
     *   paragraf). NULL = bentuk lama (kartu + daftar).
     *
     * Kunci tabel ditunggu sebentar lalu dicoba ulang (MigrasiKunciSingkat) supaya
     * deploy tidak menggantung bila tabel sedang dipakai instance lama.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        MigrasiKunciSingkat::jalankan(function () {
            Schema::table('bagian_kustom', function (Blueprint $table) {
                if (! Schema::hasColumn('bagian_kustom', 'aturan_bukti')) {
                    $table->json('aturan_bukti')->nullable()->after('bukti_wajib');
                }

                if (! Schema::hasColumn('bagian_kustom', 'peran_pembaca')) {
                    $table->json('peran_pembaca')->nullable()->after('peran');
                }

                if (! Schema::hasColumn('bagian_kustom', 'tampilan')) {
                    $table->json('tampilan')->nullable()->after('subbagian');
                }
            });
        });
    }

    public function down(): void
    {
        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->dropColumn(['aturan_bukti', 'peran_pembaca', 'tampilan']);
        });
    }
};
