<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Kode IKU sebelumnya sering diketik dengan awalan "IKU-" (mis. "IKU-1131") saat
     * impor Excel/tambah manual — awalan itu sekarang dihapus di seluruh aplikasi
     * (form Tambah/Ubah, dropdown, notula, dsb.) supaya kode konsisten cuma
     * angka/kodenya saja. Backfill ini menghapus awalan non-digit apa pun dari data
     * yang sudah tersimpan (lihat juga App\Livewire\MasterIku::save() dan
     * App\Imports\MasterIkuImport, yang menormalisasi input baru dengan pola sama).
     */
    public function up(): void
    {
        DB::table('master_iku')->orderBy('id')->get(['id', 'kode'])->each(function ($baris) {
            $bersih = preg_replace('/^\D+/', '', $baris->kode);

            if ($bersih !== '' && $bersih !== $baris->kode) {
                DB::table('master_iku')->where('id', $baris->id)->update(['kode' => $bersih]);
            }
        });
    }

    public function down(): void
    {
        // Tidak dibalik — kode tanpa awalan "IKU-" adalah bentuk final yang diinginkan.
    }
};
