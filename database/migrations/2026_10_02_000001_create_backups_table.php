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
        // Riwayat backup database (fitur Backup Database, menu Kelola Pengguna).
        // Satu baris = satu kali proses backup (RF baru) -- berisi dump SQL penuh
        // database aplikasi (bukan berkas bukti dukung, yang sudah tersimpan di
        // Google Drive lewat tabel berkas).
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('nama_file');
            // Path lokal (disk 'local') tempat dump disimpan sementara -- TIDAK
            // persisten di Render free plan (terhapus tiap deploy ulang), karena itu
            // juga diarsipkan ke Drive lewat drive_file_id di bawah (sama seperti
            // berkas & template notula, lihat FolderStructureService::unggahBackupDatabase()).
            $table->string('path')->nullable();
            $table->unsignedBigInteger('ukuran_bytes')->nullable();
            $table->string('drive_file_id')->nullable();
            // Nullable -- akun storage bisa saja dihapus belakangan, riwayat backup tetap ada.
            $table->foreignId('storage_account_id')->nullable()->constrained('storage_account')->nullOnDelete();
            // 'gagal' dicatat tetap sebagai satu baris (bukan dibuang) supaya Tim SAKIP
            // tahu ada upaya backup yang gagal dan kenapa (lihat kolom catatan).
            $table->enum('status', ['berhasil', 'gagal'])->default('berhasil');
            $table->text('catatan')->nullable();
            // Nullable -- user yang menghapus akun tidak ikut menghapus jejak riwayat backup.
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
