<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migrasi 2026_10_26_000001 hanya menambah kolom; baris lama diisi kode & nama_folder
 * tanpa mengubah kolom lama, dan down() hanya membuang kolom baru.
 */
class MigrasiKodeSubbagianBagianKustomTest extends TestCase
{
    use RefreshDatabase;

    private function migrasi(): object
    {
        return require database_path('migrations/2026_10_26_000001_tambah_kode_folder_subbagian_ke_bagian_kustom.php');
    }

    public function test_baris_lama_diisi_kode_dan_folder_tanpa_mengubah_kolom_lama(): void
    {
        $m = $this->migrasi();
        $m->down();
        $this->assertFalse(Schema::hasColumn('bagian_kustom', 'kode'));

        $lama = [
            ['nama' => 'Manajemen Risiko', 'deskripsi' => 'A', 'aktif' => true, 'urutan' => 1, 'frekuensi_wajib' => 'opsional', 'bukti_wajib' => true, 'kolom' => json_encode([['kunci' => 'x', 'label' => 'X', 'tipe' => 'teks']])],
            ['nama' => 'Manajemen  Risiko!', 'deskripsi' => null, 'aktif' => false, 'urutan' => 2, 'frekuensi_wajib' => 'setiap_bulan', 'bukti_wajib' => false, 'kolom' => null],
            ['nama' => '2026 Inovasi', 'deskripsi' => null, 'aktif' => true, 'urutan' => 3, 'frekuensi_wajib' => 'opsional', 'bukti_wajib' => false, 'kolom' => null],
        ];

        foreach ($lama as $baris) {
            DB::table('bagian_kustom')->insert($baris + ['peran' => '["ketua_tim"]', 'penempatan' => '["pengisian"]', 'created_at' => now(), 'updated_at' => now()]);
        }

        $kolomLama = ['id', 'nama', 'deskripsi', 'aktif', 'urutan', 'frekuensi_wajib', 'bukti_wajib', 'kolom', 'peran', 'penempatan', 'updated_at'];
        $potret = fn ($baris) => $baris->map(function ($r) use ($kolomLama) {
            $a = array_intersect_key((array) $r, array_flip($kolomLama));
            ksort($a);

            return $a;
        })->all();
        $sebelum = $potret(DB::table('bagian_kustom')->orderBy('id')->get());

        $m->up();

        $sesudah = DB::table('bagian_kustom')->orderBy('id')->get();
        $this->assertSame($sebelum, $potret($sesudah), 'kolom lama tidak berubah');
        $this->assertSame(['manajemen_risiko', 'manajemen_risiko_2', 'bagian_2026_inovasi'], $sesudah->pluck('kode')->all());
        $this->assertSame(['Manajemen Risiko', 'Manajemen  Risiko!', '2026 Inovasi'], $sesudah->pluck('nama_folder')->all());
        $this->assertSame([null, null, null], $sesudah->pluck('subbagian')->all());

        // Turun lalu naik lagi: aman diulang.
        $m->down();
        $m->up();
        $this->assertSame(3, DB::table('bagian_kustom')->whereNotNull('kode')->count());
    }
}
