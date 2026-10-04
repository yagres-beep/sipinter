<?php

namespace Tests\Feature;

use App\Livewire\PengisianKegiatan;
use App\Livewire\VerifikasiCapaian;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Services\FolderStructureService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class GantiNamaBerkasTest extends TestCase
{
    use RefreshDatabase;

    protected function buatUser(string $peran, string $username): User
    {
        $role = Role::firstOrCreate(['nama' => $peran]);

        return User::create([
            'nama' => $username,
            'username' => $username, 'email' => "{$username}@example.test",
            'password' => 'password',
            'role_id' => $role->id,
            'status_verifikasi' => 'terverifikasi',
        ]);
    }

    protected function siapkanBerkas(?User $pengunggah = null): array
    {
        $iku = MasterIku::create(['kode' => '1111', 'indikator' => 'Persentase Publikasi/Laporan Uji']);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $capaian = Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);
        $kegiatan = Kegiatan::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'Kegiatan uji',
            'jenis' => 'bukan_survei_sensus', 'status_dokumen' => Kegiatan::STATUS_DIAJUKAN,
        ]);
        $berkas = Berkas::create([
            'ref_id' => $kegiatan->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian',
            'nama_file' => 'Kegiatan uji.pdf', 'status_verifikasi' => 'menunggu',
            'diunggah_oleh' => $pengunggah?->id,
        ]);

        return compact('iku', 'capaian', 'berkas');
    }

    public function test_nama_folder_iku_berisi_kode_diikuti_indikator(): void
    {
        $iku = new MasterIku(['kode' => '1111', 'indikator' => 'Persentase Publikasi/Laporan Statistik']);

        $this->assertSame('1111. Persentase Publikasi Laporan Statistik', FolderStructureService::namaFolderIku($iku));
    }

    public function test_pengunggah_tercatat_otomatis_saat_berkas_dibuat(): void
    {
        $ketua = $this->buatUser('Ketua Tim', 'ketua');
        $this->actingAs($ketua);

        $data = $this->siapkanBerkas();

        $this->assertSame($ketua->id, $data['berkas']->fresh()->diunggah_oleh);
    }

    public function test_tim_sakip_bisa_ganti_nama_dan_ekstensi_dipertahankan_serta_drive_ikut_diganti(): void
    {
        $this->actingAs($this->buatUser('Tim SAKIP', 'sakip'));
        $data = $this->siapkanBerkas($this->buatUser('Ketua Tim', 'ketua'));
        $data['berkas']->update(['drive_file_id' => 'drive-123']);

        $this->app->instance(GoogleDriveService::class, Mockery::mock(GoogleDriveService::class, function ($mock) {
            $mock->shouldReceive('renameFile')->once()->with('drive-123', 'Laporan baru.pdf');
        }));

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('gantiNamaBerkas', $data['berkas']->id, 'Laporan baru');

        $this->assertSame('Laporan baru.pdf', $data['berkas']->fresh()->nama_file);
    }

    public function test_berkas_baru_menampilkan_nama_default_dan_nama_kustom_dipakai_saat_disimpan(): void
    {
        $this->actingAs($this->buatUser('Ketua Tim', 'ketua'));
        $iku = MasterIku::create(['kode' => 'UJI-001', 'indikator' => 'Indikator uji coba', 'tim' => 'Uji']);

        $komponen = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Rapat koordinasi')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('scan001.pdf', 100, 'application/pdf')])
            ->assertSee('Kegiatan Rapat koordinasi.pdf');

        $kunci = $komponen->instance()->kunciBuktiBaru($komponen->get('blocks.0.bukti')[0]);

        $komponen->call('aturNamaBuktiBaru', $kunci, 'Undangan rapat.pdf')
            ->assertSee('Undangan rapat.pdf')
            ->set('kendalaBlocks.0.kendala', 'Kendala uji')
            ->set('rtlBaru.0.rtl_teks', 'RTL uji')
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $this->assertSame('Undangan rapat.pdf', Berkas::first()->nama_file);
    }

    public function test_pengguna_lain_tidak_bisa_ganti_nama_berkas_orang_lain(): void
    {
        $data = $this->siapkanBerkas($this->buatUser('Ketua Tim', 'ketua'));
        $lain = $this->buatUser('Ketua Tim', 'ketua-lain');

        $this->assertFalse($data['berkas']->bisaDiubahNamaOleh($lain));
        $this->assertTrue($data['berkas']->bisaDiubahNamaOleh(User::where('username', 'ketua')->first()));
    }
}
