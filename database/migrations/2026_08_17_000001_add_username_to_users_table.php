<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->after('nama');
        });

        // Backfill username dari bagian depan email yang sudah ada, hindari duplikat.
        $terpakai = [];
        foreach (DB::table('users')->orderBy('id')->get(['id', 'email']) as $user) {
            $dasar = Str::slug(Str::before($user->email, '@'), '_') ?: 'pengguna'.$user->id;
            $kandidat = $dasar;
            $n = 1;
            while (in_array($kandidat, $terpakai, true)) {
                $kandidat = $dasar.$n;
                $n++;
            }
            $terpakai[] = $kandidat;
            DB::table('users')->where('id', $user->id)->update(['username' => $kandidat]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable(false)->change();
            $table->string('email')->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
