<?php

namespace Tests\Feature;

use App\Livewire\PengisianKegiatan;
use App\Models\Berkas;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Bukti yang dihapus dari aplikasi ikut dibuang dari Google Drive (lihat
 * Berkas::buangSalinan()): satu bukti dihapus permanen, bukti milik kegiatan yang
 * dihapus hanya dipindah ke Sampah. Bila Drive gagal, tidak ada yang dihapus dan
 * kegagalannya ditampilkan.
 */
class BuangSalinanBerkasTest extends TestCase
{
    use RefreshDatabase;

    protected MasterIku $iku;

    protected Kegiatan $kegiatan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'nama' => 'Ketua', 'username' => 'ketua-hapus', 'email' => 'ketua-hapus@example.test', 'password' => 'password',
            'role_id' => Role::create(['nama' => 'Ketua Tim'])->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $this->iku = MasterIku::create(['kode' => '1131', 'indikator' => 'Indikator uji', 'tim' => 'Uji', 'penanggung_jawab' => 'PJ']);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $this->kegiatan = Kegiatan::create([
            'iku_id' => $this->iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'Rapat',
            'jenis' => 'bukan_survei_sensus', 'status_dokumen' => Kegiatan::STATUS_DRAFT,
        ]);

        Storage::fake('local');
    }

    protected function berkas(array $atribut = []): Berkas
    {
        Storage::disk('local')->put('bukti-capaian/a.pdf', 'isi');

        return Berkas::create($atribut + [
            'ref_id' => $this->kegiatan->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian',
            'nama_file' => 'a.pdf', 'path' => 'bukti-capaian/a.pdf', 'drive_file_id' => 'drive-123',
            'status_verifikasi' => 'menunggu',
        ]);
    }

    protected function form()
    {
        return Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $this->iku->id);
    }

    protected function notifikasi(string $jenis, string $potongan): \Closure
    {
        return fn ($nama, $params) => ($params['type'] ?? null) === $jenis && str_contains($params['message'] ?? '', $potongan);
    }

    public function test_hapus_bukti_menghapus_permanen_dari_drive_dan_menampilkan_berhasil(): void
    {
        $berkas = $this->berkas();
        $this->mock(GoogleDriveService::class)->shouldReceive('deleteFile')->once()->with('drive-123', null);

        $this->form()
            ->call('hapusBuktiLama', 0, $berkas->id)
            ->assertDispatched('notify', $this->notifikasi('success', 'dihapus permanen dari SIPINTER dan Google Drive'))
            ->assertSet('blocks.0.existing_bukti', []);

        $this->assertNull(Berkas::find($berkas->id));
        Storage::disk('local')->assertMissing('bukti-capaian/a.pdf');
    }

    public function test_hapus_bukti_gagal_di_drive_berkas_tetap_ada_dan_kegagalan_ditampilkan(): void
    {
        $berkas = $this->berkas();
        $this->mock(GoogleDriveService::class)->shouldReceive('deleteFile')->once()->andThrow(new RuntimeException('Drive mati'));

        $this->form()
            ->call('hapusBuktiLama', 0, $berkas->id)
            ->assertDispatched('notify', $this->notifikasi('error', 'masih ada'))
            ->assertCount('blocks.0.existing_bukti', 1);

        $this->assertNotNull(Berkas::find($berkas->id));
        Storage::disk('local')->assertExists('bukti-capaian/a.pdf');
    }

    public function test_hapus_kegiatan_memindahkan_bukti_ke_sampah_bukan_hapus_permanen(): void
    {
        $this->berkas();
        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldReceive('trashFile')->once()->with('drive-123', null);
        $drive->shouldNotReceive('deleteFile');

        $this->form()
            ->call('removeBlock', 0)
            ->assertDispatched('notify', $this->notifikasi('success', 'Sampah Google Drive'));

        $this->assertSame(0, Kegiatan::count());
        $this->assertSame(0, Berkas::count());
    }

    public function test_hapus_kegiatan_gagal_di_drive_kegiatan_dan_bukti_tetap_ada(): void
    {
        $this->berkas();
        $this->mock(GoogleDriveService::class)->shouldReceive('trashFile')->once()->andThrow(new RuntimeException('Drive mati'));

        $this->form()
            ->call('removeBlock', 0)
            ->assertDispatched('notify', $this->notifikasi('error', 'Kegiatan TIDAK dihapus'))
            ->assertSet('blocks.0.id', $this->kegiatan->id);

        $this->assertSame(1, Kegiatan::count());
        $this->assertSame(1, Berkas::count());
    }

    public function test_berkas_yang_belum_masuk_drive_tidak_menyentuh_drive(): void
    {
        $berkas = $this->berkas(['drive_file_id' => null]);
        $this->mock(GoogleDriveService::class)->shouldNotReceive('deleteFile');

        $berkas->buangSalinan();

        Storage::disk('local')->assertMissing('bukti-capaian/a.pdf');
    }

    public function test_dialog_konfirmasi_menjelaskan_akibat_hapus(): void
    {
        Livewire::test(PengisianKegiatan::class)
            ->assertSee('tidak masuk Sampah', false)
            ->assertSee('dihapus permanen dari SIPINTER dan dari Google Drive', false)
            ->assertSee('dipindahkan ke Sampah Google Drive', false)
            ->assertSee('tertaut ke poin RTL', false);
    }
}
