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
            ->call('tambahSubfolder', 0, 'Undangan', 'persiapan')
            ->set('subfolderBuktiBaru.'.$komponen->kunciBuktiBaru($undangan), 'Undangan')
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $persiapan = Kegiatan::where('tahapan_survei', 'persiapan')->firstOrFail();
        $pelaksanaan = Kegiatan::where('tahapan_survei', 'pelaksanaan')->firstOrFail();

        $this->assertSame(2, Kegiatan::count());
        $this->assertSame('Persiapan Survei Sakernas', $persiapan->nama_folder_auto);
        $this->assertSame('Pelaksanaan Survei Sakernas', $pelaksanaan->nama_folder_auto);
        // Folder tahapan boleh bernama tahapan, tapi identitas kegiatan tetap uraiannya.
        $this->assertSame('Survei Sakernas', $persiapan->uraianTampil());
        $this->assertSame('Survei Sakernas', $pelaksanaan->namaCapaian());
        $this->assertSame('Persiapan', $persiapan->labelTahapan());

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

        $unggahan = [];
        $this->mock(FolderStructureService::class, function ($mock) use (&$unggahan) {
            $mock->shouldReceive('unggahBerkasKegiatan')->andReturnUsing(function (Kegiatan $kegiatan, $kategori, $path, $ekstensi = '.pdf', $mime = 'application/pdf', $namaBerkasOverride = null, $subfolder = null) use (&$unggahan) {
                $unggahan[] = [$kegiatan->tahapan_survei, $subfolder];

                return ['drive_file_id' => 'x', 'storage_account_id' => null, 'nama_file' => 'x.pdf'];
            });
        });

        $test = $this->formSurvei($iku)
            ->call('toggleTahapan', 0, 'persiapan')
            ->call('tambahSubfolder', 0, 'Surat Tugas', 'persiapan')
            // Tahapan ke-1 (Persiapan), folder ke-1 (subfolder "Surat Tugas") — langsung
            // tersimpan ke Drive tanpa klik Simpan Draft.
            ->set('unggahFolder.0.0.1', [UploadedFile::fake()->create('st.pdf', 10, 'application/pdf')])
            ->assertHasNoErrors();

        $this->assertSame([['persiapan', 'Surat Tugas']], $unggahan);
        $this->assertSame([], $test->get('unggahFolder'));
        $this->assertSame([], $test->get('blocks.0.bukti'));
        $this->assertCount(1, $test->get('blocks.0.existing_bukti'));
        $this->assertSame(Kegiatan::STATUS_DRAFT, Kegiatan::sole()->status_dokumen);
    }

    public function test_unggahan_tertahan_sampai_uraian_kegiatan_diisi(): void
    {
        $iku = $this->siapkan();

        $this->mock(FolderStructureService::class, function ($mock) {
            $mock->shouldReceive('unggahBerkasKegiatan')->once()->andReturn(['drive_file_id' => 'x', 'storage_account_id' => null, 'nama_file' => 'x.pdf']);
        });

        $test = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('unggahFolder.0.0.0', [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
            ->assertHasErrors('blocks.0.uraian_kegiatan');

        $this->assertCount(1, $test->get('blocks.0.bukti'));
        $this->assertSame(0, Berkas::count());

        $test->set('blocks.0.uraian_kegiatan', 'Rapat koordinasi');

        $this->assertSame(1, Berkas::count());
        $this->assertSame([], $test->get('blocks.0.bukti'));
    }

    public function test_simpan_otomatis_tidak_membuang_blok_yang_belum_diisi(): void
    {
        $iku = $this->siapkan();

        $this->mock(FolderStructureService::class, function ($mock) {
            $mock->shouldReceive('unggahBerkasKegiatan')->andReturn(['drive_file_id' => 'x', 'storage_account_id' => null, 'nama_file' => 'x.pdf']);
        });

        $test = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->call('addBlock')
            ->set('blocks.1.jenis', 'bukan_survei_sensus')
            // Blok 1 menunggu uraian — berkasnya tertahan.
            ->set('unggahFolder.1.0.0', [UploadedFile::fake()->create('tertahan.pdf', 10, 'application/pdf')])
            ->set('blocks.0.uraian_kegiatan', 'Rapat koordinasi')
            ->set('unggahFolder.0.0.0', [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);

        $this->assertSame(1, Kegiatan::count());
        $this->assertCount(2, $test->get('blocks'));
        $this->assertSame('', $test->get('blocks.1.uraian_kegiatan'));
        $this->assertCount(1, $test->get('blocks.1.bukti'));
        $this->assertTrue($test->get('adaPerubahan'));
    }

    public function test_susunan_subfolder_diatur_terpisah_per_tahapan(): void
    {
        $iku = $this->siapkan();

        $test = $this->formSurvei($iku)
            ->call('toggleTahapan', 0, 'persiapan')
            ->call('toggleTahapan', 0, 'pengolahan')
            ->call('tambahSubfolder', 0, 'Undangan', 'persiapan')
            ->call('tambahSubfolder', 0, 'Notula', 'persiapan')
            ->call('tambahSubfolder', 0, 'Hasil Validasi', 'pengolahan')
            ->call('aturSusunanFolder', 0, false, 'pengolahan');

        $komponen = $test->instance();
        $block = $komponen->blocks[0];

        $this->assertSame(['Undangan', 'Notula'], $komponen->subfolderBlok($block, 'persiapan'));
        // Pengolahan kembali ke "1 folder" — daftarnya disimpan tapi tidak dipakai.
        $this->assertSame([], $komponen->subfolderBlok($block, 'pengolahan'));
        $this->assertSame(['Hasil Validasi'], $komponen->daftarSubfolder($block, 'pengolahan'));

        $grup = $komponen->folderUnggahBlok(0);
        $this->assertCount(3, $grup[0]['folder']);
        $this->assertCount(1, $grup[1]['folder']);
    }

    public function test_tahapan_buatan_sendiri_bisa_ditambah_dan_disimpan(): void
    {
        $iku = $this->siapkan();

        $this->mock(FolderStructureService::class, function ($mock) {
            $mock->shouldReceive('unggahBerkasKegiatan')->andReturn(['drive_file_id' => 'x', 'storage_account_id' => null, 'nama_file' => 'x.pdf']);
        });

        $test = $this->formSurvei($iku)
            ->call('toggleTahapan', 0, 'persiapan')
            ->call('tambahTahapan', 0, 'Pelatihan Petugas')
            // Nama yang sama dengan tahapan bawaan cukup mencentang tahapan bawaan itu.
            ->call('tambahTahapan', 0, 'pelaksanaan')
            ->assertSet('blocks.0.tahapan_kustom', ['Pelatihan Petugas'])
            ->assertSet('blocks.0.tahapan_tambahan', ['Pelatihan Petugas', 'pelaksanaan'])
            ->call('tambahSubfolder', 0, 'Daftar Hadir', 'Pelatihan Petugas')
            // Unggahan pertama langsung menyimpan draf — tiap tahapan jadi blok sendiri
            // (0 Persiapan, 1 Pelatihan Petugas, 2 Pelaksanaan), subfolder yang belum
            // berisi berkas tetap terbawa.
            ->set('unggahFolder.0.0.0', [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
            ->assertSet('blocks.1.tahapan_survei', 'Pelatihan Petugas')
            ->assertSet('blocks.1.subfolders_tahapan', ['Pelatihan Petugas' => ['Daftar Hadir']])
            ->set('unggahFolder.1.0.1', [UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')])
            ->set('unggahFolder.2.0.0', [UploadedFile::fake()->create('c.pdf', 10, 'application/pdf')])
            ->assertHasNoErrors();

        $pelatihan = Kegiatan::where('tahapan_survei', 'Pelatihan Petugas')->firstOrFail();
        $this->assertSame('Pelatihan Petugas Survei Sakernas', $pelatihan->nama_folder_auto);
        $this->assertSame('Daftar Hadir', Berkas::where('ref_id', $pelatihan->id)->sole()->subfolder);
        $this->assertSame(3, Kegiatan::count());

        // Saat dimuat ulang, tahapan buatan sendiri tetap tampil sebagai pilihan.
        $this->formSurvei($iku)
            ->assertSet('blocks.1.tahapan_survei', 'Pelatihan Petugas')
            ->assertSet('blocks.1.tahapan_kustom', ['Pelatihan Petugas'])
            ->assertSet('blocks.1.subfolders_tahapan', ['Pelatihan Petugas' => ['Daftar Hadir']]);
    }

    public function test_tahapan_buatan_sendiri_bisa_dihapus(): void
    {
        $iku = $this->siapkan();

        $this->formSurvei($iku)
            ->call('tambahTahapan', 0, 'Evaluasi')
            ->assertSet('blocks.0.tahapan_survei', 'Evaluasi')
            ->call('hapusTahapanKustom', 0, 'Evaluasi')
            ->assertSet('blocks.0.tahapan_survei', '')
            ->assertSet('blocks.0.tahapan_kustom', []);
    }

    public function test_nama_folder_tahapan_tambahan_dan_subfolder_bisa_diganti(): void
    {
        $iku = $this->siapkan();

        $this->mock(FolderStructureService::class, function ($mock) {
            $mock->shouldReceive('unggahBerkasKegiatan')->andReturn(['drive_file_id' => 'x', 'storage_account_id' => null, 'nama_file' => 'x.pdf']);
        });

        $test = $this->formSurvei($iku)
            ->call('toggleTahapan', 0, 'persiapan')
            ->call('toggleTahapan', 0, 'pelaksanaan')
            ->call('tambahSubfolder', 0, 'Undangan', 'persiapan')
            ->call('gantiNamaSubfolder', 0, 0, 'Surat Undangan', 'persiapan')
            ->assertSet('blocks.0.subfolders_tahapan.persiapan', ['Surat Undangan'])
            ->set('blocks.0.nama_folder_tahapan.pelaksanaan', 'Lapangan Sakernas')
            ->set('blocks.0.bukti', [
                UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            ])
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $pelaksanaan = Kegiatan::where('tahapan_survei', 'pelaksanaan')->firstOrFail();
        $this->assertSame('Lapangan Sakernas', $pelaksanaan->namaFolder());
        // Mengganti nama folder tahapan tidak mengganti nama kegiatan.
        $this->assertSame('Survei Sakernas', $pelaksanaan->uraianTampil());
        $this->assertSame('Survei Sakernas', Kegiatan::where('tahapan_survei', 'persiapan')->firstOrFail()->uraianTampil());
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
            'nama_folder_auto' => 'Persiapan Survei Sakernas', 'status_dokumen' => Kegiatan::STATUS_DRAFT,
        ]);

        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldReceive('findOrCreateFolder')->once()->with('Survei Sakernas', 'folder-capaian')->andReturn('folder-induk');
        $drive->shouldReceive('namesInFolder')->once()->with('folder-induk')->andReturn([]);
        $drive->shouldReceive('createFolder')->once()->with('Persiapan Survei Sakernas', 'folder-induk')->andReturn('folder-persiapan');

        $hasil = app(FolderStructureService::class)->resolveKegiatanFolder($kegiatan, 'folder-capaian');

        $this->assertSame('folder-persiapan', $hasil);
        $this->assertSame('folder-persiapan', $kegiatan->fresh()->drive_folder_id);
    }

    public function test_kegiatan_sama_bulan_lain_triwulan_ini_memakai_folder_yang_sama(): void
    {
        $iku = $this->siapkan();
        $juli = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $september = Periode::create(['tahun' => 2026, 'bulan' => 9, 'triwulan' => 3, 'bulan_ke' => 3, 'flag_bulan_terlewat' => false]);
        $oktober = Periode::create(['tahun' => 2026, 'bulan' => 10, 'triwulan' => 4, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        $dasar = ['iku_id' => $iku->id, 'jenis' => 'bukan_survei_sensus', 'nama_folder_auto' => 'Menyusun Buklet', 'status_dokumen' => Kegiatan::STATUS_DRAFT];
        $kegiatanJuli = Kegiatan::create($dasar + ['periode_id' => $juli->id, 'uraian_kegiatan' => 'Menyusun Buklet', 'drive_folder_id' => 'folder-buklet', 'nama_folder_kustom' => 'Buklet Kemiskinan']);
        $kegiatanSeptember = Kegiatan::create($dasar + ['periode_id' => $september->id, 'uraian_kegiatan' => ' menyusun buklet ']);
        $kegiatanLain = Kegiatan::create(['periode_id' => $september->id, 'uraian_kegiatan' => 'Rapat lain', 'nama_folder_auto' => 'Rapat lain'] + $dasar);
        $kegiatanTwBerikutnya = Kegiatan::create($dasar + ['periode_id' => $oktober->id, 'uraian_kegiatan' => 'Menyusun Buklet']);

        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldReceive('namesInFolder')->andReturn([]);
        $drive->shouldReceive('createFolder')->once()->with('Rapat lain', 'folder-capaian')->andReturn('folder-rapat');
        $drive->shouldReceive('createFolder')->once()->with('Menyusun Buklet', 'folder-capaian-tw4')->andReturn('folder-buklet-tw4');
        $layanan = app(FolderStructureService::class);

        // Bulan lain, kegiatan sama → folder Juli dipakai lagi, nama folder ikut.
        $this->assertSame('folder-buklet', $layanan->resolveKegiatanFolder($kegiatanSeptember, 'folder-capaian'));
        $this->assertSame('Buklet Kemiskinan', $kegiatanSeptember->fresh()->nama_folder_kustom);

        // Kegiatan berbeda & triwulan berbeda tetap dibuatkan folder sendiri.
        $this->assertSame('folder-rapat', $layanan->resolveKegiatanFolder($kegiatanLain, 'folder-capaian'));
        $this->assertSame('folder-buklet-tw4', $layanan->resolveKegiatanFolder($kegiatanTwBerikutnya, 'folder-capaian-tw4'));

        // Ganti nama dari salah satu bulan → bulan lain yang berbagi folder ikut.
        $drive->shouldReceive('renameFile')->once()->with('folder-buklet', 'Buklet Kemiskinan 2026');
        $kegiatanSeptember->fresh()->gantiNamaFolder('Buklet Kemiskinan 2026');
        $this->assertSame('Buklet Kemiskinan 2026', $kegiatanJuli->fresh()->namaFolder());
    }

    public function test_folder_kegiatan_tidak_digabung_bila_dipecah_per_bulan_lebih_dulu(): void
    {
        $iku = $this->siapkan();
        $juli = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $agustus = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        $dasar = ['iku_id' => $iku->id, 'jenis' => 'bukan_survei_sensus', 'uraian_kegiatan' => 'Menyusun Buklet', 'nama_folder_auto' => 'Menyusun Buklet', 'status_dokumen' => Kegiatan::STATUS_DRAFT];
        Kegiatan::create($dasar + ['periode_id' => $juli->id, 'drive_folder_id' => 'folder-juli']);
        $kegiatanAgustus = Kegiatan::create($dasar + ['periode_id' => $agustus->id]);

        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldReceive('namesInFolder')->andReturn([]);
        $drive->shouldReceive('createFolder')->once()->with('Menyusun Buklet', 'folder-agustus')->andReturn('folder-buklet-agustus');

        $this->assertSame('folder-buklet-agustus', app(FolderStructureService::class)->resolveKegiatanFolder($kegiatanAgustus, 'folder-agustus', gabungBulanLain: false));
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
