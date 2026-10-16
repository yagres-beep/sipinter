<?php

namespace Tests\Feature;

use App\Livewire\PengisianKegiatan;
use App\Livewire\RtlEvaluasi as RtlEvaluasiComponent;
use App\Models\Berkas;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\RtlEvaluasi;
use App\Models\User;
use App\Services\FolderStructureService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Zona unggah drag-and-drop, coba lagi per berkas ke Drive (tanpa unggahan dobel),
 * dan pemeriksaan kepemilikan id yang dikirim browser.
 */
class UnggahAmanTest extends TestCase
{
    use RefreshDatabase;

    protected MasterIku $iku;

    protected Periode $periode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'nama' => 'Ketua', 'username' => 'ketua-aman', 'email' => 'ketua-aman@example.test', 'password' => 'password',
            'role_id' => Role::create(['nama' => 'Ketua Tim'])->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $this->iku = MasterIku::create(['kode' => '1131', 'indikator' => 'Indikator uji', 'tim' => 'Uji', 'penanggung_jawab' => 'PJ']);
        $this->periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        Storage::fake('local');
    }

    protected function kegiatan(?MasterIku $iku = null): Kegiatan
    {
        return Kegiatan::create([
            'iku_id' => ($iku ?? $this->iku)->id, 'periode_id' => $this->periode->id, 'uraian_kegiatan' => 'Rapat',
            'jenis' => 'bukan_survei_sensus', 'status_dokumen' => Kegiatan::STATUS_DRAFT,
        ]);
    }

    protected function berkasBelumDiDrive(Kegiatan $kegiatan): Berkas
    {
        Storage::disk('local')->put('bukti-capaian/a.pdf', 'isi');

        return Berkas::create([
            'ref_id' => $kegiatan->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian',
            'nama_file' => 'a.pdf', 'path' => 'bukti-capaian/a.pdf', 'status_verifikasi' => 'menunggu',
        ]);
    }

    protected function form()
    {
        return Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $this->iku->id);
    }

    public function test_halaman_isian_memakai_zona_seret_dan_lepas(): void
    {
        $this->form()
            ->assertSee('data-zona-unggah', false)
            ->assertSee('Seret PDF ke folder ini atau klik untuk memilih', false);
    }

    public function test_coba_lagi_mengunggah_hanya_berkas_itu_dengan_penanda_kode(): void
    {
        $berkas = $this->berkasBelumDiDrive($this->kegiatan());

        $this->mock(GoogleDriveService::class)->shouldReceive('cariBerkasBerpenanda')->once()->andReturn(null);
        $this->mock(FolderStructureService::class)->shouldReceive('unggahBerkasKegiatan')->once()
            ->withArgs(fn ($k, $kategori, $path, $ekstensi = '.pdf', $mime = 'application/pdf', $nama = null, $sub = null, $kode = null) => $kode === $berkas->kode)
            ->andReturn(['drive_file_id' => 'drive-baru', 'storage_account_id' => null, 'nama_file' => 'a.pdf']);

        $this->form()
            ->call('cobaLagiDrive', $berkas->id)
            ->assertDispatched('notify', fn ($n, $p) => $p['type'] === 'success');

        $this->assertSame('drive-baru', $berkas->fresh()->drive_file_id);
    }

    public function test_coba_lagi_tidak_mengunggah_dobel_bila_berkas_sudah_ada_di_drive(): void
    {
        $berkas = $this->berkasBelumDiDrive($this->kegiatan());

        $this->mock(GoogleDriveService::class)->shouldReceive('cariBerkasBerpenanda')->once()
            ->andReturn(['id' => 'drive-lama', 'name' => 'a.pdf', 'web_view_link' => null, 'size_bytes' => 3]);
        $this->mock(FolderStructureService::class)->shouldNotReceive('unggahBerkasKegiatan');

        $this->form()->call('cobaLagiDrive', $berkas->id);

        $this->assertSame('drive-lama', $berkas->fresh()->drive_file_id);
    }

    public function test_coba_lagi_gagal_menampilkan_kegagalan_dan_berkas_tetap_ada(): void
    {
        $berkas = $this->berkasBelumDiDrive($this->kegiatan());

        $this->mock(GoogleDriveService::class)->shouldReceive('cariBerkasBerpenanda')->andReturn(null);
        $this->mock(FolderStructureService::class)->shouldReceive('unggahBerkasKegiatan')->once()->andThrow(new RuntimeException('Drive mati'));

        $this->form()
            ->call('cobaLagiDrive', $berkas->id)
            ->assertDispatched('notify', fn ($n, $p) => $p['type'] === 'error' && str_contains($p['message'], 'BELUM masuk Google Drive'));

        $this->assertNull($berkas->fresh()->drive_file_id);
        Storage::disk('local')->assertExists('bukti-capaian/a.pdf');
    }

    public function test_coba_lagi_untuk_berkas_iku_lain_diabaikan(): void
    {
        $ikuLain = MasterIku::create(['kode' => '9999', 'indikator' => 'Lain', 'tim' => 'Lain', 'penanggung_jawab' => 'PJ']);
        $berkas = $this->berkasBelumDiDrive($this->kegiatan($ikuLain));

        $this->mock(FolderStructureService::class)->shouldNotReceive('unggahBerkasKegiatan');

        $this->form()->call('cobaLagiDrive', $berkas->id);

        $this->assertNull($berkas->fresh()->drive_file_id);
    }

    public function test_hapus_kegiatan_dengan_id_iku_lain_ditolak(): void
    {
        $ikuLain = MasterIku::create(['kode' => '9999', 'indikator' => 'Lain', 'tim' => 'Lain', 'penanggung_jawab' => 'PJ']);
        $milikLain = $this->kegiatan($ikuLain);
        $this->mock(GoogleDriveService::class)->shouldNotReceive('trashFile');

        $this->form()
            ->set('blocks.0.id', $milikLain->id)
            ->call('removeBlock', 0);

        $this->assertNotNull(Kegiatan::find($milikLain->id));
    }

    public function test_bukti_evaluasi_untuk_poin_rtl_iku_lain_diabaikan(): void
    {
        $ikuLain = MasterIku::create(['kode' => '9999', 'indikator' => 'Lain', 'tim' => 'Lain', 'penanggung_jawab' => 'PJ']);
        $poinLain = RtlEvaluasi::create(['iku_id' => $ikuLain->id, 'periode_id' => $this->periode->id, 'rtl_teks' => 'RTL lain', 'pic' => 'PIC', 'batas_waktu' => '2026-09-30']);
        $this->mock(FolderStructureService::class)->shouldNotReceive('unggahBerkasRtl');

        $this->form()->set("evaluasi.{$poinLain->id}.bukti", [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]);

        $this->assertSame(0, Berkas::count());
    }

    public function test_halaman_rtl_bukti_langsung_tersimpan_tanpa_klik_simpan(): void
    {
        $periodeTw3 = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $poin = RtlEvaluasi::create(['iku_id' => $this->iku->id, 'periode_id' => $periodeTw3->id, 'rtl_teks' => 'RTL uji', 'pic' => 'PIC', 'batas_waktu' => '2026-09-30']);

        $this->mock(FolderStructureService::class)->shouldReceive('unggahBerkasRtl')->once()
            ->andReturn(['drive_file_id' => 'drive-rtl', 'storage_account_id' => null, 'nama_file' => 'realisasi.pdf']);

        Livewire::test(RtlEvaluasiComponent::class)
            ->set('tahun', 2026)
            ->set('bulan', 10)
            ->set('iku_id', $this->iku->id)
            ->set("evaluasi.{$poin->id}.bukti", [UploadedFile::fake()->create('realisasi.pdf', 10, 'application/pdf')])
            ->assertDispatched('notify', fn ($n, $p) => $p['type'] === 'success')
            // Form realisasi tetap terbuka walau bukti sudah tersimpan.
            ->assertSee('Simpan Evaluasi');

        $this->assertDatabaseHas('berkas', ['ref_id' => $poin->id, 'drive_file_id' => 'drive-rtl']);
    }
}
