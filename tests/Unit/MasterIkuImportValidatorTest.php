<?php

namespace Tests\Unit;

use App\Services\MasterIkuImportValidator;
use PHPUnit\Framework\TestCase;

/**
 * MasterIkuImportValidator — validasi baris mentah Excel Master IKU sesuai spek
 * bagian 6.2 (satu test per aturan). Murni PHP, tanpa DB/Excel — baris disusun
 * langsung sebagai array kolom A-T (index 0-16 dipakai, R/S/T diabaikan).
 */
class MasterIkuImportValidatorTest extends TestCase
{
    /** @return array<int, string> */
    protected function baseRowPersen(array $override = []): array
    {
        // Contoh dari spek: X=8, Y=90 -> Target Tahunan 8,89%.
        $row = [
            0 => '1', 1 => 'S01', 2 => 'Sasaran Satu',
            3 => '1001', 4 => 'Indikator Uji Persen', 5 => 'Tahunan', 6 => '%',
            7 => 'Persen', 8 => '8.89',
            9 => 'Pembilang', 10 => '8', 11 => 'Penyebut', 12 => '90',
            13 => '2', 14 => '2', 15 => '2', 16 => '2',
        ];

        return $override + $row;
    }

    /** @return array<int, string> */
    protected function baseRowNonPersen(array $override = []): array
    {
        // Contoh dari spek: Indeks Pelayanan Publik target 4,35.
        $row = [
            0 => '2', 1 => 'S02', 2 => 'Sasaran Dua',
            3 => '1002', 4 => 'Indikator Uji Non Persen', 5 => 'Triwulanan', 6 => 'Non %',
            7 => 'Poin', 8 => '4.35',
            9 => '', 10 => '', 11 => '', 12 => '',
            13 => '1.09', 14 => '1.08', 15 => '1.09', 16 => '1.09',
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
        $row = $this->baseRowPersen([8 => '100', 10 => '4', 12 => '4', 13 => '1', 14 => '1', 15 => '1', 16 => '1']);

        $hasil = $this->validasi($row);

        $this->assertTrue($hasil['valid'], implode(' | ', $hasil['errors']));
    }

    public function test_baris_tipe_non_persen_valid(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen());

        $this->assertTrue($hasil['valid'], implode(' | ', $hasil['errors']));
        $this->assertSame('langsung', $hasil['data']['master_iku']['metode_capaian']);
        $this->assertSame(4.35, $hasil['data']['capaian_tahunan']['target_tahunan']);
    }

    // --- Aturan 1: wajib isi -------------------------------------------------

    public function test_kolom_wajib_kosong_menghasilkan_error(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen([4 => '']));

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('Indikator Kinerja wajib diisi', implode(' ', $hasil['errors']));
    }

    // --- Aturan 2: dropdown case-insensitive + trim ---------------------------

    public function test_dropdown_case_insensitive_dan_trim(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen([5 => 'TRIWULANAN', 6 => ' non % ']));

        $this->assertTrue($hasil['valid'], implode(' | ', $hasil['errors']));
        $this->assertSame('triwulanan', $hasil['data']['master_iku']['jenis_periode']);
        $this->assertSame('langsung', $hasil['data']['master_iku']['metode_capaian']);
    }

    public function test_dropdown_nilai_tidak_valid_menghasilkan_error(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen([5 => 'Utama']));

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('Jenis Periode harus', implode(' ', $hasil['errors']));
    }

    // --- Aturan 3: kode unik dalam file & di DB --------------------------------

    public function test_kode_duplikat_dalam_file_menghasilkan_error_pada_baris_kedua(): void
    {
        $hasil = MasterIkuImportValidator::validasiSemua([
            $this->baseRowNonPersen(),
            $this->baseRowNonPersen([3 => '1002']),
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

    public function test_tipe_persen_kolom_m_sampai_p_wajib_diisi(): void
    {
        $hasil = $this->validasi($this->baseRowPersen([10 => '']));

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('Target X (Pembilang) wajib diisi', implode(' ', $hasil['errors']));
    }

    public function test_tipe_persen_target_x_dan_y_harus_lebih_dari_nol(): void
    {
        $hasil = $this->validasi($this->baseRowPersen([10 => '0', 12 => '0']));

        $this->assertFalse($hasil['valid']);
        $pesan = implode(' | ', $hasil['errors']);
        $this->assertStringContainsString('Target X (Pembilang) harus lebih besar dari 0', $pesan);
        $this->assertStringContainsString('Target Y (Penyebut) harus lebih besar dari 0', $pesan);
    }

    public function test_tipe_persen_jumlah_alokasi_harus_sama_dengan_target_x(): void
    {
        $hasil = $this->validasi($this->baseRowPersen([13 => '5'])); // jumlah jadi 11, target X tetap 8.

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('harus sama dengan Target X', implode(' ', $hasil['errors']));
    }

    public function test_tipe_persen_target_tahunan_harus_sama_dengan_x_dibagi_y(): void
    {
        // Target Tahunan ditulis 50% padahal X/Y = 8/90 = 8,89% -> tidak cocok.
        $hasil = $this->validasi($this->baseRowPersen([8 => '50']));

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('harus sama dengan Target X ÷ Target Y × 100', implode(' ', $hasil['errors']));
    }

    // --- Aturan 5: Tipe "Non %" ---------------------------------------------------

    public function test_tipe_non_persen_kolom_m_sampai_p_harus_kosong(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen([9 => 'Pembilang']));

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('harus dikosongkan untuk Jenis Nilai "Non %"', implode(' ', $hasil['errors']));
    }

    public function test_tipe_non_persen_jumlah_alokasi_harus_sama_dengan_target_tahunan(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen([8 => '5.00'])); // jumlah alokasi tetap 4.35.

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('harus sama dengan Target Tahunan', implode(' ', $hasil['errors']));
    }

    // --- Aturan 6: angka negatif & sel kosong dianggap 0 -----------------------

    public function test_angka_negatif_ditolak(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen([13 => '-1']));

        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('tidak boleh negatif', implode(' ', $hasil['errors']));
    }

    public function test_sel_alokasi_kosong_dianggap_nol(): void
    {
        $hasil = $this->validasi($this->baseRowNonPersen([8 => '2.17', 13 => '2.17', 14 => '', 15 => '', 16 => '']));

        $this->assertTrue($hasil['valid'], implode(' | ', $hasil['errors']));
        $this->assertSame(0.0, $hasil['data']['capaian_tahunan']['alokasi_tw2']);
    }

    // --- Baris kosong dilewati, bukan error ------------------------------------

    public function test_baris_kosong_total_dilewati_tanpa_dilaporkan(): void
    {
        $hasil = MasterIkuImportValidator::validasiSemua([
            array_fill(0, 17, ''),
        ], 4, false, []);

        $this->assertSame([], $hasil);
    }

    // --- Mapping Y konstan ke y_alokasi_tw1 (lihat docblock validator) --------

    public function test_y_konstan_ditaruh_di_tw_satu_untuk_tipe_persen(): void
    {
        $hasil = $this->validasi($this->baseRowPersen());

        $ct = $hasil['data']['capaian_tahunan'];
        $this->assertSame(90.0, $ct['y_alokasi_tw1']);
        $this->assertSame(0.0, $ct['y_alokasi_tw2']);
        $this->assertSame(0.0, $ct['y_alokasi_tw3']);
        $this->assertSame(0.0, $ct['y_alokasi_tw4']);
    }
}
