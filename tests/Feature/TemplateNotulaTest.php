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

    public function test_tim_sakip_bisa_mengunggah_template_notula(): void
    {
        Storage::fake('local');

        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip@example.test', 'email' => 'sakip@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        Livewire::test(TemplateNotula::class)
            ->set('templateFile', UploadedFile::fake()->create('template.docx', 50))
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
            ->set('templateFile', UploadedFile::fake()->create('template.docx', 50))
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
            ->set('templateFile', UploadedFile::fake()->create('template.docx', 50))
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

    public function test_hapus_template_menghapus_berkas_dan_referensinya(): void
    {
        Storage::fake('local');

        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip2@example.test', 'email' => 'sakip2@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        Livewire::test(TemplateNotula::class)
            ->set('templateFile', UploadedFile::fake()->create('template.docx', 50))
            ->call('unggah');

        $path = FolderConfig::current()->template_notula_path;

        Livewire::test(TemplateNotula::class)->call('hapus');

        $this->assertNull(FolderConfig::current()->template_notula_path);
        Storage::disk('local')->assertMissing($path);
    }
}
