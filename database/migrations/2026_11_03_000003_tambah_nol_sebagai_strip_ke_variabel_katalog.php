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
                if (! Schema::hasColumn('variabel_katalog', 'nol_sebagai_strip')) {
                    $table->boolean('nol_sebagai_strip')->default(false)->after('ribuan');
                }
            });
        });
    }

    public function down(): void
    {
        Schema::table('variabel_katalog', function (Blueprint $table) {
            $table->dropColumn('nol_sebagai_strip');
        });
    }
};
