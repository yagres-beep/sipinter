<?php

use App\Support\RumusMarkup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rumus di Bagian I yang sudah tersimpan masih terkunci (tidak bisa disunting di
     * pratinjau) dan berangka desimal ("1.00/3.00") -- dirapikan sekali di sini
     * supaya pratinjau, .docx, dan PDF langsung memakai angka bulat tanpa harus
     * "Susun Ulang Otomatis" (baris yang sudah disunting memang tidak ikut disusun
     * ulang). Lihat RumusMarkup::rapikanHtmlTersimpan().
     */
    public function up(): void
    {
        DB::table('notula')->select(['id', 'bagian1_html', 'bagian1_html_cadangan'])->orderBy('id')
            ->chunkById(50, function ($daftar) {
                foreach ($daftar as $notula) {
                    $perubahan = [];
                    foreach (['bagian1_html', 'bagian1_html_cadangan'] as $kolom) {
                        if (filled($notula->{$kolom})) {
                            $baru = RumusMarkup::rapikanHtmlTersimpan($notula->{$kolom});
                            if ($baru !== $notula->{$kolom}) {
                                $perubahan[$kolom] = $baru;
                            }
                        }
                    }

                    if ($perubahan !== []) {
                        DB::table('notula')->where('id', $notula->id)->update($perubahan);
                    }
                }
            });
    }

    public function down(): void
    {
        // Perapian data satu arah -- tidak dikembalikan.
    }
};
