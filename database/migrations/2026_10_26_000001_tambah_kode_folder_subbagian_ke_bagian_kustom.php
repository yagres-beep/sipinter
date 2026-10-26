<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Hanya MENAMBAH kolom ke bagian_kustom — tidak ada kolom lama yang diubah/dihapus:
     * - kode: kode internal unik dan tetap (dipakai variabel notula), tidak ikut berubah
     *   bila nama bagian diganti.
     * - nama_folder: nama folder Drive bukti dukung, dikunci saat bagian dibuat supaya
     *   bukti lama dan baru tetap satu folder walau nama bagian diganti.
     * - subbagian: daftar subbagian (JSON) — NULL = bagian tanpa subbagian (perilaku lama).
     *
     * Baris lama diisi kode (dari nama) dan nama_folder (= nama saat ini, sama dengan
     * folder yang selama ini dipakai unggahan), hanya pada kolom baru tersebut.
     */
    public function up(): void
    {
        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->string('kode', 60)->nullable()->unique()->after('id');
            $table->string('nama_folder', 255)->nullable()->after('nama');
            $table->json('subbagian')->nullable()->after('kolom');
        });

        $terpakai = [];

        foreach (DB::table('bagian_kustom')->orderBy('id')->get(['id', 'nama']) as $baris) {
            $dasar = Str::of((string) $baris->nama)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(50, '')->trim('_')->value();
            $dasar = preg_match('/^[a-z]/', $dasar) ? $dasar : 'bagian_'.$dasar;
            $dasar = rtrim($dasar, '_');
            $kode = $dasar;
            $n = 2;

            while (in_array($kode, $terpakai, true)) {
                $kode = $dasar.'_'.$n++;
            }

            $terpakai[] = $kode;

            DB::table('bagian_kustom')->where('id', $baris->id)->update([
                'kode' => $kode,
                'nama_folder' => $baris->nama,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('bagian_kustom', function (Blueprint $table) {
            $table->dropUnique(['kode']);
            $table->dropColumn(['kode', 'nama_folder', 'subbagian']);
        });
    }
};
