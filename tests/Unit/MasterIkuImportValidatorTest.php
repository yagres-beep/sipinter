<?php

namespace Tests\Unit;

use App\Services\MasterIkuImportValidator;
use PHPUnit\Framework\TestCase;

/**
 * MasterIkuImportValidator — validasi baris mentah Excel Master IKU sesuai spek
 * bagian 6.2 (satu test per aturan). Murni PHP, tanpa DB/Excel — baris disusun
 * langsung sebagai array kolom A-V (index 0-18 dipakai, T/U/V diabaikan).
 */
class MasterIkuImportValidatorTest extends TestCase
{
    /** @return array<int, string> */
    protected function baseRowPersen(array $override = []): array
    {
        // Contoh dari spek: X=8, Y=90 -> Target Tahunan 8,89%.
        $row = [
            0 => '1', 1 => 'Sasaran Satu',
            2 => '1001', 3 => 'Tim Uji', 4 => 'Indikator Uji Persen',
            5 => 'Rumus uji', 6 => 'Sumber uji',
            7 => 'Tahunan', 8 => '%',
            9 => 'Persen', 10 => '8.89',
            11 => 'Pembilang', 12 => '8', 13 => 'Penyebut', 14 => '90',
            15 => '2', 16 => '2', 17 => '2', 18 => '2',
        ];

        return $override + $row;
    }

    /** @return array<int, string> */
    protected function baseRowNonPersen(array $override = []): array
    {
        // Contoh dari spek: Indeks Pelayanan Publik target 4,35.
        $row = [
            0 => '2', 1 => 'Sasaran Dua',
            2 => '1002', 3 => 'Tim Uji Dua', 4 => 'Indikator Uji Non Persen',
            5 => '', 6 => '',
            7 => 'Triwulanan', 8 => 'Non %',
            9 => 'Poin', 10 => '4.35',
            11 => '', 12 => '', 13 => '', 14 => '',
            15 => '1.09', 16 => '1.08', 17 => '1.09', 18 => '1.09',
        ];

        return $override + $row;
    }

    protected function validasi(array $row, bool $modeUpsert = false, array $kodeDiDb = []): array
    {
        $hasil = MasterIkuImportValidator::validasiSemua([$row], 4, $modeUpsert, $kodeDiDb);

        return $hasil[0];
    }

    // --- Baris valid dasar (kedua tipe) ------------------------------------

    public function test_baris_tipe_persen_valid_delapan_koma_delapan_sembilan_persen(): void
    {
        $hasil = $this->validasi($this->baseRowPersen());

        $this->assertTrue($hasil['valid'], implode(' | ', $hasil['errors']));
        $this->assertSame('rasio', $hasil['data']['master_iku']['metode_capaian']);
        $this->assertSame(8.0, $hasil['data']['capaian_tahunan']['x_target']);
        $this->assertSame(90.0, $hasil['data']['capaian_tahunan']['y_target']);
    }

    public function test_baris_tipe_persen_valid_seratus_persen(): void
    {
        $row = $this->baseRowPersen([10 => '100', 12 => '4', 14 => '4', 15 => '1', 16 => '1', 17 => '1', 18 => '1']);

        $hasil = $this->validasi($row);

        $this->assertTrue($hasil['valid'], implode(' | ', $hasil['errors']));
    }

    public function test_baris_tipe_non_persen_valid(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen());

        $this->assertTrue($hasil['valid'], implode(' | ', $hasil['errors']));
        $this->assertSame('langsung', $hasil['data']['master_iku']['metode_capaian']);
        // target_tahunan TIDAK LAGI ditulis (kolom lama, tidak dipakai lagi) --
        // alokasi_tw1..4 kini ditulis KUMULATIF dari kontribusi mentah kolom Alokasi
        // Target TW I-IV (1.09, 1.08, 1.09, 1.09 -> kumulatif 1.09, 2.17, 3.26, 4.35),
        // TW IV selalu = Target Tahunan (lihat CapaianTahunan::targetTahunan()).
        $this->assertNull($hasil['data']['capaian_tahunan']['target_tahunan']);
        $this->assertEqualsWithDelta(1.09, $hasil['data']['capaian_tahunan']['alokasi_tw1'], 0.01);
        $this->assertEqualsWithDelta(4.35, $hasil['data']['capaian_tahunan']['alokasi_tw4'], 0.01);
    }

    // --- Aturan 1: wajib isi -------------------------------------------------

    public function test_kolom_wajib_kosong_menghasilkan_error(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen([4 => '']));

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('Indikator Kinerja wajib diisi', implode(' ', $hasil['errors']));
    }

    public function test_tim_wajib_diisi(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen([3 => '']));

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('Penanggung Jawab (Tim) wajib diisi', implode(' ', $hasil['errors']));
    }

    public function test_tim_dasar_hitung_dan_basis_data_ikut_disimpan(): void
    {
        $hasil = $this->validasi($this->baseRowPersen());

        $this->assertTrue($hasil['valid'], implode(' | ', $hasil['errors']));
        $this->assertSame('Tim Uji', $hasil['data']['master_iku']['tim']);
        $this->assertSame('Rumus uji', $hasil['data']['master_iku']['dasar_hitung']);
        $this->assertSame('Sumber uji', $hasil['data']['master_iku']['basis_data']);
    }

    public function test_dasar_hitung_dan_basis_data_boleh_kosong(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen());

        $this->assertTrue($hasil['valid'], implode(' | ', $hasil['errors']));
        $this->assertNull($hasil['data']['master_iku']['dasar_hitung']);
        $this->assertNull($hasil['data']['master_iku']['basis_data']);
    }

    // --- Aturan 2: dropdown case-insensitive + trim ---------------------------

    public function test_dropdown_case_insensitive_dan_trim(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen([7 => 'TRIWULANAN', 8 => ' non % ']));

        $this->assertTrue($hasil['valid'], implode(' | ', $hasil['errors']));
        $this->assertSame('triwulanan', $hasil['data']['master_iku']['jenis_periode']);
        $this->assertSame('langsung', $hasil['data']['master_iku']['metode_capaian']);
    }

    public function test_dropdown_nilai_tidak_valid_menghasilkan_error(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen([7 => 'Utama']));

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('Jenis Periode harus', implode(' ', $hasil['errors']));
    }

    // --- Aturan 3: kode unik dalam file & di DB --------------------------------

    public function test_kode_duplikat_dalam_file_menghasilkan_error_pada_baris_kedua(): void
    {
        $hasil = MasterIkuImportValidator::validasiSemua([
            $this->baseRowNonPersen(),
            $this->baseRowNonPersen([2 => '1002']),
        ], 4, false, []);

        $this->assertTrue($hasil[0]['valid']);
        $this->assertFalse($hasil[1]['valid']);
        $this->assertStringContainsString('duplikat', implode(' ', $hasil[1]['errors']));
    }

    public function test_kode_sudah_ada_di_db_ditolak_mode_insert_diterima_mode_upsert(): void
    {
        $row = $this->baseRowNonPersen();

        $insert = $this->validasi($row, false, ['1002']);
        $upsert = $this->validasi($row, true, ['1002']);

        $this->assertFalse($insert['valid']);
        $this->assertStringContainsString('sudah ada di database', implode(' ', $insert['errors']));
        $this->assertTrue($upsert['valid'], implode(' | ', $upsert['errors']));
    }

    // --- Aturan 4: Tipe "%" -----------------------------------------------------

    public function test_tipe_persen_kolom_l_sampai_o_wajib_diisi(): void
    {
        $hasil = $this->validasi($this->baseRowPersen([12 => '']));

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('Target X (Pembilang) wajib diisi', implode(' ', $hasil['errors']));
    }

    public function test_tipe_persen_target_x_dan_y_harus_lebih_dari_nol(): void
    {
        $hasil = $this->validasi($this->baseRowPersen([12 => '0', 14 => '0']));

        $this->assertFalse($hasil['valid']);
        $pesan = implode(' | ', $hasil['errors']);
        $this->assertStringContainsString('Target X (Pembilang) harus lebih besar dari 0', $pesan);
        $this->assertStringContainsString('Target Y (Penyebut) harus lebih besar dari 0', $pesan);
    }

    public function test_tipe_persen_jumlah_alokasi_harus_sama_dengan_target_x(): void
    {
        $hasil = $this->validasi($this->baseRowPersen([15 => '5'])); // jumlah jadi 11, target X tetap 8.

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('harus sama dengan Target X', implode(' ', $hasil['errors']));
    }

    public function test_tipe_persen_target_tahunan_harus_sama_dengan_x_dibagi_y(): void
    {
        // Target Tahunan ditulis 50% padahal X/Y = 8/90 = 8,89% -> tidak cocok.
        $hasil = $this->validasi($this->baseRowPersen([10 => '50']));

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('harus sama dengan Target X ÷ Target Y × 100', implode(' ', $hasil['errors']));
    }

    // --- Aturan 5: Tipe "Non %" ---------------------------------------------------

    public function test_tipe_non_persen_kolom_l_sampai_o_harus_kosong(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen([11 => 'Pembilang']));

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('harus dikosongkan untuk Jenis Nilai "Non %"', implode(' ', $hasil['errors']));
    }

    public function test_tipe_non_persen_jumlah_alokasi_harus_sama_dengan_target_tahunan(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen([10 => '5.00'])); // jumlah alokasi tetap 4.35.

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('harus sama dengan Target Tahunan', implode(' ', $hasil['errors']));
    }

    // --- Aturan 6: angka negatif & sel kosong dianggap 0 -----------------------

    public function test_angka_negatif_ditolak(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen([15 => '-1']));

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('tidak boleh negatif', implode(' ', $hasil['errors']));
    }

    public function test_sel_alokasi_kosong_dianggap_nol(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen([10 => '2.17', 15 => '2.17', 16 => '', 17 => '', 18 => '']));

        $this->assertTrue($hasil['valid'], implode(' | ', $hasil['errors']));

        // Sel TW II-IV kosong -> kontribusi mentah dianggap 0 (bukan error), TAPI
        // angka KUMULATIF-nya tetap 2.17 di keempat TW (carry forward dari TW I,
        // bukan reset ke 0 -- sesuai App\Models\CapaianTahunan::alokasiKumulatif()
        // yang membaca kumulatif apa adanya).
        $ct = $hasil['data']['capaian_tahunan'];
        $this->assertSame(2.17, $ct['alokasi_tw1']);
        $this->assertSame(2.17, $ct['alokasi_tw2']);
        $this->assertSame(2.17, $ct['alokasi_tw3']);
        $this->assertSame(2.17, $ct['alokasi_tw4']);
    }

    // --- Baris kosong dilewati, bukan error ------------------------------------

    public function test_baris_kosong_total_dilewati_tanpa_dilaporkan(): void
    {
        $hasil = MasterIkuImportValidator::validasiSemua([
            array_fill(0, 19, ''),
        ], 4, false, []);

        $this->assertSame([], $hasil);
    }

    // --- Mapping Y konstan & X kumulatif (lihat docblock validator) -----------

    public function test_y_konstan_ditaruh_sama_di_keempat_tw_untuk_tipe_persen(): void
    {
        $hasil = $this->validasi($this->baseRowPersen());

        $ct = $hasil['data']['capaian_tahunan'];
        $this->assertSame(90.0, $ct['y_alokasi_tw1']);
        $this->assertSame(90.0, $ct['y_alokasi_tw2']);
        $this->assertSame(90.0, $ct['y_alokasi_tw3']);
        $this->assertSame(90.0, $ct['y_alokasi_tw4']);
    }

    /**
     * Kolom "Alokasi Target TW I-IV" pada file Excel tetap KONTRIBUSI mentah tiap
     * TW (baseRowPersen: [2,2,2,2], jumlah = Target X = 8, sesuai aturan bersyarat
     * di atas) -- tapi ditulis ke x_alokasi_tw1..4 sebagai KUMULATIF running-sum
     * (2, 4, 6, 8), sesuai App\Models\CapaianTahunan::alokasiKumulatif() yang
     * sekarang membacanya langsung tanpa menjumlah lagi.
     */
    public function test_alokasi_x_ditulis_kumulatif_running_sum_untuk_tipe_persen(): void
    {
        $hasil = $this->validasi($this->baseRowPersen());

        $ct = $hasil['data']['capaian_tahunan'];
        $this->assertSame(2.0, $ct['x_alokasi_tw1']);
        $this->assertSame(4.0, $ct['x_alokasi_tw2']);
        $this->assertSame(6.0, $ct['x_alokasi_tw3']);
        $this->assertSame(8.0, $ct['x_alokasi_tw4']);
    }
}
