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
        Schema::table('notula', function (Blueprint $table) {
            // Konten Bagian II/III sebagai HTML/gambar INLINE (siap ditempel langsung ke
            // dokumen notula menyatu — lihat pdf.notula-utuh), bukan PDF terpisah yang
            // digabung halaman-demi-halaman. bagian2_pdf/bagian3_pdf tetap dipertahankan
            // untuk pratinjau iframe di layar Kompilasi Notula.
            $table->longText('bagian2_html')->nullable()->after('bagian2_pdf');
            $table->longText('bagian3_html')->nullable()->after('bagian3_pdf');

            // Nama notulis, ditampilkan pada blok TTD di akhir dokumen (kolom kiri,
            // berpasangan dengan TTD Kepala di kolom kanan) — diisi Tim SAKIP lewat
            // Detail Rapat, sama seperti hari_tanggal/waktu/tempat/pimpinan_rapat.
            $table->string('notulis')->nullable()->after('pimpinan_rapat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notula', function (Blueprint $table) {
            $table->dropColumn(['bagian2_html', 'bagian3_html', 'notulis']);
        });
    }
};
