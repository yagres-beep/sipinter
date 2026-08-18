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
        $this->assertSame('Sasaran Uji', MasterIkuModel::where('kode', 'IKU-2000')->value('sasaran'));
    }
}
