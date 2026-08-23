<?php

namespace Tests\Feature;

use App\Livewire\PengaturanCapaian;
use App\Models\PengaturanCapaian as PengaturanCapaianModel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PengaturanCapaianTest extends TestCase
{
    use RefreshDatabase;

    protected function loginSebagaiTimSakip(): User
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);

        $user = User::create([
            'nama' => 'Tim SAKIP Uji',
            'username' => 'sakip-uji@example.test', 'email' => 'sakip-uji@example.test',
            'password' => 'password',
            'role_id' => $peran->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $this->actingAs($user);

        return $user;
    }

    public function test_simpan_mengubah_pengaturan_format_nol(): void
    {
        $this->loginSebagaiTimSakip();

        Livewire::test(PengaturanCapaian::class)
            ->assertSet('tampilkanNolSebagaiStrip', false)
            ->set('tampilkanNolSebagaiStrip', true)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertTrue((bool) PengaturanCapaianModel::ambil()->tampilkan_nol_sebagai_strip);
    }
}
