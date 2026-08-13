<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Google melarang Service Account menulis berkas baru ke folder yang di-*share*
     * dari akun Gmail biasa (bukan Shared Drive) — akun tsb tidak pernah punya kuota
     * penyimpanan sendiri. Karena akun institusi SIPINTER adalah Gmail biasa (bukan
     * Google Workspace), satu-satunya jalan adalah OAuth2: Tim SAKIP login sekali ke
     * akun tsb, dan aplikasi mengunggah berkas SEBAGAI akun itu (pakai kuota 15GB
     * pribadinya) memakai token yang disimpan di kolom-kolom baru ini.
     */
    public function up(): void
    {
        Schema::table('storage_account', function (Blueprint $table) {
            $table->text('google_access_token')->nullable()->after('drive_folder_id');
            // Dienkripsi lewat cast 'encrypted' di StorageAccount — lihat app/Models/StorageAccount.php.
            $table->text('google_refresh_token')->nullable()->after('google_access_token');
            $table->timestamp('google_token_expires_at')->nullable()->after('google_refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('storage_account', function (Blueprint $table) {
            $table->dropColumn(['google_access_token', 'google_refresh_token', 'google_token_expires_at']);
        });
    }
};
