<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Pindahkan master_iku.tim (satu string, boleh berisi lebih dari satu nama tim
     * dipisah koma/titik-koma dari input lama) ke baris-baris iku_tim -- supaya IKU
     * yang sudah pernah diisi Tim sebelum RF "PIC boleh lebih dari satu tim" ini tetap
     * langsung terhitung penanggung jawab otomatisnya lewat App\Models\IkuTim.
     */
    public function up(): void
    {
        DB::table('master_iku')
            ->whereNotNull('tim')
            ->where('tim', '!=', '')
            ->orderBy('id')
            ->get(['id', 'tim'])
            ->each(function ($baris) {
                $daftarTim = collect(preg_split('/[,;]/', (string) $baris->tim))
                    ->map(fn ($t) => trim($t))
                    ->filter()
                    ->unique();

                foreach ($daftarTim as $tim) {
                    DB::table('iku_tim')->insertOrIgnore([
                        'iku_id' => $baris->id,
                        'tim' => $tim,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('iku_tim')->truncate();
    }
};
