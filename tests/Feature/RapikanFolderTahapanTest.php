<?php

namespace Tests\Feature;

use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Folder tahapan kegiatan cukup bernama tahapannya (tanpa nama kegiatan), termasuk
 * data lama lewat perintah kegiatan:rapikan-folder-tahapan.
 */
class RapikanFolderTahapanTest extends TestCase
{
    use RefreshDatabase;

    protected MasterIku $iku;

    protected Periode $periode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->iku = MasterIku::create(['kode' => 'UJI-RFT', 'indikator' => 'Indikator uji', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $this->periode = Periode::create(['tahun' => 2026, 'bulan' => 9, 'triwulan' => 3, 'bulan_ke' => 3, 'flag_bulan_terlewat' => false]);
    }

    protected function kegiatan(array $atribut): Kegiatan
    {
        return Kegiatan::create($atribut + [
            'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id,
            'uraian_kegiatan' => 'Survei Updating Direktori', 'jenis' => 'survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);
    }

    public function test_nama_folder_tahapan_cukup_nama_tahapan(): void
    {
        $tahapan = $this->kegiatan(['tahapan_survei' => 'persiapan', 'nama_folder_auto' => 'Persiapan Survei Updating Direktori']);
        $kustom = $this->kegiatan(['tahapan_survei' => 'pelaksanaan', 'nama_folder_auto' => 'Pelaksanaan Survei Updating Direktori', 'nama_folder_kustom' => 'Lapangan']);
        $satuFolder = $this->kegiatan(['jenis' => 'bukan_survei_sensus', 'uraian_kegiatan' => 'Rapat koordinasi', 'nama_folder_auto' => 'Rapat koordinasi']);

        $this->assertSame('Persiapan', $tahapan->namaFolder());
        $this->assertSame('Lapangan', $kustom->namaFolder());
        $this->assertSame('Rapat koordinasi', $satuFolder->namaFolder());
        // Jejak uraian asal tetap terbaca dari nama_folder_auto.
        $this->assertSame('survei updating direktori', $tahapan->uraianAsal());
    }

    public function test_data_lama_dirapikan_di_aplikasi_dan_drive(): void
    {
        // Nama otomatis lama yang tersimpan sebagai "kustom" (format berkurung siku & biasa).
        $persiapan = $this->kegiatan(['tahapan_survei' => 'persiapan', 'nama_folder_auto' => '[Persiapan] Survei Updating Direktori', 'nama_folder_kustom' => 'Persiapan Survei Updating Direktori', 'drive_folder_id' => 'folder-persiapan']);
        // Bulan lain, folder Drive yang sama.
        $persiapanAgustus = $this->kegiatan(['tahapan_survei' => 'persiapan', 'nama_folder_auto' => 'Persiapan Survei Updating Direktori', 'drive_folder_id' => 'folder-persiapan']);
        $pelaksanaan = $this->kegiatan(['tahapan_survei' => 'pelaksanaan', 'nama_folder_auto' => 'Pelaksanaan Survei Updating Direktori', 'drive_folder_id' => 'folder-pelaksanaan']);
        // Nama pilihan pengguna tetap.
        $pengolahan = $this->kegiatan(['tahapan_survei' => 'pengolahan', 'nama_folder_auto' => 'Pengolahan Survei Updating Direktori', 'nama_folder_kustom' => 'Olah Data', 'drive_folder_id' => 'folder-pengolahan']);
        // Diganti sendiri langsung di Drive: tidak disentuh.
        $this->kegiatan(['tahapan_survei' => 'diseminasi', 'nama_folder_auto' => 'Diseminasi Survei Updating Direktori', 'drive_folder_id' => 'folder-diseminasi']);
        // Bukan kegiatan bertahapan: tidak disentuh.
        $this->kegiatan(['jenis' => 'bukan_survei_sensus', 'uraian_kegiatan' => 'Rapat koordinasi', 'nama_folder_auto' => 'Rapat koordinasi', 'drive_folder_id' => 'folder-rapat']);

        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldReceive('namaFile')->with('folder-persiapan')->once()->andReturn('[Persiapan] Survei Updating Direktori');
        $drive->shouldReceive('namaFile')->with('folder-pelaksanaan')->once()->andReturn('Pelaksanaan Survei Updating Direktori (2)');
        $drive->shouldReceive('namaFile')->with('folder-pengolahan')->once()->andReturn('Olah Data');
        $drive->shouldReceive('namaFile')->with('folder-diseminasi')->once()->andReturn('Publikasi');
        $drive->shouldReceive('renameFile')->with('folder-persiapan', 'Persiapan')->once();
        $drive->shouldReceive('renameFile')->with('folder-pelaksanaan', 'Pelaksanaan')->once();
        $drive->shouldNotReceive('renameFile')->with('folder-pengolahan', \Mockery::any());
        $drive->shouldNotReceive('renameFile')->with('folder-diseminasi', \Mockery::any());

        $this->artisan('kegiatan:rapikan-folder-tahapan')->assertSuccessful();

        $this->assertNull($persiapan->fresh()->nama_folder_kustom);
        $this->assertSame('Persiapan', $persiapan->fresh()->namaFolder());
        $this->assertSame('Persiapan', $persiapanAgustus->fresh()->namaFolder());
        $this->assertSame('Pelaksanaan', $pelaksanaan->fresh()->namaFolder());
        $this->assertSame('Olah Data', $pengolahan->fresh()->namaFolder());
        // nama_folder_auto (jejak uraian asal) tidak diubah.
        $this->assertSame('[Persiapan] Survei Updating Direktori', $persiapan->fresh()->nama_folder_auto);
    }

    public function test_tanpa_drive_hanya_merapikan_data_aplikasi(): void
    {
        $k = $this->kegiatan(['tahapan_survei' => 'persiapan', 'nama_folder_auto' => 'Persiapan Survei Updating Direktori', 'nama_folder_kustom' => 'Persiapan Survei Updating Direktori', 'drive_folder_id' => 'folder-persiapan']);
        $this->mock(GoogleDriveService::class)->shouldNotReceive('namaFile');

        $this->artisan('kegiatan:rapikan-folder-tahapan', ['--tanpa-drive' => true])->assertSuccessful();

        $this->assertNull($k->fresh()->nama_folder_kustom);
    }
}
