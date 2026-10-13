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
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class TahapanGandaDanSubfolderTest extends TestCase
{
    use RefreshDatabase;

    protected function siapkan(): MasterIku
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'username' => 'ketua-tahapan@example.test', 'email' => 'ketua-tahapan@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $this->actingAs($ketua);

        return MasterIku::create([
            'kode' => 'UJI-THP',
            'indikator' => 'Indikator uji tahapan',
            'tim' => 'Uji',
            'penanggung_jawab' => 'Ketua Uji',
        ]);
    }

    /**
     * @return Testable
     */
    protected function formSurvei(MasterIku $iku)
    {
        return Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Survei Sakernas')
            ->set('blocks.0.jenis', 'survei_sensus');
    }

    public function test_tahapan_bisa_dicentang_lebih_dari_satu_dan_dilepas_lagi(): void
    {
        $iku = $this->siapkan();

        $test = $this->formSurvei($iku)
            ->call('toggleTahapan', 0, 'persiapan')
            ->call('toggleTahapan', 0, 'pelaksanaan')
            ->assertSet('blocks.0.tahapan_survei', 'persiapan')
            ->assertSet('blocks.0.tahapan_tambahan', ['pelaksanaan']);

        // Melepas tahapan utama menjadikan tahapan berikutnya sebagai utama.
        $test->call('toggleTahapan', 0, 'persiapan')
            ->assertSet('blocks.0.tahapan_survei', 'pelaksanaan')
            ->assertSet('blocks.0.tahapan_tambahan', []);
    }

    public function test_beberapa_tahapan_disimpan_sebagai_kegiatan_per_tahapan_dengan_subfolder(): void
    {
        $iku = $this->siapkan();
        $unggahan = [];

        $this->mock(FolderStructureService::class, function ($mock) use (&$unggahan) {
            $mock->shouldReceive('unggahBerkasKegiatan')->andReturnUsing(function (Kegiatan $kegiatan, $kategori, $path, $ekstensi = '.pdf', $mime = 'application/pdf', $namaBerkasOverride = null, $subfolder = null) use (&$unggahan) {
                $unggahan[] = [$kegiatan->tahapan_survei, $namaBerkasOverride, $subfolder];

                return ['drive_file_id' => 'drive-'.count($unggahan), 'storage_account_id' => null, 'nama_file' => $namaBerkasOverride.'.pdf'];
            });
        });

        $test = $this->formSurvei($iku)
            ->call('toggleTahapan', 0, 'persiapan')
            ->call('toggleTahapan', 0, 'pelaksanaan')
            ->set('blocks.0.bukti', [
                UploadedFile::fake()->create('undangan.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('laporan lapangan.pdf', 10, 'application/pdf'),
            ]);

        [$undangan, $laporan] = $test->get('blocks.0.bukti');
        $komponen = $test->instance();

        $test->set('tahapanBuktiBaru.'.$komponen->kunciBuktiBaru($laporan), 'pelaksanaan')
            ->set('blocks.0.pakai_subfolder', true)
            ->call('tambahSubfolder', 0, 'Undangan')
            ->set('subfolderBuktiBaru.'.$komponen->kunciBuktiBaru($undangan), 'Undangan')
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $persiapan = Kegiatan::where('tahapan_survei', 'persiapan')->firstOrFail();
        $pelaksanaan = Kegiatan::where('tahapan_survei', 'pelaksanaan')->firstOrFail();

        $this->assertSame(2, Kegiatan::count());
        $this->assertSame('Persiapan', $persiapan->nama_folder_auto);
        $this->assertSame('Pelaksanaan', $pelaksanaan->nama_folder_auto);
        $this->assertSame('Persiapan Survei Sakernas', $persiapan->uraianTampil());

        $berkasPersiapan = Berkas::where('ref_id', $persiapan->id)->sole();
        $this->assertSame('undangan.pdf', $berkasPersiapan->nama_file);
        $this->assertSame('Undangan', $berkasPersiapan->subfolder);

        $berkasPelaksanaan = Berkas::where('ref_id', $pelaksanaan->id)->sole();
        $this->assertSame('laporan lapangan.pdf', $berkasPelaksanaan->nama_file);
        $this->assertNull($berkasPelaksanaan->subfolder);

        $this->assertEqualsCanonicalizing([
            ['persiapan', 'undangan', 'Undangan'],
            ['pelaksanaan', 'laporan lapangan', null],
        ], $unggahan);
    }

    public function test_tombol_unggah_per_folder_langsung_menentukan_tahapan_dan_subfolder(): void
    {
        $iku = $this->siapkan();

        $test = $this->formSurvei($iku)
            ->call('toggleTahapan', 0, 'persiapan')
            ->call('toggleTahapan', 0, 'pelaksanaan')
            ->call('tambahSubfolder', 0, 'Surat Tugas')
            // Tahapan ke-2 (Pelaksanaan), folder ke-1 (subfolder "Surat Tugas").
            ->set('unggahFolder.0.1.1', [UploadedFile::fake()->create('st.pdf', 10, 'application/pdf')])
            // Tahapan ke-1 (Persiapan), langsung di folder tahapan.
            ->set('unggahFolder.0.0.0', [UploadedFile::fake()->create('rapat.pdf', 10, 'application/pdf')]);

        $komponen = $test->instance();
        [$st, $rapat] = $test->get('blocks.0.bukti');
        $block = $komponen->blocks[0];

        $this->assertSame('pelaksanaan', $komponen->tahapanBerkasBaru($block, $st));
        $this->assertSame('Surat Tugas', $komponen->subfolderBerkasBaru($block, $st));
        $this->assertSame('persiapan', $komponen->tahapanBerkasBaru($block, $rapat));
        $this->assertSame('', $komponen->subfolderBerkasBaru($block, $rapat));
        $this->assertSame([], $test->get('unggahFolder'));
    }

    public function test_tiap_tahapan_wajib_punya_bukti_sebelum_diajukan(): void
    {
        $iku = $this->siapkan();

        $test = $this->formSurvei($iku)
            ->call('toggleTahapan', 0, 'persiapan')
            ->call('toggleTahapan', 0, 'pelaksanaan')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('undangan.pdf', 10, 'application/pdf')]);

        $kekurangan = implode("\n", $test->instance()->kekuranganPengajuan());

        $this->assertStringContainsString('tahapan Pelaksanaan belum diberi bukti', $kekurangan);
        $this->assertStringNotContainsString('tahapan Persiapan belum diberi bukti', $kekurangan);
    }

    public function test_folder_tahapan_survei_dikelompokkan_dalam_folder_induk_kegiatan(): void
    {
        $iku = $this->siapkan();
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        $kegiatan = Kegiatan::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Survei Sakernas', 'jenis' => 'survei_sensus', 'tahapan_survei' => 'persiapan',
            'nama_folder_auto' => 'Persiapan', 'status_dokumen' => Kegiatan::STATUS_DRAFT,
        ]);

        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldReceive('findOrCreateFolder')->once()->with('Survei Sakernas', 'folder-capaian')->andReturn('folder-induk');
        $drive->shouldReceive('namesInFolder')->once()->with('folder-induk')->andReturn([]);
        $drive->shouldReceive('createFolder')->once()->with('Persiapan', 'folder-induk')->andReturn('folder-persiapan');

        $hasil = app(FolderStructureService::class)->resolveKegiatanFolder($kegiatan, 'folder-capaian');

        $this->assertSame('folder-persiapan', $hasil);
        $this->assertSame('folder-persiapan', $kegiatan->fresh()->drive_folder_id);
    }

    public function test_kegiatan_bukan_survei_tetap_langsung_di_folder_kategori(): void
    {
        $iku = $this->siapkan();
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        $kegiatan = Kegiatan::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Rapat koordinasi', 'jenis' => 'bukan_survei_sensus',
            'nama_folder_auto' => 'Rapat koordinasi', 'status_dokumen' => Kegiatan::STATUS_DRAFT,
        ]);

        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldNotReceive('findOrCreateFolder');
        $drive->shouldReceive('namesInFolder')->once()->with('folder-capaian')->andReturn([]);
        $drive->shouldReceive('createFolder')->once()->with('Rapat koordinasi', 'folder-capaian')->andReturn('folder-rapat');

        $this->assertSame('folder-rapat', app(FolderStructureService::class)->resolveKegiatanFolder($kegiatan, 'folder-capaian'));
    }
}
