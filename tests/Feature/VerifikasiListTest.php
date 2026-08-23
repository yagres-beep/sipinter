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
        Capaian::create(['iku_id' => $ikuA->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);
        Capaian::create(['iku_id' => $ikuB->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);

        Livewire::test(VerifikasiList::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->set('cari', 'ALPHA')
            ->assertSee('ALPHA-1')
            ->assertDontSee('BETA-2');
    }

    public function test_capaian_sedang_ditangani_tetap_muncul_di_daftar(): void
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip-st@example.test', 'email' => 'sakip-st@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $iku = MasterIku::create(['kode' => 'GAMMA-3', 'indikator' => 'Indikator Gamma', 'tim' => 'Tim C', 'penanggung_jawab' => 'PJ C']);
        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_SEDANG_DITANGANI]);

        // Opsi filter memuat "sedang_ditangani" (dulu sempat hilang dari daftar, lihat
        // App\Livewire\VerifikasiList::statusTersedia()).
        $this->assertContains(Capaian::STATUS_SEDANG_DITANGANI, VerifikasiList::statusTersedia());

        Livewire::test(VerifikasiList::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->set('status', Capaian::STATUS_SEDANG_DITANGANI)
            ->assertSee('GAMMA-3');
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
        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);

        Livewire::test(VerifikasiList::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->assertSee('2 kegiatan');
    }

    /**
     * Skenario: 1 IKU, 5 kegiatan (3 diverifikasi + 2 dikembalikan) — status BESAR
     * Capaian-nya "dikembalikan" (Tim SAKIP baru saja mengembalikan 2 kegiatan yang
     * tidak sesuai), tapi rincian per status tiap kegiatan harus tetap terlihat di
     * tabel supaya tidak membingungkan.
     */
    public function test_rincian_status_kegiatan_tampil_saat_sebagian_dikembalikan(): void
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip5@example.test', 'email' => 'sakip5@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $iku = MasterIku::create(['kode' => 'DELTA-4', 'indikator' => 'Indikator Delta', 'tim' => 'Tim D', 'penanggung_jawab' => 'PJ D']);

        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIKEMBALIKAN]);

        for ($i = 1; $i <= 3; $i++) {
            Kegiatan::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => "Kegiatan verif {$i}", 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'diverifikasi']);
        }
        for ($i = 1; $i <= 2; $i++) {
            Kegiatan::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => "Kegiatan kembali {$i}", 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'dikembalikan']);
        }

        Livewire::test(VerifikasiList::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->set('status', 'dikembalikan')
            ->assertSee('5 kegiatan')
            ->assertSee('3 Diverifikasi')
            ->assertSee('2 Dikembalikan');
    }

    /**
     * Skenario: 1 IKU, 4 kegiatan sudah diverifikasi, lalu Ketua Tim menambah 1
     * kegiatan baru dan mengajukannya — status BESAR Capaian balik jadi "diajukan"
     * (butuh diperiksa lagi), tapi rincian harus tetap menunjukkan 4 kegiatan lama
     * yang sudah diverifikasi TIDAK ikut berubah, hanya 1 yang baru diajukan.
     */
    public function test_rincian_status_kegiatan_tampil_saat_ada_kegiatan_tambahan(): void
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip6@example.test', 'email' => 'sakip6@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $iku = MasterIku::create(['kode' => 'EPSILON-5', 'indikator' => 'Indikator Epsilon', 'tim' => 'Tim E', 'penanggung_jawab' => 'PJ E']);

        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);

        for ($i = 1; $i <= 4; $i++) {
            Kegiatan::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => "Kegiatan lama {$i}", 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'diverifikasi']);
        }
        Kegiatan::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'Kegiatan baru', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'diajukan']);

        Livewire::test(VerifikasiList::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->set('status', 'diajukan')
            ->assertSee('5 kegiatan')
            ->assertSee('4 Diverifikasi')
            ->assertSee('1 Diajukan');
    }
}
