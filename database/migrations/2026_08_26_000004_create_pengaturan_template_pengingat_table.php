<?php

use App\Models\PengaturanTemplatePengingat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Satu baris per jenis pesan pengingat WA (lihat App\Models\
        // PengaturanTemplatePengingat::JENIS) berisi template teksnya — bisa diubah
        // Tim SAKIP lewat halaman Pengingat WA, menggantikan string pesan yang
        // tadinya dikodekan langsung (sprintf) di tiap Command/Listener.
        Schema::create('pengaturan_template_pengingat', function (Blueprint $table) {
            $table->id();
            $table->string('jenis')->unique();
            $table->text('template');
            $table->timestamps();
        });

        $now = now();

        DB::table('pengaturan_template_pengingat')->insert(
            collect(PengaturanTemplatePengingat::JENIS)->map(fn ($meta, $jenis) => [
                'jenis' => $jenis,
                'template' => $meta['default'],
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all()
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pengaturan_template_pengingat');
    }
};
