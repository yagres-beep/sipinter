<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sebelumnya status "Terhubung" di menu Akun & Storage hanya berarti "ada refresh
     * token tersimpan" — token yang sudah DITOLAK Google (invalid_grant: kedaluwarsa
     * 7 hari saat consent screen masih Testing, dicabut pemilik akun, dst.) tetap
     * tampil Terhubung sampai unggahan gagal. Kolom-kolom ini mencatat hasil
     * pemeriksaan sambungan yang sebenarnya (lihat GoogleDriveService::clientOAuthUntukAkun()
     * & perintah google:cek-sambungan) supaya putusnya sambungan langsung terlihat.
     */
    public function up(): void
    {
        Schema::table('storage_account', function (Blueprint $table) {
            $table->timestamp('google_terhubung_pada')->nullable()->after('google_token_expires_at');
            $table->timestamp('google_dicek_pada')->nullable()->after('google_terhubung_pada');
            $table->timestamp('google_galat_pada')->nullable()->after('google_dicek_pada');
            $table->text('google_galat')->nullable()->after('google_galat_pada');
        });
    }

    public function down(): void
    {
        Schema::table('storage_account', function (Blueprint $table) {
            $table->dropColumn(['google_terhubung_pada', 'google_dicek_pada', 'google_galat_pada', 'google_galat']);
        });
    }
};
