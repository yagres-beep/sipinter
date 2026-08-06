<?php

namespace Tests\Feature;

use App\Livewire\FolderConfigManager;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FolderConfigManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_bisa_menambah_dan_menghapus_tingkat_folder_kustom(): void
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'email' => 'sakip@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $component = Livewire::test(FolderConfigManager::class)
            ->set('levelBaru', 'Kategori Survei')
            ->call('tambahLevel')
            ->assertHasNoErrors();

        $hierarki = $component->get('hierarki');
        $this->assertTrue(collect($hierarki)->contains(fn ($h) => $h['level'] === 'Kategori Survei' && ($h['custom'] ?? false)));

        $index = collect($hierarki)->search(fn ($h) => $h['level'] === 'Kategori Survei');

        $component->call('hapusLevel', $index);

        $this->assertFalse(collect($component->get('hierarki'))->contains(fn ($h) => $h['level'] === 'Kategori Survei'));
    }

    public function test_nama_tingkat_kosong_ditolak(): void
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'email' => 'sakip2@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        Livewire::test(FolderConfigManager::class)
            ->set('levelBaru', '')
            ->call('tambahLevel')
            ->assertHasErrors(['levelBaru']);
    }
}
