<?php

namespace Tests\Feature;

use App\Livewire\TemplateNotula;
use App\Models\FolderConfig;
use App\Models\Role;
use App\Models\User;
use App\Services\FolderStructureService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class TemplateNotulaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * UploadedFile::fake()->create() bikin berkas OMONG KOSONG (bukan .docx/zip sungguhan) --
     * sejak unggah() memvalidasi struktur template (NotulaBagian1DocxService::validasiStrukturTemplate(),
     * lihat test_unggah_menolak_template_yang_penanda_bloknya_tidak_lengkap()), berkas asal-asalan itu
     * akan DITOLAK. Test yang cuma mau menguji mekanisme simpan/unduh/arsip (bukan validasi strukturnya
     * sendiri) makanya pakai isi template BAWAAN sungguhan di sini supaya lolos validasi.
     */
    private function templateValidFake(string $nama = 'template.docx'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $nama,
            file_get_contents(base_path('template_notula/SIPINTER_Template_Bagian_I_Mesin.docx'))
        );
    }

    /**
     * Template BAWAAN yang sengaja dirusak (penanda {{/iku_blok}} dibuang dari word/document.xml
     * lewat ZipArchive) -- mensimulasikan kesalahan nyata yang memicu bug ini: Tim SAKIP menyunting
     * ulang template di Word dan penanda penutup blok IKU-nya tidak sengaja ikut terhapus/tidak
     * tersalin, lalu berkas itu diunggah lewat Pengaturan > Template Notula.
     */
    private function templateRusakFake(string $nama = 'template-rusak.docx'): UploadedFile
    {
        $temp = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        copy(base_path('template_notula/SIPINTER_Template_Bagian_I_Mesin.docx'), $temp);

        $zip = new \ZipArchive();
        $zip->open($temp);
        $xml = str_replace('{{/iku_blok}}', '', $zip->getFromName('word/document.xml'));
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        $isi = file_get_contents($temp);
        unlink($temp);

        return UploadedFile::fake()->createWithContent($nama, $isi);
    }

    public function test_tim_sakip_bisa_mengunggah_template_notula(): void
    {
        Storage::fake('local');

        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip@example.test', 'email' => 'sakip@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        Livewire::test(TemplateNotula::class)
            ->set('templateFile', $this->templateValidFake())
            ->call('unggah')
            ->assertHasNoErrors();

        $config = FolderConfig::current();
        $this->assertNotNull($config->template_notula_path);
        $this->assertSame('template.docx', $config->template_notula_nama_asli);
        Storage::disk('local')->assertExists($config->template_notula_path);
    }

    public function test_unduh_template_notula_mengembalikan_berkas_tersimpan(): void
    {
        Storage::fake('local');

        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip3@example.test', 'email' => 'sakip3@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        Livewire::test(TemplateNotula::class)
            ->set('templateFile', $this->templateValidFake())
            ->call('unggah');

        Livewire::test(TemplateNotula::class)
            ->call('unduh')
            ->assertFileDownloaded('template.docx');
    }

    public function test_unggah_mengarsipkan_template_ke_drive_dan_menyimpan_rujukannya(): void
    {
        Storage::fake('local');

        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip4@example.test', 'email' => 'sakip4@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $this->instance(
            FolderStructureService::class,
            Mockery::mock(FolderStructureService::class, function ($mock) {
                $mock->shouldReceive('unggahTemplateNotula')
                    ->once()
                    ->andReturn(['drive_file_id' => 'drive-file-123', 'storage_account_id' => null]);
            })
        );

        Livewire::test(TemplateNotula::class)
            ->set('templateFile', $this->templateValidFake())
            ->call('unggah')
            ->assertHasNoErrors();

        $config = FolderConfig::current();
        $this->assertSame('drive-file-123', $config->template_notula_drive_file_id);
    }

    public function test_unduh_jatuh_ke_drive_saat_salinan_lokal_sudah_tidak_ada(): void
    {
        Storage::fake('local');

        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip5@example.test', 'email' => 'sakip5@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        // Baris folder_config mengarah ke path lokal yang TIDAK ada di disk fake ini
        // (mensimulasikan container Render yang sudah di-deploy ulang) tapi tetap
        // punya rujukan Drive dari unggahan sebelumnya.
        FolderConfig::current()->update([
            'template_notula_path' => 'template-notula/sudah-hilang.docx',
            'template_notula_nama_asli' => 'template.docx',
            'template_notula_drive_file_id' => 'drive-file-123',
        ]);

        $this->instance(
            GoogleDriveService::class,
            Mockery::mock(GoogleDriveService::class, function ($mock) {
                $mock->shouldReceive('downloadFileContent')
                    ->once()
                    ->with('drive-file-123')
                    ->andReturn('isi-docx-dari-drive');
            })
        );

        Livewire::test(TemplateNotula::class)
            ->call('unduh')
            ->assertFileDownloaded('template.docx', 'isi-docx-dari-drive');
    }

    /**
     * Regresi bug produksi: berkas yang penanda blok IKU-nya tidak lengkap (mis. {{/iku_blok}}
     * hilang) HARUS ditolak saat diunggah -- bukan diterima lalu baru meruntuhkan 500 halaman
     * Kompilasi Notula bagi semua pemakai begitu ada yang mencoba menyusun Bagian I dengannya.
     */
    public function test_unggah_menolak_template_yang_penanda_bloknya_tidak_lengkap(): void
    {
        Storage::fake('local');

        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip6@example.test', 'email' => 'sakip6@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        Livewire::test(TemplateNotula::class)
            ->set('templateFile', $this->templateRusakFake())
            ->call('unggah')
            ->assertHasErrors('templateFile');

        $this->assertNull(FolderConfig::current()->template_notula_path);
    }

    public function test_hapus_template_menghapus_berkas_dan_referensinya(): void
    {
        Storage::fake('local');

        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip2@example.test', 'email' => 'sakip2@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        Livewire::test(TemplateNotula::class)
            ->set('templateFile', $this->templateValidFake())
            ->call('unggah');

        $path = FolderConfig::current()->template_notula_path;

        Livewire::test(TemplateNotula::class)->call('hapus');

        $this->assertNull(FolderConfig::current()->template_notula_path);
        Storage::disk('local')->assertMissing($path);
    }
}
