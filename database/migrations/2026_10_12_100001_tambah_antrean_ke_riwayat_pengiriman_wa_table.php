<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riwayat_pengiriman_wa', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->string('jenis', 50)->nullable()->after('user_id');
            $table->string('idempotency_key', 191)->nullable()->unique()->after('jenis');
            $table->string('status', 20)->nullable()->index()->after('berhasil');
            $table->unsignedSmallInteger('percobaan_kirim')->default(0)->after('status');
            $table->timestamp('diserahkan_ke_gateway_at')->nullable();
            $table->timestamp('terkirim_at')->nullable();
            $table->timestamp('diterima_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // Baris dari gateway lama (sebelum fitur ini) hanya arsip: tanpa
        // idempotency_key & berstatus 'arsip' sehingga tidak pernah diproses ulang.
        DB::table('riwayat_pengiriman_wa')->whereNull('status')->update(['status' => 'arsip']);
    }

    public function down(): void
    {
        Schema::table('riwayat_pengiriman_wa', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'jenis', 'idempotency_key', 'status', 'percobaan_kirim',
                'diserahkan_ke_gateway_at', 'terkirim_at', 'diterima_at', 'updated_at',
            ]);
        });
    }
};
