<?php

namespace Tests\Feature;

use App\Exports\MasterIkuTemplateExport;
use App\Imports\MasterIkuImport;
use App\Livewire\MasterIku;
use App\Models\MasterIku as MasterIkuModel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Tests\TestCase;

class MasterIkuTest extends TestCase
{
    use RefreshDatabase;

    public function test_tim_sakip_bisa_mengunduh_template_excel(): void
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip@example.test', 'email' => 'sakip@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        // Regresi: import Excel facade sempat salah namespace (Illuminate\Support\Facades\Excel,
        // seharusnya Maatwebsite\Excel\Facades\Excel) sehingga tombol ini melempar
        // "Class not found" alih-alih mengunduh berkas â€” lihat perbaikan di app/Livewire/MasterIku.php.
        Livewire::test(MasterIku::class)
            ->call('downloadTemplate')
            ->assertFileDownloaded('template-master-iku.xlsx');
    }

    public function test_template_terunduh_bisa_diunggah_balik_tanpa_error_meski_ada_sheet_daftar_nama(): void
    {
        // Regresi: template resmi punya 2 sheet (Master IKU + Daftar Nama referensi).
        // Tanpa WithMultipleSheets pada sisi import, Maatwebsite Excel memanggil
        // collection() untuk SETIAP sheet pada berkas yang diunggah balik — sheet
        // "Daftar Nama" harus dilewati diam-diam, bukan dianggap format salah.
        Storage::fake('local');

        $relativePath = 'template-uji.xlsx';
        ExcelFacade::store(new MasterIkuTemplateExport, $relativePath, 'local');

        $import = new MasterIkuImport;
        ExcelFacade::import($import, Storage::disk('local')->path($relativePath));

        $this->assertSame([], $import->errors);
    }

    public function test_impor_menyimpan_kolom_sasaran(): void
    {
        $import = new MasterIkuImport;
        $import->collection(collect([
            MasterIkuImport::EXPECTED_HEADER,
            ['IKU-1131', 'Persentase publikasi tepat waktu', 'Produksi Statistik', 'Nama Penanggung Jawab', 'Statistik Kesejahteraan Rakyat'],
            [\App\Exports\MasterIkuTemplateSheet::BARIS_PETUNJUK, '', '', '', ''],
            ['IKU-2000', 'Indikator uji', 'Tim Uji', 'Petugas Uji', 'Sasaran Uji'],
        ]));

        $this->assertSame([], $import->errors);
        // Kode disimpan dinormalisasi tanpa prefix non-digit (lihat MasterIkuImport::collection()),
        // sama seperti "IKU-2000" -> "2000" -- query di sini harus ikut memakai bentuk tersimpan.
        $this->assertSame('Sasaran Uji', MasterIkuModel::where('kode', '2000')->value('sasaran'));
    }

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
}
