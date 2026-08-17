<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sebelumnya "akun master folder" hanya bisa ditetapkan lewat .env
     * (GOOGLE_DRIVE_MASTER_ACCOUNT_EMAIL) — kolom ini memindahkan penetapannya ke
     * database supaya Tim SAKIP bisa mengubahnya lewat menu Akun & Storage tanpa
     * edit berkas .env. Baris yang emailnya sudah cocok dengan .env lama langsung
     * ditandai master di sini supaya instalasi yang sudah terlanjur pakai .env
     * tidak kehilangan pengaturannya (lihat StorageAccount::master() untuk fallback
     * ke .env selama belum ada satu pun baris is_master = true).
     */
    public function up(): void
    {
        Schema::table('storage_account', function (Blueprint $table) {
            $table->boolean('is_master')->default(false)->after('drive_folder_id');
        });

        $emailEnv = env('GOOGLE_DRIVE_MASTER_ACCOUNT_EMAIL');

        if ($emailEnv) {
            DB::table('storage_account')
                ->whereRaw('LOWER(email_gmail_institusi) = ?', [strtolower($emailEnv)])
                ->update(['is_master' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('storage_account', function (Blueprint $table) {
            $table->dropColumn('is_master');
        });
    }
};
