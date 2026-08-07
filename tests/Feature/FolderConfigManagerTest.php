<?php

namespace Tests\Feature;

use App\Livewire\FolderConfigManager;
use App\Models\IkuFolderConfig;
use App\Models\MasterIku;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FolderConfigManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsSakip(): User
    {
        $peran = Role::firstOrCreate(['nama' => 'Tim SAKIP']);

        $user = User::create([
            'nama' => 'SAKIP Uji', 'email' => 'sakip-'.uniqid().'@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $this->actingAs($user);

        return $user;
    }

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

    public function test_pilih_iku_tanpa_override_memuat_salinan_pola_global(): void
    {
        $this->actingAsSakip();
        $iku = MasterIku::create(['kode' => '1010', 'indikator' => 'Uji', 'tim' => 'Sosial', 'penanggung_jawab' => 'A']);

        $component = Livewire::test(FolderConfigManager::class)
            ->call('pilihIku', $iku->id);

        $this->assertFalse($component->get('ikuPunyaOverride'));
        $this->assertSame($component->get('hierarki'), $component->get('hierarkiIku'));
    }

    public function test_simpan_pola_iku_membuat_override_tanpa_mengubah_pola_global(): void
    {
        $this->actingAsSakip();
        $iku = MasterIku::create(['kode' => '1011', 'indikator' => 'Uji', 'tim' => 'Sosial', 'penanggung_jawab' => 'A']);
        $ikuLain = MasterIku::create(['kode' => '1012', 'indikator' => 'Uji lain', 'tim' => 'Sosial', 'penanggung_jawab' => 'A']);

        Livewire::test(FolderConfigManager::class)
            ->call('pilihIku', $iku->id)
            ->set('levelBaruIku', 'Bulan Khusus')
            ->call('tambahLevelIku')
            ->call('simpanPolaIku')
            ->assertSet('ikuPunyaOverride', true);

        $override = IkuFolderConfig::where('iku_id', $iku->id)->first();
        $this->assertNotNull($override);
        $this->assertTrue(collect($override->pola_json['hierarki'])->contains(fn ($h) => $h['level'] === 'Bulan Khusus'));

        // IKU lain tidak ikut punya override.
        $this->assertNull(IkuFolderConfig::where('iku_id', $ikuLain->id)->first());
    }

    public function test_hapus_override_iku_mengembalikan_ke_pola_global(): void
    {
        $this->actingAsSakip();
        $iku = MasterIku::create(['kode' => '1013', 'indikator' => 'Uji', 'tim' => 'Sosial', 'penanggung_jawab' => 'A']);

        $component = Livewire::test(FolderConfigManager::class)
            ->call('pilihIku', $iku->id)
            ->call('simpanPolaIku')
            ->assertSet('ikuPunyaOverride', true)
            ->call('hapusOverrideIku')
            ->assertSet('ikuPunyaOverride', false);

        $this->assertNull(IkuFolderConfig::where('iku_id', $iku->id)->first());
    }

    public function test_buat_folder_manual_menolak_nama_folder_kosong(): void
    {
        $this->actingAsSakip();

        Livewire::test(FolderConfigManager::class)
            ->set('manualTahun', 2026)
            ->set('manualNamaFolder', '')
            ->call('buatFolderManual')
            ->assertHasErrors(['manualNamaFolder']);
    }

    public function test_buat_folder_manual_gagal_dengan_pesan_jelas_bila_belum_ada_storage_aktif(): void
    {
        $this->actingAsSakip();

        // Tanpa StorageAccount aktif di database uji — harus gagal dengan pesan yang
        // jelas (RuntimeException dari FolderStructureService), BUKAN error tak tertangani.
        Livewire::test(FolderConfigManager::class)
            ->set('manualTahun', 2026)
            ->set('manualNamaFolder', 'Dokumen Tambahan')
            ->call('buatFolderManual')
            ->assertHasErrors(['manualNamaFolder']);
    }
}
