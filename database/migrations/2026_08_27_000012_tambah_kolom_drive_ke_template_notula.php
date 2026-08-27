<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template notula (RF-41) sejauh ini HANYA tersimpan di disk lokal server —
 * berbeda dari Berkas (bukti dukung) & arsip PDF notula final yang sejak awal
 * juga diarsipkan ke Google Drive lewat GoogleDriveService (lihat catatan di
 * BerkasDownloadController::show() & NotulaService::setujui()). Disk lokal
 * TIDAK persisten di Render free plan (terhapus tiap container di-deploy
 * ulang), sehingga template yang sudah diunggah bisa "hilang" walau baris
 * folder_config (di Postgres, persisten) masih menunjuknya — unduh() lalu
 * abort 404 walau UI masih menampilkan berkasnya sebagai tersimpan.
 *
 * Kolom ini menyimpan rujukan salinan di Drive (pola sama seperti
 * berkas.drive_file_id + berkas.storage_account_id) supaya TemplateNotula
 * bisa jatuh ke Drive sebagai sumber cadangan persisten saat salinan lokalnya
 * sudah tidak ada, sama seperti BerkasDownloadController::show().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folder_config', function (Blueprint $table) {
            $table->string('template_notula_drive_file_id')->nullable()->after('template_notula_nama_asli');
            $table->foreignId('template_notula_storage_account_id')->nullable()->after('template_notula_drive_file_id')
                ->constrained('storage_account')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('folder_config', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_notula_storage_account_id');
            $table->dropColumn('template_notula_drive_file_id');
        });
    }
};
