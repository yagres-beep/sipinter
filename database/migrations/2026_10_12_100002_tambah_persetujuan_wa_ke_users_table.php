<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Default false: pengguna lama tidak menerima pesan WhatsApp otomatis
        // sampai menyetujuinya sendiri di halaman Profil.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('wa_opt_in')->default(false)->after('nomor_telepon');
            $table->timestamp('wa_opt_in_at')->nullable()->after('wa_opt_in');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['wa_opt_in', 'wa_opt_in_at']);
        });
    }
};
