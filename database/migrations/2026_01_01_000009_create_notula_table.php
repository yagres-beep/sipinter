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
        Schema::create('notula', function (Blueprint $table) {
            $table->id();
            // Satu notula per TRIWULAN. periode_id menunjuk ke baris periode BULAN
            // PERTAMA triwulan tsb (mengikuti konvensi yang sama dipakai rtl_evaluasi
            // untuk data yang sebenarnya berskala triwulan, bukan bulanan).
            $table->foreignId('periode_id')->unique()->constrained('periode')->cascadeOnUpdate()->cascadeOnDelete();

            // Bagian I — Capaian Kinerja (RF-42): disusun otomatis dari data terverifikasi,
            // disimpan sebagai HTML supaya Tim SAKIP bisa menyunting pratinjaunya dulu
            // sebelum digabung. Dirender ulang jadi PDF (dompdf) tiap kali digabungkan.
            $table->longText('bagian1_html')->nullable();

            // Bagian II & III (RF-42a/b): path lokal PDF HASIL KONVERSI (bukan berkas
            // asli unggahan) — LibreOffice headless mengonversi docx/xlsx/gambar/pdf
            // menjadi PDF ini sebelum digabungkan.
            $table->string('bagian2_pdf')->nullable();
            $table->string('bagian3_pdf')->nullable();

            // Hasil gabungan Bagian I+II+III (RF-42d). DUA versi disimpan terpisah karena
            // "Unduh draf" dan "Unduh final" isinya BERBEDA (RF-43): draf tanpa blok TTD,
            // final dengan blok TTD elektronik Kepala — bukan sekadar dua nama unduhan
            // untuk file yang sama.
            $table->string('pdf_gabungan')->nullable(); // versi draf, tanpa TTD
            $table->string('pdf_final')->nullable();     // versi final, dengan TTD (dibuat ulang saat disetujui)

            // Alur status notula (berbeda dari alur status kegiatan/isian — lihat
            // catatan di Kegiatan::TRANSITIONS): draft (bagian belum lengkap/belum
            // digabung) -> menunggu_persetujuan (sudah digabung, dikirim ke Kepala)
            // -> disetujui / dikembalikan (RF-44).
            $table->enum('status', ['draft', 'menunggu_persetujuan', 'disetujui', 'dikembalikan'])->default('draft');

            // RF-44: persetujuan elektronik Kepala — siapa & kapan, dipakai mengisi
            // blok TTD ("ttd" + nama kepala + tanggal) pada pdf_final.
            $table->foreignId('disetujui_oleh_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disetujui_pada')->nullable();

            // Alasan Kepala saat "Kembalikan ke Tim SAKIP" (padanan catatan per-berkas
            // pada verifikasi kegiatan, tapi di level notula tidak ada unit berkas
            // per-baris untuk ditempeli catatan, jadi disimpan di sini).
            $table->text('catatan_pengembalian')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notula');
    }
};
