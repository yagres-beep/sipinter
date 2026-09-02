<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * App\Models\CapaianTahunan::alokasiKumulatif() berhenti MENJUMLAHKAN
 * x_alokasi_tw{n}/y_alokasi_tw{n} lintas triwulan untuk IKU 'rasio' — sekarang
 * membaca nilainya APA ADANYA sebagai angka KUMULATIF langsung (persis kolom M-P
 * sheet "LK_Kabkot" resmi), bukan kontribusi mentah tiap TW yang dijumlahkan
 * otomatis seperti sebelumnya (lihat docblock kelas). realisasiKumulatif()
 * (x_realisasi_tw{n}/y_realisasi_tw{n}) TIDAK berubah — TETAP dijumlahkan.
 *
 * Baris capaian_tahunan yang SUDAH terlanjur terisi di bawah rumus LAMA perlu
 * dikonversi supaya Capaian Kinerja yang sudah tampil/tersimpan tidak berubah nilai
 * secara diam-diam: running-sum-forward x_alokasi_tw1..4/y_alokasi_tw1..4 (null
 * dianggap 0 kontribusi, PERSIS logika penjumlahan lama), lalu tulis hasilnya ke
 * KEEMPAT kolom TW — reproduksi PERSIS nilai kumulatif yang sebelumnya dihasilkan
 * rumus lama, hanya beda cara penyimpanan (kumulatif langsung, bukan kontribusi
 * mentah). Baris yang keempat TW-nya null semua (IKU belum pernah disentuh sama
 * sekali) dibiarkan null, TIDAK diisi 0 — supaya Capaian::hitungPersentase() tetap
 * mengembalikan "-" (belum dinilai), bukan seolah-olah alokasinya 0 sungguhan.
 *
 * HANYA menyentuh baris milik IKU bermetode 'rasio' (MasterIku::metode_capaian) —
 * alokasi_tw1..4 milik IKU 'langsung' TIDAK disentuh, rumusnya tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->konversi(kumulatifKeArahMentah: false);
    }

    public function down(): void
    {
        $this->konversi(kumulatifKeArahMentah: true);
    }

    private function konversi(bool $kumulatifKeArahMentah): void
    {
        $baris = DB::table('capaian_tahunan')
            ->join('master_iku', 'master_iku.id', '=', 'capaian_tahunan.iku_id')
            ->where('master_iku.metode_capaian', 'rasio')
            ->select('capaian_tahunan.id', 'capaian_tahunan.x_alokasi_tw1', 'capaian_tahunan.x_alokasi_tw2', 'capaian_tahunan.x_alokasi_tw3', 'capaian_tahunan.x_alokasi_tw4', 'capaian_tahunan.y_alokasi_tw1', 'capaian_tahunan.y_alokasi_tw2', 'capaian_tahunan.y_alokasi_tw3', 'capaian_tahunan.y_alokasi_tw4')
            ->get();

        foreach ($baris as $row) {
            $update = [];
            $update += $this->kolomHasil('x_alokasi', $row, $kumulatifKeArahMentah);
            $update += $this->kolomHasil('y_alokasi', $row, $kumulatifKeArahMentah);

            if ($update !== []) {
                DB::table('capaian_tahunan')->where('id', $row->id)->update($update);
            }
        }
    }

    /**
     * up(): running-sum-forward (mentah -> kumulatif), null dianggap 0 kontribusi.
     * down(): delta antar-TW (kumulatif -> mentah), untuk rollback.
     * Baris yang keempat TW-nya null semua dilewati (tidak ditulis apa pun).
     *
     * @return array<string, float|null>
     */
    private function kolomHasil(string $prefix, object $row, bool $kumulatifKeArahMentah): array
    {
        $nilai = collect([1, 2, 3, 4])->map(fn ($tw) => $row->{"{$prefix}_tw{$tw}"});

        if ($nilai->every(fn ($v) => $v === null)) {
            return [];
        }

        $hasil = [];

        if ($kumulatifKeArahMentah) {
            $sebelumnya = 0.0;
            foreach ([1, 2, 3, 4] as $tw) {
                $sekarang = (float) ($nilai[$tw - 1] ?? 0);
                $hasil["{$prefix}_tw{$tw}"] = round($sekarang - $sebelumnya, 2);
                $sebelumnya = $sekarang;
            }
        } else {
            $berjalan = 0.0;
            foreach ([1, 2, 3, 4] as $tw) {
                $berjalan += (float) ($nilai[$tw - 1] ?? 0);
                $hasil["{$prefix}_tw{$tw}"] = round($berjalan, 2);
            }
        }

        return $hasil;
    }
};
