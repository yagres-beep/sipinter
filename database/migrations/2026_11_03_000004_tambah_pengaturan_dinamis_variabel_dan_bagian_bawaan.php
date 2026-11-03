<?php

use App\Support\MigrasiKunciSingkat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        MigrasiKunciSingkat::jalankan(function () {
            Schema::table('variabel_katalog', function (Blueprint $table) {
                if (! Schema::hasColumn('variabel_katalog', 'nilai_override')) {
                    $table->text('nilai_override')->nullable()->after('opsi');
                }
            });

            Schema::table('pengaturan_bagian_bawaan', function (Blueprint $table) {
                if (! Schema::hasColumn('pengaturan_bagian_bawaan', 'masuk_notula')) {
                    $table->boolean('masuk_notula')->default(true)->after('petunjuk');
                }
                if (! Schema::hasColumn('pengaturan_bagian_bawaan', 'judul_notula')) {
                    $table->string('judul_notula', 120)->nullable()->after('masuk_notula');
                }
                if (! Schema::hasColumn('pengaturan_bagian_bawaan', 'notula_periode')) {
                    $table->string('notula_periode', 20)->default('triwulan')->after('judul_notula');
                }
                if (! Schema::hasColumn('pengaturan_bagian_bawaan', 'aktif')) {
                    $table->boolean('aktif')->default(true)->after('notula_periode');
                }
                if (! Schema::hasColumn('pengaturan_bagian_bawaan', 'catatan')) {
                    $table->text('catatan')->nullable()->after('aktif');
                }
            });
        });
    }

    public function down(): void
    {
        Schema::table('variabel_katalog', function (Blueprint $table) {
            $table->dropColumn('nilai_override');
        });

        Schema::table('pengaturan_bagian_bawaan', function (Blueprint $table) {
            $table->dropColumn(['masuk_notula', 'judul_notula', 'notula_periode', 'aktif', 'catatan']);
        });
    }
};
