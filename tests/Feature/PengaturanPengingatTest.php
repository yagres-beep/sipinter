<?php

namespace Tests\Feature;

use App\Livewire\PengaturanPengingat;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regresi: view pengaturan-pengingat.blade.php memanggil $this->konversiJam(),
 * tapi method itu sempat hanya ada di working directory lokal (belum pernah
 * di-commit) sementara blade pemanggilnya sudah live di produksi — menyebabkan
 * BadMethodCallException / 500 setiap kali halaman ini dibuka.
 */
class PengaturanPengingatTest extends TestCase
{
    use RefreshDatabase;

    protected function loginSebagaiTimSakip(): User
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $user = User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip-pengingat@example.test', 'email' => 'sakip-pengingat@example.test',
            'password' => 'password', 'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_halaman_pengaturan_pengingat_tampil_tanpa_error(): void
    {
        $this->loginSebagaiTimSakip();

        Livewire::test(PengaturanPengingat::class)
            ->assertOk();
    }

    public function test_konversi_jam_menampilkan_padanan_wib_wita_wit(): void
    {
        $component = new PengaturanPengingat;
        $component->jamKirim = '01:00';

        $this->assertSame('08:00 WIB · 09:00 WITA · 10:00 WIT', $component->konversiJam());
    }

    public function test_konversi_jam_null_bila_format_belum_valid(): void
    {
        $component = new PengaturanPengingat;
        $component->jamKirim = 'bukan-jam';

        $this->assertNull($component->konversiJam());
    }
}
