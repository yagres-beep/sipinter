<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->text('dasar_hitung')->nullable()->after('sasaran');
            $table->string('basis_data')->nullable()->after('dasar_hitung');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('master_iku', function (Blueprint $table) {
            $table->dropColumn(['dasar_hitung', 'basis_data']);
        });
    }
};
