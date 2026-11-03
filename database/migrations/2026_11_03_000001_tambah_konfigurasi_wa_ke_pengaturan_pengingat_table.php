<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengaturan_pengingat', function (Blueprint $table) {
            $table->boolean('wa_pengingat_aktif')->nullable()->default(null)->after('deadline_h_minus');
            $table->string('wa_gateway_url', 500)->nullable()->default(null)->after('wa_pengingat_aktif');
            $table->text('wa_gateway_token')->nullable()->default(null)->after('wa_gateway_url');
        });
    }

    public function down(): void
    {
        Schema::table('pengaturan_pengingat', function (Blueprint $table) {
            $table->dropColumn(['wa_pengingat_aktif', 'wa_gateway_url', 'wa_gateway_token']);
        });
    }
};
