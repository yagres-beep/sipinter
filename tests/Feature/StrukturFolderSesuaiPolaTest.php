<?php

namespace Tests\Feature;

use App\Livewire\RtlEvaluasi as RtlEvaluasiComponent;
use App\Models\FolderConfig;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\RtlEvaluasi;
use App\Models\StorageAccount;
use App\Models\User;
use App\Services\FolderStructureService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Unggahan mengikuti pola folder yang disimpan Tim SAKIP di menu Struktur Folder
 * untuk SEMUA kategori (bukan hanya Capaian), dan tidak lagi dibatasi ukurannya.
 */
class StrukturFolderSesuaiPolaTest extends TestCase
{
    use RefreshDatabase;

    protected function poinRtl(): RtlEvaluasi
    {
        StorageAccount::create(['email_gmail_institusi' => 'aktif@example.test', 'status' => StorageAccount::STATUS_AKTIF, 'drive_folder_id' => 'root']);

        $iku = MasterIku::create(['kode' => '1131', 'indikator' => 'Indikator uji', 'tim' => 'Uji', 'penanggung_jawab' => 'PJ']);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        return RtlEvaluasi::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'rtl_teks' => 'Rapat koordinasi/evaluasi', 'pic' => 'PIC', 'batas_waktu' => '2026-09-30']);
    }

    protected function aturKategori(string $nama, array $opsi): void
    {
        $config = FolderConfig::current();
        $pola = $config->pola_json;
        foreach ($pola['kategori'] as $i => $k) {
            if ($k['nama'] === $nama) {
                $pola['kategori'][$i] = array_merge($k, $opsi);
            }
        }
        $config->update(['pola_json' => $pola]);
    }

    protected function driveMenuju(string $folderTujuan): void
    {
        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldReceive('findOrCreateFolder')->andReturnUsing(fn ($nama, $induk) => "{$induk}/{$nama}");
        $drive->shouldReceive('foldersInFolder')->andReturn([]);
        $drive->shouldReceive('createFolder')->andReturnUsing(fn ($nama, $induk) => "{$induk}/{$nama}");
        $drive->shouldReceive('namesInFolder')->andReturn([]);
        $drive->shouldReceive('uploadFile')->once()
            ->withArgs(fn ($lokal, $nama, $folder) => $folder === $folderTujuan)
            ->andReturn(['id' => 'f1', 'name' => 'x.pdf', 'web_view_link' => null, 'size_bytes' => 10]);
    }

    public function test_bukti_evaluasi_rtl_masuk_subfolder_bila_tim_sakip_mengaktifkan_subfolder_per_kegiatan(): void
    {
        $poin = $this->poinRtl();
        $this->aturKategori('Evaluasi-RTL', ['subfolder_per_kegiatan' => true, 'subfolder_per_bulan' => false]);

        $this->driveMenuju('root/2026/Triwulan III/1131. Indikator uji/Evaluasi-RTL/Rapat koordinasi evaluasi');

        app(FolderStructureService::class)->unggahBerkasRtl($poin, __FILE__);
    }

    public function test_bukti_evaluasi_rtl_langsung_di_kategori_bila_subfolder_per_kegiatan_mati(): void
    {
        $poin = $this->poinRtl();
        $this->aturKategori('Evaluasi-RTL', ['subfolder_per_kegiatan' => false, 'subfolder_per_bulan' => false]);

        $this->driveMenuju('root/2026/Triwulan III/1131. Indikator uji/Evaluasi-RTL');

        app(FolderStructureService::class)->unggahBerkasRtl($poin, __FILE__);
    }

    public function test_urutan_kegiatan_dulu_menaruh_folder_bulan_di_dalam_folder_item(): void
    {
        $poin = $this->poinRtl();
        $this->aturKategori('Evaluasi-RTL', ['subfolder_per_kegiatan' => true, 'subfolder_per_bulan' => true, 'urutan_bulan_kegiatan' => 'kegiatan_dulu']);

        $this->driveMenuju('root/2026/Triwulan III/1131. Indikator uji/Evaluasi-RTL/Rapat koordinasi evaluasi/Agustus');

        app(FolderStructureService::class)->unggahBerkasRtl($poin, __FILE__);
    }

    public function test_bukti_berukuran_besar_tidak_ditolak(): void
    {
        $poin = $this->poinRtl();
        $this->actingAs(User::create([
            'nama' => 'Ketua', 'username' => 'ketua-besar', 'email' => 'ketua-besar@example.test', 'password' => 'password',
            'role_id' => Role::create(['nama' => 'Ketua Tim'])->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $this->mock(FolderStructureService::class)->shouldReceive('unggahBerkasRtl')->once()
            ->andReturn(['drive_file_id' => 'f1', 'storage_account_id' => null, 'nama_file' => 'besar.pdf']);

        Livewire::test(RtlEvaluasiComponent::class)
            ->set("evaluasi.{$poin->id}.realisasi", 'Sudah dilaksanakan')
            ->set("evaluasi.{$poin->id}.status_cocok", 'cocok')
            ->set("evaluasi.{$poin->id}.bukti", [UploadedFile::fake()->create('besar.pdf', 50 * 1024, 'application/pdf')])
            ->call('simpanEvaluasi', $poin->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('berkas', ['ref_id' => $poin->id, 'drive_file_id' => 'f1']);
    }
}
