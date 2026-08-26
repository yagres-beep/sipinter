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
        // Catatan tiap percobaan kirim WA (tes maupun pengingat sungguhan lewat
        // KirimPengingatWhatsAppJob) — lihat App\Services\WhatsAppService::catat().
        // Dipakai kartu "Riwayat Pengiriman" di halaman Pengingat WA supaya Tim
        // SAKIP bisa lihat status & alasan gagal tiap kiriman, bukan cuma pesan
        // flash sesaat.
        Schema::create('riwayat_pengiriman_wa', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_telepon');
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
        Schema::dropIfExists('riwayat_pengiriman_wa');
    }
};
