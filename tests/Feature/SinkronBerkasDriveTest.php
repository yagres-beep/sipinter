<?php

namespace Tests\Feature;

use App\Livewire\PengisianKegiatan;
use App\Models\Berkas;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Services\FolderStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class SinkronBerkasDriveTest extends TestCase
{
    use RefreshDatabase;

    protected User $ketua;

    protected MasterIku $iku;

    protected Kegiatan $kegiatan;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $this->ketua = User::create([
            'nama' => 'Ketua Uji Sinkron', 'username' => 'ketua-sinkron@example.test', 'email' => 'ketua-sinkron@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $this->iku = MasterIku::create([
            'kode' => 'UJI-SNK', 'indikator' => 'Indikator uji sinkron', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        $this->kegiatan = Kegiatan::create([
            'iku_id' => $this->iku->id, 'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Rapat koordinasi', 'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DRAFT,
        ]);
    }

    protected function berkasBelumDiDrive(string $nama, bool $adaLokal = true, ?int $pengunggah = null): Berkas
    {
        $berkas = Berkas::create([
            'ref_id' => $this->kegiatan->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian',
            'nama_file' => "{$nama}.pdf", 'subfolder' => 'Undangan', 'path' => "bukti-capaian/{$nama}.pdf",
            'status_verifikasi' => 'menunggu', 'diunggah_oleh' => $pengunggah,
        ]);

        if ($adaLokal) {
            Storage::disk('local')->put($berkas->path, '%PDF-1.4 isi');
        }

        return $berkas;
    }

    public function test_perintah_menyusulkan_berkas_yang_belum_di_drive_ke_folder_asalnya(): void
    {
        $berkas = $this->berkasBelumDiDrive('Kegiatan Rapat koordinasi');
        $hilang = $this->berkasBelumDiDrive('Sudah terhapus', adaLokal: false);

        $this->mock(FolderStructureService::class, function ($mock) {
            $mock->shouldReceive('unggahBerkasKegiatan')->once()
                ->withArgs(fn ($kegiatan, $kategori, $path, $ekstensi = '.pdf', $mime = 'application/pdf', $nama = null, $subfolder = null) => $kegiatan->is($this->kegiatan) && $kategori === 'capaian' && $nama === 'Kegiatan Rapat koordinasi' && $subfolder === 'Undangan')
                ->andReturn(['drive_file_id' => 'drive-baru', 'storage_account_id' => null, 'nama_file' => 'Kegiatan Rapat koordinasi.pdf']);
        });

        $this->artisan('berkas:sinkron-drive')->assertSuccessful();

        $this->assertSame('drive-baru', $berkas->fresh()->drive_file_id);
        $this->assertNull($hilang->fresh()->drive_file_id);
    }

    public function test_perintah_berhenti_setelah_beberapa_kegagalan_beruntun(): void
    {
        foreach (range(1, 5) as $i) {
            $this->berkasBelumDiDrive("bukti-{$i}");
        }

        $this->mock(FolderStructureService::class, function ($mock) {
            $mock->shouldReceive('unggahBerkasKegiatan')->times(3)->andThrow(new RuntimeException('Drive mati'));
        });

        $this->artisan('berkas:sinkron-drive')->assertSuccessful();

        $this->assertSame(5, Berkas::belumDiDrive()->count());
    }

    public function test_simpan_sementara_ikut_menyusulkan_berkas_lama_yang_gagal_ke_drive(): void
    {
        $milikSendiri = $this->berkasBelumDiDrive('punya-ketua', pengunggah: $this->ketua->id);
        $milikOrangLain = $this->berkasBelumDiDrive('punya-orang-lain');

        $this->mock(FolderStructureService::class, function ($mock) {
            $mock->shouldReceive('unggahBerkasKegiatan')->once()
                ->andReturn(['drive_file_id' => 'drive-susulan', 'storage_account_id' => null, 'nama_file' => 'punya-ketua.pdf']);
        });

        $this->actingAs($this->ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $this->iku->id)
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $this->assertSame('drive-susulan', $milikSendiri->fresh()->drive_file_id);
        $this->assertNull($milikOrangLain->fresh()->drive_file_id);
    }
}
