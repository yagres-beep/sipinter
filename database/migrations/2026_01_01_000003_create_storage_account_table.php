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
        Schema::create('storage_account', function (Blueprint $table) {
            $table->id();
            $table->string('email_gmail_institusi')->unique();
            // 'aktif'  = akun yang sedang dipakai untuk menampung UNGGAHAN BARU (hanya boleh satu).
            // 'penuh'  = akun tidak lagi dipakai untuk unggahan baru — baik karena kuotanya benar-benar
            //            penuh, maupun karena baru ditambahkan dan belum diaktifkan (lihat RF-10a).
            //            Berkas LAMA yang tersimpan di akun berstatus 'penuh' tetap bisa dibuka (RF-10b).
            $table->enum('status', ['aktif', 'penuh'])->default('penuh');
            // Dalam satuan GB, dipakai bersama kuota_total untuk indikator persentase (RF-10c).
            $table->decimal('kuota_terpakai', 10, 2)->default(0);
            // Kuota total akun Gmail (default akun gratis ±15 GB, lihat SRS §5.3).
            // Disimpan per akun (bukan konstanta) karena bisa saja institusi memakai akun
            // Google Workspace dengan kuota berbeda di kemudian hari.
            $table->decimal('kuota_total', 10, 2)->default(15);
            // ID folder INDUK di Drive akun ini yang sudah dibagikan (share) ke Service Account.
            // Berbeda-beda per akun karena tiap akun Gmail institusi adalah Drive terpisah.
            $table->string('drive_folder_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('storage_account');
    }
};
