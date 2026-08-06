<?php

namespace Tests\Feature;

use App\Livewire\TemplateNotula;
use App\Models\FolderConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TemplateNotulaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tim_sakip_bisa_mengunggah_template_notula(): void
    {
        Storage::fake('local');

        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'email' => 'sakip@example.test', 'password' => 'password',
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

    public function test_hapus_template_menghapus_berkas_dan_referensinya(): void
    {
        Storage::fake('local');

        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'email' => 'sakip2@example.test', 'password' => 'password',
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
