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
use Illuminate\Support\Carbon;
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
            'nama' => "$peranNama Uji", 'username' => strtolower(str_replace(' ', '', $peranNama)), 'email' => strtolower(str_replace(' ', '', $peranNama)).'@example.test',
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

    public function test_peringatan_iku_belum_terisi_triwulan_berjalan(): void
    {
        Carbon::setTestNow('2026-08-15'); // Triwulan III 2026 — sama seperti fixture siapkanData().

        $this->loginSebagai('Tim SAKIP');
        $this->siapkanData();

        // IKU ketiga ini sengaja TIDAK diberi kegiatan sama sekali — harus muncul di peringatan.
        MasterIku::create(['kode' => 'GAMMA-3', 'indikator' => 'Indikator Gamma', 'tim' => 'Tim C', 'penanggung_jawab' => 'PJ C']);

        Livewire::test(DasborUtama::class)
            ->assertSee('1 IKU belum ada isian')
            ->assertSee('GAMMA-3');

        Carbon::setTestNow();
    }

    public function test_baris_tim_sakip_tertaut_ke_verifikasi_show(): void
    {
        $this->loginSebagai('Tim SAKIP');
        $this->siapkanData();

        $response = $this->get('/dashboard');
        $response->assertOk();
        $response->assertSee('verifikasi/', false);
    }

    public function test_baris_ketua_tim_tertaut_ke_pengisian_dengan_iku_terpilih(): void
    {
        $this->loginSebagai('Ketua Tim');
        $this->siapkanData();

        $ikuA = MasterIku::where('kode', 'ALPHA-1')->firstOrFail();

        $response = $this->get('/dashboard');
        $response->assertOk();
        $response->assertSee('iku_id='.$ikuA->id, false);
    }
}
