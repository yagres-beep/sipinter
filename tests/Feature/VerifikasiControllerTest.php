<?php

namespace Tests\Feature;

use App\Models\Capaian;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifikasiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function loginSebagaiTimSakip(): User
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $user = User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip-vc@example.test', 'email' => 'sakip-vc@example.test',
            'password' => 'password', 'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_capaian_draft_tidak_bisa_dibuka_tim_sakip(): void
    {
        $this->loginSebagaiTimSakip();

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $iku = MasterIku::create(['kode' => 'ETA-7', 'indikator' => 'Indikator Eta', 'tim' => 'Tim E', 'penanggung_jawab' => 'PJ E']);
        $capaian = Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DRAFT]);

        $response = $this->get(route('verifikasi.show', $capaian));

        $response->assertRedirect(route('verifikasi.index'));
        $response->assertSessionHas('status');
    }

    public function test_capaian_diajukan_tetap_bisa_dibuka_tim_sakip(): void
    {
        $this->loginSebagaiTimSakip();

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $iku = MasterIku::create(['kode' => 'THETA-8', 'indikator' => 'Indikator Theta', 'tim' => 'Tim T', 'penanggung_jawab' => 'PJ T']);
        $capaian = Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);

        $response = $this->get(route('verifikasi.show', $capaian));

        $response->assertOk();
    }
}
