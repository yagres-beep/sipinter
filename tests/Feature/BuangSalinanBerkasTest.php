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
use Livewire\Livewire;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Bukti yang dihapus dari aplikasi ikut dihapus permanen dari Google Drive, supaya
 * folder Drive tidak menyimpan bukti yang sudah dibuang (lihat Berkas::buangSalinan()).
 */
class BuangSalinanBerkasTest extends TestCase
{
    use RefreshDatabase;

    protected function berkas(array $atribut = []): Berkas
    {
        $iku = MasterIku::create(['kode' => '1131', 'indikator' => 'Indikator uji', 'tim' => 'Uji', 'penanggung_jawab' => 'PJ']);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $kegiatan = Kegiatan::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'Rapat',
            'jenis' => 'bukan_survei_sensus', 'status_dokumen' => Kegiatan::STATUS_DRAFT,
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('bukti-capaian/a.pdf', 'isi');

        return Berkas::create($atribut + [
            'ref_id' => $kegiatan->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian',
            'nama_file' => 'a.pdf', 'path' => 'bukti-capaian/a.pdf', 'drive_file_id' => 'drive-123',
            'status_verifikasi' => 'menunggu',
        ]);
    }

    public function test_salinan_lokal_dan_salinan_drive_dihapus_permanen(): void
    {
        $berkas = $this->berkas();

        $this->mock(GoogleDriveService::class)->shouldReceive('deleteFile')->once()->with('drive-123', null);

        $berkas->buangSalinan();

        Storage::disk('local')->assertMissing('bukti-capaian/a.pdf');
    }

    public function test_gagal_di_drive_tidak_menggagalkan_penghapusan(): void
    {
        $berkas = $this->berkas();

        $this->mock(GoogleDriveService::class)->shouldReceive('deleteFile')->once()->andThrow(new RuntimeException('Drive mati'));

        $berkas->buangSalinan();

        Storage::disk('local')->assertMissing('bukti-capaian/a.pdf');
    }

    public function test_halaman_isian_meminta_konfirmasi_hapus_permanen(): void
    {
        $this->actingAs(User::create([
            'nama' => 'Ketua', 'username' => 'ketua-hapus', 'email' => 'ketua-hapus@example.test', 'password' => 'password',
            'role_id' => Role::create(['nama' => 'Ketua Tim'])->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        Livewire::test(PengisianKegiatan::class)
            ->assertSee('tidak masuk Sampah', false)
            ->assertSee('dihapus permanen dari SIPINTER dan dari Google Drive', false)
            ->assertSee('Ya, Hapus Permanen', false);
    }

    public function test_berkas_yang_belum_masuk_drive_tidak_menyentuh_drive(): void
    {
        $berkas = $this->berkas(['drive_file_id' => null]);

        $this->mock(GoogleDriveService::class)->shouldNotReceive('deleteFile');

        $berkas->buangSalinan();

        Storage::disk('local')->assertMissing('bukti-capaian/a.pdf');
    }
}
