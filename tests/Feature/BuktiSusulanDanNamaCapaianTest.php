<?php

namespace Tests\Feature;

use App\Livewire\PengisianKegiatan;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Services\FolderStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class BuktiSusulanDanNamaCapaianTest extends TestCase
{
    use RefreshDatabase;

    protected MasterIku $iku;

    protected Periode $periode;

    protected function setUp(): void
    {
        parent::setUp();

        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'username' => 'ketua-susulan@example.test', 'email' => 'ketua-susulan@example.test',
            'password' => 'password',
            'role_id' => Role::create(['nama' => 'Ketua Tim'])->id,
            'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($ketua);

        $this->iku = MasterIku::create(['kode' => 'UJI-SUS', 'indikator' => 'Indikator uji', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $this->periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
    }

    protected function kegiatan(array $atribut = []): Kegiatan
    {
        return Kegiatan::create($atribut + [
            'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id,
            'uraian_kegiatan' => 'Survei Sakernas', 'jenis' => 'bukan_survei_sensus',
            'nama_folder_auto' => 'Survei Sakernas', 'status_dokumen' => Kegiatan::STATUS_DRAFT,
        ]);
    }

    protected function form()
    {
        return Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $this->iku->id);
    }

    public function test_nama_capaian_notula_pakai_nama_folder_tahapan_atau_uraian_kegiatan(): void
    {
        $tahapan = $this->kegiatan(['jenis' => 'survei_sensus', 'tahapan_survei' => 'persiapan', 'nama_folder_auto' => 'Persiapan Survei Sakernas']);
        $satuFolder = $this->kegiatan(['uraian_kegiatan' => 'Rapat koordinasi', 'nama_folder_auto' => 'Rapat koordinasi', 'nama_folder_kustom' => 'Folder Rapat']);

        $this->assertSame('Persiapan Survei Sakernas', $tahapan->namaCapaian());
        $this->assertSame('Rapat koordinasi', $satuFolder->namaCapaian());
    }

    public function test_kegiatan_terverifikasi_bisa_ditambah_bukti_dukung_baru(): void
    {
        $kegiatan = $this->kegiatan(['status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);
        Capaian::create(['iku_id' => $this->iku->id, 'periode_id' => $this->periode->id, 'status' => Capaian::STATUS_DIVERIFIKASI]);

        $this->mock(FolderStructureService::class, function ($mock) {
            $mock->shouldReceive('unggahBerkasKegiatan')->once()
                ->withArgs(fn ($k, $kategori, $path, $ekstensi = '.pdf', $mime = 'application/pdf', $nama = null) => $nama === 'Daftar hadir tambahan')
                ->andReturn(['drive_file_id' => 'drive-1', 'storage_account_id' => null, 'nama_file' => 'Daftar hadir tambahan.pdf']);
            $mock->shouldReceive('linkBuktiDukungIku')->andReturn('https://drive.google.com/drive/folders/iku-uji');
        });

        $this->form()
            ->set("buktiSusulan.kegiatan-{$kegiatan->id}", [UploadedFile::fake()->create('Daftar hadir tambahan.pdf', 10, 'application/pdf')])
            ->assertHasNoErrors()
            ->assertSee('Daftar hadir tambahan.pdf')
            ->assertSee('https://drive.google.com/drive/folders/iku-uji');

        $berkas = Berkas::where('ref_id', $kegiatan->id)->sole();
        $this->assertSame('menunggu', $berkas->status_verifikasi);
        $this->assertSame(Kegiatan::STATUS_DIVERIFIKASI, $kegiatan->fresh()->status_dokumen);
    }

    public function test_bukti_susulan_ditolak_untuk_kegiatan_yang_belum_diverifikasi(): void
    {
        $kegiatan = $this->kegiatan();

        $this->form()->set("buktiSusulan.kegiatan-{$kegiatan->id}", [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);

        $this->assertSame(0, Berkas::count());
    }

    public function test_berkas_yang_gagal_ke_drive_diberi_peringatan_dan_alasan(): void
    {
        $kegiatan = $this->kegiatan();
        Berkas::create([
            'ref_id' => $kegiatan->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian',
            'nama_file' => 'undangan.pdf', 'path' => 'bukti-capaian/undangan.pdf', 'status_verifikasi' => 'menunggu',
        ]);

        $this->form()->assertSee('Belum berhasil diunggah ke Google Drive');

        $this->assertStringContainsString('belum disambungkan', PengisianKegiatan::alasanGagalDrive('Belum ada storage aktif. Tambahkan & aktifkan akun di menu Akun & Storage terlebih dahulu.'));
    }
}
