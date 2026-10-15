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
            // Berkas .docx ASLI Bagian II/III (base64) yang diunggah Tim SAKIP --
            // disisipkan apa adanya ke notula lengkap (.docx/PDF) lewat
            // App\Support\PenggabungDocx, bukan lagi lewat HTML hasil konversi yang
            // merusak tabel & penomoran. Disimpan di DB (bukan disk lokal) karena
            // storage lokal server tidak persisten antar-deploy.
            $table->longText('bagian2_docx')->nullable()->after('bagian2_html');
            $table->longText('bagian3_docx')->nullable()->after('bagian3_html');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notula', function (Blueprint $table) {
            $table->dropColumn(['bagian2_docx', 'bagian3_docx']);
        });
    }
};
