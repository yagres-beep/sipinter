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
        // Catatan tiap percobaan kirim email pengingat (tes maupun pengingat
        // sungguhan lewat KirimPengingatEmailJob) — lihat
        // App\Services\EmailPengingatService::catat(). Dipakai kartu "Riwayat
        // Pengiriman Email" supaya Tim SAKIP bisa lihat status & alasan gagal
        // tiap kiriman, sama seperti riwayat_pengiriman_wa sebelumnya.
        Schema::create('riwayat_pengiriman_email', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('subjek');
            $table->text('pesan');
            $table->boolean('berhasil');
            $table->text('alasan_gagal')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('riwayat_pengiriman_email');
    }
};
