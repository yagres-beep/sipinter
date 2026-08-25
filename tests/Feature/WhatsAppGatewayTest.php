<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WhatsAppGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsTimSakip(): User
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $user = User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip@example.test', 'email' => 'sakip@example.test',
            'password' => 'password', 'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $this->actingAs($user);

        return $user;
    }

    public function test_tab_pengingat_wa_tampil_di_halaman_kelola_pengguna(): void
    {
        $this->actingAsTimSakip();

        $this->get(route('verifikasi-akun.index'))
            ->assertOk()
            ->assertSee('Pengingat WA')
            ->assertSeeLivewire('whats-app-gateway');
    }

    public function test_status_gateway_error_saat_belum_terkonfigurasi(): void
    {
        $this->actingAsTimSakip();

        config(['services.whatsapp.api_url' => null, 'services.whatsapp.api_token' => null]);

        Livewire::test('whats-app-gateway')
            ->assertSee('Tidak bisa menghubungi gateway WhatsApp');
    }
}
