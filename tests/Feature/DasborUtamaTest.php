<?php

namespace Tests\Feature;

use App\Livewire\DasborUtama;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DasborUtamaTest extends TestCase
{
    use RefreshDatabase;

    protected function siapkanData(): void
    {
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        $ikuA = MasterIku::create(['kode' => 'ALPHA-1', 'indikator' => 'Indikator Alpha', 'tim' => 'Tim A', 'penanggung_jawab' => 'PJ A']);
        $ikuB = MasterIku::create(['kode' => 'BETA-2', 'indikator' => 'Indikator Beta', 'tim' => 'Tim B', 'penanggung_jawab' => 'PJ B']);

        Kegiatan::create(['iku_id' => $ikuA->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'K1', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'diajukan']);
        Kegiatan::create(['iku_id' => $ikuB->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'K2', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'diverifikasi']);
        Capaian::create(['iku_id' => $ikuA->id, 'periode_id' => $periode->id]);
        Capaian::create(['iku_id' => $ikuB->id, 'periode_id' => $periode->id]);
    }

    protected function loginSebagai(string $peranNama): User
    {
        $peran = Role::firstOrCreate(['nama' => $peranNama]);
        $user = User::create([
            'nama' => "$peranNama Uji", 'email' => strtolower(str_replace(' ', '', $peranNama)).'@example.test',
            'password' => 'password', 'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_pencarian_menyaring_tabel_dasbor(): void
    {
        $this->loginSebagai('Tim SAKIP');
        $this->siapkanData();

        Livewire::test(DasborUtama::class)
            ->set('cari', 'ALPHA')
            ->assertSee('ALPHA-1')
            ->assertDontSee('BETA-2');
    }

    public function test_filter_triwulan_menyaring_kegiatan_di_luar_triwulan(): void
    {
        $this->loginSebagai('Ketua Tim');
        $this->siapkanData();

        Livewire::test(DasborUtama::class)
            ->set('filterTriwulan', '1')
            ->assertDontSee('ALPHA-1')
            ->assertDontSee('BETA-2');
    }

    public function test_memilih_bulan_langsung_menyesuaikan_filter_triwulan(): void
    {
        $this->loginSebagai('Ketua Tim');
        $this->siapkanData();

        $component = Livewire::test(DasborUtama::class)
            ->set('filterBulan', '4'); // April -> Triwulan II, tanpa memilih triwulan dulu.

        $this->assertSame('2', $component->get('filterTriwulan'));
    }

    public function test_baris_tim_sakip_tertaut_ke_verifikasi_show(): void
    {
        $this->loginSebagai('Tim SAKIP');
        $this->siapkanData();

        $response = $this->get('/dashboard');
        $response->assertOk();
        $response->assertSee('verifikasi/', false);
    }
}
