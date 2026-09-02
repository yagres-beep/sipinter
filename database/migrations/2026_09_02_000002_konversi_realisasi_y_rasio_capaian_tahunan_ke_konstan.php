<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * App\Models\CapaianTahunan::realisasiKumulatif() berhenti MENJUMLAHKAN
 * y_realisasi_tw{n} lintas triwulan untuk IKU 'rasio' — sekarang membacanya APA
 * ADANYA per TW (konstan sepanjang tahun, PERSIS pola y_alokasi_tw{n} sejak
 * migration 2026_09_02_000001), bukan pola lama (nol di TW II-IV, hanya benar lewat
 * penjumlahan) — lihat docblock kelas. x_realisasi_tw{n} TIDAK berubah — TETAP
 * dijumlahkan.
 *
 * Baris capaian_tahunan yang SUDAH terlanjur terisi di bawah pola LAMA (mis. Y=3 di
 * TW I, 0 di TW II-IV) perlu dikonversi ke pola BARU (Y=3 di KEEMPAT TW) supaya
 * Capaian Kinerja yang sudah tampil/tersimpan tidak berubah nilai secara diam-diam.
 * Sama seperti migration 2026_09_02_000001 (dan bukan kebetulan — running-sum-forward
 * dari pola nol-berjenjang [3,0,0,0] MENGHASILKAN pola konstan [3,3,3,3]), reuse
 * teknik yang sama, HANYA untuk kolom y_realisasi_tw1..4, HANYA milik IKU 'rasio'.
 * Baris yang keempat TW-nya null semua dibiarkan null.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->konversi(konstanKeArahNolBerjenjang: false);
    }

    public function down(): void
    {
        $this->konversi(konstanKeArahNolBerjenjang: true);
    }

    private function konversi(bool $konstanKeArahNolBerjenjang): void
    {
        $baris = DB::table('capaian_tahunan')
            ->join('master_iku', 'master_iku.id', '=', 'capaian_tahunan.iku_id')
            ->where('master_iku.metode_capaian', 'rasio')
            ->select('capaian_tahunan.id', 'capaian_tahunan.y_realisasi_tw1', 'capaian_tahunan.y_realisasi_tw2', 'capaian_tahunan.y_realisasi_tw3', 'capaian_tahunan.y_realisasi_tw4')
            ->get();

        foreach ($baris as $row) {
            $nilai = collect([1, 2, 3, 4])->map(fn ($tw) => $row->{"y_realisasi_tw{$tw}"});

            if ($nilai->every(fn ($v) => $v === null)) {
                continue;
            }

            $update = [];

            if ($konstanKeArahNolBerjenjang) {
                $sebelumnya = 0.0;
                foreach ([1, 2, 3, 4] as $tw) {
                    $sekarang = (float) ($nilai[$tw - 1] ?? 0);
                    $update["y_realisasi_tw{$tw}"] = round($sekarang - $sebelumnya, 2);
                    $sebelumnya = $sekarang;
                }
            } else {
                $berjalan = 0.0;
                foreach ([1, 2, 3, 4] as $tw) {
                    $berjalan += (float) ($nilai[$tw - 1] ?? 0);
                    $update["y_realisasi_tw{$tw}"] = round($berjalan, 2);
                }
            }

            DB::table('capaian_tahunan')->where('id', $row->id)->update($update);
        }
    }
};
