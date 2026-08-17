<?php

namespace Tests\Feature;

use App\Livewire\VerifikasiList;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VerifikasiListTest extends TestCase
{
    use RefreshDatabase;

    public function test_pencarian_menyaring_daftar_berdasarkan_kode_atau_indikator(): void
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip@example.test', 'email' => 'sakip@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        $ikuA = MasterIku::create(['kode' => 'ALPHA-1', 'indikator' => 'Indikator Alpha', 'tim' => 'Tim A', 'penanggung_jawab' => 'PJ A']);
        $ikuB = MasterIku::create(['kode' => 'BETA-2', 'indikator' => 'Indikator Beta', 'tim' => 'Tim B', 'penanggung_jawab' => 'PJ B']);

        Kegiatan::create(['iku_id' => $ikuA->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'K1', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'diajukan']);
        Kegiatan::create(['iku_id' => $ikuB->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'K2', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'diajukan']);
        Capaian::create(['iku_id' => $ikuA->id, 'periode_id' => $periode->id]);
        Capaian::create(['iku_id' => $ikuB->id, 'periode_id' => $periode->id]);

        Livewire::test(VerifikasiList::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->set('cari', 'ALPHA')
            ->assertSee('ALPHA-1')
            ->assertDontSee('BETA-2');
    }

    public function test_reset_filter_mengembalikan_pencarian_kosong(): void
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip2@example.test', 'email' => 'sakip2@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        Livewire::test(VerifikasiList::class)
            ->set('cari', 'sesuatu')
            ->call('resetFilter')
            ->assertSet('cari', '');
    }

    public function test_memilih_bulan_langsung_menyesuaikan_triwulan(): void
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip3@example.test', 'email' => 'sakip3@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $component = Livewire::test(VerifikasiList::class)
            ->set('bulan', '8'); // Agustus -> Triwulan III, tanpa memilih triwulan dulu.

        $this->assertSame(3, $component->get('triwulan'));
    }

    public function test_jumlah_kegiatan_pendukung_tampil_benar_tanpa_n_plus_1(): void
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip4@example.test', 'email' => 'sakip4@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $iku = MasterIku::create(['kode' => 'GAMMA-3', 'indikator' => 'Indikator Gamma', 'tim' => 'Tim C', 'penanggung_jawab' => 'PJ C']);

        Kegiatan::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'K1', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'diajukan']);
        Kegiatan::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'K2', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'diajukan']);
        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id]);

        Livewire::test(VerifikasiList::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->assertSee('2 kegiatan');
    }
}
