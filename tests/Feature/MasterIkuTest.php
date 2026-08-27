<?php

namespace Tests\Feature;

use App\Exports\MasterIkuTemplateExport;
use App\Imports\MasterIkuImport;
use App\Livewire\MasterIku;
use App\Models\CapaianTahunan;
use App\Models\MasterIku as MasterIkuModel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MasterIkuTest extends TestCase
{
    use RefreshDatabase;

    protected function loginSebagaiTimSakip(): User
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $user = User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip-metode@example.test', 'email' => 'sakip-metode@example.test',
            'password' => 'password', 'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_tim_sakip_bisa_mengunduh_template_excel(): void
    {
        $this->loginSebagaiTimSakip();

        // Regresi: import Excel facade sempat salah namespace (Illuminate\Support\Facades\Excel,
        // seharusnya Maatwebsite\Excel\Facades\Excel) sehingga tombol ini melempar
        // "Class not found" alih-alih mengunduh berkas — lihat perbaikan di app/Livewire/MasterIku.php.
        Livewire::test(MasterIku::class)
            ->call('downloadTemplate')
            ->assertFileDownloaded('template-master-iku.xlsx');
    }

    public function test_template_terunduh_bisa_diunggah_balik_tanpa_error_meski_ada_sheet_daftar_nama(): void
    {
        // Regresi: template resmi punya 2 sheet (Master_IKU + Daftar Nama referensi).
        // Tanpa WithMultipleSheets pada sisi import, Maatwebsite Excel memanggil
        // collection() untuk SETIAP sheet pada berkas yang diunggah balik — sheet
        // "Daftar Nama" harus dilewati diam-diam, bukan dianggap format salah. Template
        // sendiri hanya berisi header+2 contoh+petunjuk (tanpa baris data) -> hasilValidasi kosong.
        Storage::fake('local');

        $relativePath = 'template-uji.xlsx';
        ExcelFacade::store(new MasterIkuTemplateExport, $relativePath, 'local');

        $import = new MasterIkuImport;
        ExcelFacade::import($import, Storage::disk('local')->path($relativePath));

        $this->assertSame([], $import->errors);
        $this->assertSame([], $import->hasilValidasi);
    }

    /**
     * @return array<int, mixed>
     */
    protected function rowPersen(array $override = []): array
    {
        $row = [
            1, 'T01', 'Tujuan Satu', 'S01', 'Sasaran Satu',
            '1131', 'Persentase publikasi tepat waktu', 'IKU', 'Tahunan', '%', 'Persen', 8.89,
            'Pembilang', 8, 'Penyebut', 90,
            2, 2, 2, 2, '', '', '',
        ];

        foreach ($override as $i => $v) {
            $row[$i] = $v;
        }

        return $row;
    }

    /**
     * @return array<int, mixed>
     */
    protected function rowNonPersen(array $override = []): array
    {
        $row = [
            2, 'T01', 'Tujuan Satu', 'S02', 'Sasaran Dua',
            '1132', 'Indeks Pelayanan Publik', 'Proksi', 'Triwulanan', 'Non %', 'Poin', 4.35,
            '', '', '', '',
            1.09, 1.08, 1.09, 1.09, '', '', '',
        ];

        foreach ($override as $i => $v) {
            $row[$i] = $v;
        }

        return $row;
    }

    protected function importDanKembalikanHasil(array $dataRows, bool $modeUpsert = false): MasterIkuImport
    {
        $import = new MasterIkuImport($modeUpsert);
        $import->collection(collect([
            MasterIkuImport::EXPECTED_HEADER,
            $this->rowPersen(),
            $this->rowNonPersen(),
            [\App\Exports\MasterIkuTemplateSheet::BARIS_PETUNJUK],
            ...$dataRows,
        ]));

        return $import;
    }

    public function test_import_baris_valid_tipe_persen_dan_non_persen(): void
    {
        $import = $this->importDanKembalikanHasil([
            $this->rowPersen([5 => '2001']),
            $this->rowNonPersen([5 => '2002']),
        ]);

        $this->assertSame([], $import->errors);
        $this->assertCount(2, $import->hasilValidasi);
        $this->assertTrue($import->hasilValidasi[0]['valid'], implode(' | ', $import->hasilValidasi[0]['errors']));
        $this->assertTrue($import->hasilValidasi[1]['valid'], implode(' | ', $import->hasilValidasi[1]['errors']));
    }

    public function test_import_baris_tidak_valid_dilaporkan_dengan_alasan(): void
    {
        $import = $this->importDanKembalikanHasil([
            $this->rowPersen([5 => '2003', 13 => '']), // Target X dikosongkan -> error.
        ]);

        $this->assertFalse($import->hasilValidasi[0]['valid']);
        $this->assertNotEmpty($import->hasilValidasi[0]['errors']);
    }

    public function test_konfirmasi_impor_menyimpan_master_iku_dan_capaian_tahunan_tipe_persen(): void
    {
        $this->loginSebagaiTimSakip();

        $import = $this->importDanKembalikanHasil([$this->rowPersen([5 => '3001'])]);

        Livewire::test(MasterIku::class)
            ->set('tahunImpor', 2026)
            ->set('pratinjau', $import->hasilValidasi)
            ->call('konfirmasiImpor')
            ->assertHasNoErrors();

        $iku = MasterIkuModel::where('kode', '3001')->first();
        $this->assertNotNull($iku);
        $this->assertSame('rasio', $iku->metode_capaian);
        $this->assertSame('T01', $iku->kode_tujuan);
        $this->assertSame('Sasaran Satu', $iku->sasaran);

        $ct = CapaianTahunan::where('iku_id', $iku->id)->where('tahun', 2026)->first();
        $this->assertNotNull($ct);
        $this->assertEqualsWithDelta(8.0, $ct->x_target, 0.01);
        $this->assertEqualsWithDelta(90.0, $ct->y_target, 0.01);
        $this->assertEqualsWithDelta(90.0, $ct->y_alokasi_tw1, 0.01);
        $this->assertEqualsWithDelta(0.0, $ct->y_alokasi_tw2, 0.01);
        // Target Tahunan tampil (8/90*100=8,89%) -> capaianTahunan::targetTahunan().
        $this->assertEqualsWithDelta(8.89, $ct->targetTahunan(), 0.01);
    }

    public function test_konfirmasi_impor_menyimpan_capaian_tahunan_tipe_non_persen(): void
    {
        $this->loginSebagaiTimSakip();

        $import = $this->importDanKembalikanHasil([$this->rowNonPersen([5 => '3002'])]);

        Livewire::test(MasterIku::class)
            ->set('tahunImpor', 2026)
            ->set('pratinjau', $import->hasilValidasi)
            ->call('konfirmasiImpor')
            ->assertHasNoErrors();

        $iku = MasterIkuModel::where('kode', '3002')->first();
        $ct = CapaianTahunan::where('iku_id', $iku->id)->where('tahun', 2026)->first();

        $this->assertEqualsWithDelta(4.35, $ct->target_tahunan, 0.01);
        $this->assertEqualsWithDelta(1.09, $ct->alokasi_tw1, 0.01);
        $this->assertEqualsWithDelta(4.35, $ct->alokasiKumulatif(4), 0.01);
    }

    public function test_mode_insert_menolak_kode_yang_sudah_ada_mode_upsert_memperbarui(): void
    {
        $this->loginSebagaiTimSakip();

        MasterIkuModel::create(['kode' => '3003', 'indikator' => 'Lama', 'tim' => 'Tim Lama', 'penanggung_jawab' => 'PJ Lama']);

        $importInsert = $this->importDanKembalikanHasil([$this->rowNonPersen([5 => '3003'])], modeUpsert: false);
        $this->assertFalse($importInsert->hasilValidasi[0]['valid']);
        $this->assertStringContainsString('sudah ada di database', implode(' ', $importInsert->hasilValidasi[0]['errors']));

        $importUpsert = $this->importDanKembalikanHasil([$this->rowNonPersen([5 => '3003', 6 => 'Indikator Baru'])], modeUpsert: true);
        $this->assertTrue($importUpsert->hasilValidasi[0]['valid'], implode(' | ', $importUpsert->hasilValidasi[0]['errors']));

        Livewire::test(MasterIku::class)
            ->set('tahunImpor', 2026)
            ->set('modeImpor', 'upsert')
            ->set('pratinjau', $importUpsert->hasilValidasi)
            ->call('konfirmasiImpor')
            ->assertHasNoErrors();

        $this->assertSame(1, MasterIkuModel::where('kode', '3003')->count());
        $this->assertSame('Indikator Baru', MasterIkuModel::where('kode', '3003')->value('indikator'));
    }

    public function test_batalkan_semua_bila_error_tidak_menyimpan_apa_pun(): void
    {
        $this->loginSebagaiTimSakip();

        $import = $this->importDanKembalikanHasil([
            $this->rowNonPersen([5 => '3004']), // valid
            $this->rowPersen([5 => '3005', 13 => '']), // invalid (Target X kosong)
        ]);

        Livewire::test(MasterIku::class)
            ->set('tahunImpor', 2026)
            ->set('batalkanSemuaBilaError', true)
            ->set('pratinjau', $import->hasilValidasi)
            ->call('konfirmasiImpor');

        $this->assertSame(0, MasterIkuModel::whereIn('kode', ['3004', '3005'])->count());
    }

    public function test_batalkan_semua_bila_error_nonaktif_tetap_menyimpan_baris_valid(): void
    {
        $this->loginSebagaiTimSakip();

        $import = $this->importDanKembalikanHasil([
            $this->rowNonPersen([5 => '3006']), // valid
            $this->rowPersen([5 => '3007', 13 => '']), // invalid
        ]);

        Livewire::test(MasterIku::class)
            ->set('tahunImpor', 2026)
            ->set('batalkanSemuaBilaError', false)
            ->set('pratinjau', $import->hasilValidasi)
            ->call('konfirmasiImpor');

        $this->assertSame(1, MasterIkuModel::where('kode', '3006')->count());
        $this->assertSame(0, MasterIkuModel::where('kode', '3007')->count());
    }

    public function test_alur_unggah_end_to_end_pratinjau_lalu_konfirmasi(): void
    {
        $this->loginSebagaiTimSakip();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Master_IKU');
        $sheet->fromArray(MasterIkuImport::EXPECTED_HEADER, null, 'A1');
        $sheet->fromArray($this->rowPersen(), null, 'A2');
        $sheet->fromArray($this->rowNonPersen(), null, 'A3');
        $sheet->setCellValue('A4', \App\Exports\MasterIkuTemplateSheet::BARIS_PETUNJUK);
        $sheet->fromArray($this->rowNonPersen([5 => '4001']), null, 'A5');

        $tempPath = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($spreadsheet))->save($tempPath);
        $bytes = file_get_contents($tempPath);
        unlink($tempPath);

        $uploaded = UploadedFile::fake()->createWithContent('data.xlsx', $bytes);

        $component = Livewire::test(MasterIku::class)
            ->set('excelFile', $uploaded)
            ->set('tahunImpor', 2026)
            ->call('pratinjauExcel');

        $pratinjau = $component->get('pratinjau');
        $this->assertNotNull($pratinjau);
        $this->assertCount(1, $pratinjau);
        $this->assertTrue($pratinjau[0]['valid'], implode(' | ', $pratinjau[0]['errors'] ?? []));

        $component->call('konfirmasiImpor')->assertHasNoErrors();

        $this->assertSame(1, MasterIkuModel::where('kode', '4001')->count());
    }

    public function test_iku_baru_default_metode_langsung(): void
    {
        $this->loginSebagaiTimSakip();

        Livewire::test(MasterIku::class)
            ->set('kode', '9001')
            ->set('indikator', 'Indikator uji metode default')
            ->set('tim', 'Tim Uji')
            ->set('penanggungJawab', 'Petugas Uji')
            ->call('save')
            ->assertHasNoErrors();

        $iku = MasterIkuModel::where('kode', '9001')->first();
        $this->assertSame('langsung', $iku->metode_capaian);
        $this->assertFalse($iku->pakaiRasio());
    }

    public function test_iku_bisa_disimpan_dengan_metode_rasio_dan_label_x_y(): void
    {
        $this->loginSebagaiTimSakip();

        Livewire::test(MasterIku::class)
            ->set('kode', '9002')
            ->set('indikator', 'Persentase publikasi berkualitas')
            ->set('tim', 'Tim Uji')
            ->set('penanggungJawab', 'Petugas Uji')
            ->set('metodeCapaian', 'rasio')
            ->set('deskripsiX', 'Jumlah publikasi berkualitas')
            ->set('deskripsiY', 'Jumlah seluruh publikasi')
            ->call('save')
            ->assertHasNoErrors();

        $iku = MasterIkuModel::where('kode', '9002')->first();
        $this->assertTrue($iku->pakaiRasio());
        $this->assertSame('Jumlah publikasi berkualitas', $iku->deskripsi_x);
        $this->assertSame('Jumlah seluruh publikasi', $iku->deskripsi_y);
    }

    /**
     * Satuan HARUS selalu ikut Metode Perhitungan (Rasio->Persen, Langsung->Poin)
     * -- dipaksakan lewat MasterIku::booted()/saving(), bukan pilihan bebas
     * terpisah, supaya tidak bisa lagi ada kombinasi ganjil seperti IKU 'langsung'
     * berlabel "Persen" (kasus nyata yang pernah ditemukan: Indeks Pelayanan
     * Publik & Nilai SAKIP, seharusnya "Poin").
     */
    public function test_satuan_selalu_ikut_metode_capaian_walau_diisi_beda(): void
    {
        $this->loginSebagaiTimSakip();

        Livewire::test(MasterIku::class)
            ->set('kode', '9003')
            ->set('indikator', 'Indikator uji satuan otomatis')
            ->set('tim', 'Tim Uji')
            ->set('penanggungJawab', 'Petugas Uji')
            ->set('metodeCapaian', 'langsung')
            ->set('satuan', 'Persen')
            ->call('save')
            ->assertHasNoErrors();

        $iku = MasterIkuModel::where('kode', '9003')->first();
        $this->assertSame('Poin', $iku->satuan);
    }

    /**
     * Aturan yang sama berlaku saat IKU diedit lewat find()->update() (bukan cuma
     * create()) -- lihat App\Livewire\MasterIku::save(), yang sengaja dipindah
     * dari whereKey()->update() supaya event Eloquent ikut terpicu.
     */
    public function test_satuan_ikut_tersinkron_saat_metode_capaian_diubah_lewat_edit(): void
    {
        $this->loginSebagaiTimSakip();

        $iku = MasterIkuModel::create([
            'kode' => '9004', 'indikator' => 'Indikator uji edit satuan', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'metode_capaian' => MasterIkuModel::METODE_LANGSUNG,
        ]);
        $this->assertSame('Poin', $iku->fresh()->satuan);

        Livewire::test(MasterIku::class)
            ->call('edit', $iku->id)
            ->set('metodeCapaian', 'rasio')
            ->set('deskripsiX', 'X uji')
            ->set('deskripsiY', 'Y uji')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Persen', $iku->fresh()->satuan);
    }

    public function test_edit_iku_memuat_metode_capaian_yang_tersimpan(): void
    {
        $this->loginSebagaiTimSakip();

        $iku = MasterIkuModel::create([
            'kode' => '9003', 'indikator' => 'Indikator uji edit', 'tim' => 'Tim Uji',
            'penanggung_jawab' => 'Petugas Uji', 'metode_capaian' => 'rasio',
            'deskripsi_x' => 'X uji', 'deskripsi_y' => 'Y uji',
        ]);

        Livewire::test(MasterIku::class)
            ->call('edit', $iku->id)
            ->assertSet('metodeCapaian', 'rasio')
            ->assertSet('deskripsiX', 'X uji')
            ->assertSet('deskripsiY', 'Y uji');
    }

    public function test_iku_bisa_disimpan_dengan_dasar_hitung_dan_basis_data(): void
    {
        $this->loginSebagaiTimSakip();

        Livewire::test(MasterIku::class)
            ->set('kode', '9004')
            ->set('indikator', 'Indikator uji dasar hitung')
            ->set('tim', 'Tim Uji')
            ->set('penanggungJawab', 'Petugas Uji')
            ->set('dasarHitung', 'Jumlah realisasi dibagi target dikali 100')
            ->set('basisData', 'Data internal BPS')
            ->call('save')
            ->assertHasNoErrors();

        $iku = MasterIkuModel::where('kode', '9004')->first();
        $this->assertSame('Jumlah realisasi dibagi target dikali 100', $iku->dasar_hitung);
        $this->assertSame('Data internal BPS', $iku->basis_data);
    }

    public function test_edit_iku_memuat_dasar_hitung_dan_basis_data_yang_tersimpan(): void
    {
        $this->loginSebagaiTimSakip();

        $iku = MasterIkuModel::create([
            'kode' => '9007', 'indikator' => 'Indikator uji edit dasar hitung', 'tim' => 'Tim Uji',
            'penanggung_jawab' => 'Petugas Uji', 'dasar_hitung' => 'Rumus uji', 'basis_data' => 'Sumber uji',
        ]);

        Livewire::test(MasterIku::class)
            ->call('edit', $iku->id)
            ->assertSet('dasarHitung', 'Rumus uji')
            ->assertSet('basisData', 'Sumber uji');
    }

    public function test_iku_langsung_bisa_disimpan_dengan_formula_capaian_kustom(): void
    {
        $this->loginSebagaiTimSakip();

        Livewire::test(MasterIku::class)
            ->set('kode', '9009')
            ->set('indikator', 'Indikator uji formula')
            ->set('tim', 'Tim Uji')
            ->set('penanggungJawab', 'Petugas Uji')
            ->set('metodeCapaian', 'langsung')
            ->set('formulaCapaian', 'min(realisasi / alokasi * 100, batas)')
            ->call('save')
            ->assertHasNoErrors();

        $iku = MasterIkuModel::where('kode', '9009')->first();
        $this->assertSame('min(realisasi / alokasi * 100, batas)', $iku->formula_capaian);
    }

    public function test_formula_capaian_tidak_valid_ditolak(): void
    {
        $this->loginSebagaiTimSakip();

        Livewire::test(MasterIku::class)
            ->set('kode', '9010')
            ->set('indikator', 'Indikator uji formula salah')
            ->set('tim', 'Tim Uji')
            ->set('penanggungJawab', 'Petugas Uji')
            ->set('metodeCapaian', 'langsung')
            ->set('formulaCapaian', 'realisasi / / alokasi')
            ->call('save')
            ->assertHasErrors(['formulaCapaian']);

        $this->assertDatabaseMissing('master_iku', ['kode' => '9010']);
    }

    public function test_formula_capaian_dipaksa_kosong_untuk_metode_rasio(): void
    {
        $this->loginSebagaiTimSakip();

        Livewire::test(MasterIku::class)
            ->set('kode', '9011')
            ->set('indikator', 'Indikator uji formula rasio')
            ->set('tim', 'Tim Uji')
            ->set('penanggungJawab', 'Petugas Uji')
            ->set('metodeCapaian', 'rasio')
            // formulaCapaian tidak pernah tampil di form untuk rasio, tapi tetap
            // dicoba diisi langsung ke properti komponen di sini -- harus dipaksa
            // null saat disimpan (lihat App\Livewire\MasterIku::save()).
            ->set('formulaCapaian', 'alokasi + realisasi')
            ->call('save')
            ->assertHasNoErrors();

        $iku = MasterIkuModel::where('kode', '9011')->first();
        $this->assertNull($iku->formula_capaian);
    }

    public function test_edit_iku_tanpa_tim_dan_penanggung_jawab_dari_import_tidak_error(): void
    {
        $this->loginSebagaiTimSakip();

        // IKU hasil import belum punya Tim/Penanggung Jawab (kolom nullable, lihat
        // migrasi 2026_08_26_000002) — form edit tidak boleh error saat memuatnya.
        $iku = MasterIkuModel::create([
            'kode' => '9008', 'indikator' => 'Indikator hasil import', 'tim' => null, 'penanggung_jawab' => null,
        ]);

        Livewire::test(MasterIku::class)
            ->call('edit', $iku->id)
            ->assertSet('tim', '')
            ->assertSet('penanggungJawab', '');
    }
}
