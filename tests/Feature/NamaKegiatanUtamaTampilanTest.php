<?php

namespace Tests\Feature;

use App\Livewire\DasborCapaian;
use App\Livewire\PengisianKegiatan;
use App\Livewire\VerifikasiCapaian;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Nama kegiatan utama (uraian_kegiatan, Kegiatan::namaCapaian()) jadi identitas
 * kegiatan di Isian Kegiatan, Verifikasi Capaian, dan Dasbor; nama folder tahapan
 * hanya tampil sebagai lokasi bukti dukung/unggah. Data lama (baris per tahapan,
 * nama otomatis "[Persiapan] ...", nama folder kustom) ikut aturan yang sama
 * tanpa diubah.
 */
class NamaKegiatanUtamaTampilanTest extends TestCase
{
    use RefreshDatabase;

    protected MasterIku $iku;

    protected Periode $periode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->iku = MasterIku::create(['kode' => 'UJI-NKU', 'indikator' => 'Indikator uji', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $this->periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
    }

    protected function login(string $peran): void
    {
        $this->actingAs(User::create([
            'nama' => "{$peran} Uji", 'username' => 'uji-nku@example.test', 'email' => 'uji-nku@example.test',
            'password' => 'password', 'role_id' => Role::create(['nama' => $peran])->id, 'status_verifikasi' => 'terverifikasi',
        ]));
    }

    /**
     * Kegiatan survei lama yang sudah tersimpan per tahapan: nama otomatis format
     * lama berkurung siku, satu tahapan dengan nama folder kustom, folder Drive ada.
     *
     * @return array<string, Kegiatan>
     */
    protected function kegiatanLamaPerTahapan(string $status = Kegiatan::STATUS_DIAJUKAN): array
    {
        $buat = fn (string $tahapan, string $auto, ?string $kustom, string $folder) => Kegiatan::create([
            'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id,
            'uraian_kegiatan' => 'Survei  Sakernas', 'jenis' => 'survei_sensus', 'tahapan_survei' => $tahapan,
            'nama_folder_auto' => $auto, 'nama_folder_kustom' => $kustom, 'drive_folder_id' => $folder,
            'status_dokumen' => $status,
        ]);

        $kegiatan = [
            'persiapan' => $buat('persiapan', '[Persiapan] Survei Sakernas', null, 'folder-persiapan'),
            'pelaksanaan' => $buat('pelaksanaan', 'Pelaksanaan Survei Sakernas', 'Lapangan Sakernas', 'folder-pelaksanaan'),
            'pengolahan' => $buat('pengolahan', 'Pengolahan Survei Sakernas', null, 'folder-pengolahan'),
        ];

        foreach ($kegiatan as $tahapan => $k) {
            Berkas::create([
                'ref_id' => $k->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian',
                'nama_file' => "bukti {$tahapan}.pdf", 'status_verifikasi' => 'menunggu', 'drive_file_id' => "drive-{$tahapan}",
            ]);
        }

        return $kegiatan;
    }

    public function test_verifikasi_menampilkan_nama_kegiatan_utama_dan_folder_tahapan_sebagai_lokasi(): void
    {
        $this->login('Tim SAKIP');
        $kegiatan = $this->kegiatanLamaPerTahapan();
        $capaian = Capaian::create(['iku_id' => $this->iku->id, 'periode_id' => $this->periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);

        $test = Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian])
            ->assertSee('Nama kegiatan (capaian)')
            ->assertSee('Lokasi bukti dukung:')
            // Folder tiap tahapan tetap terlihat untuk membedakan bukti dukungnya.
            ->assertSee('[Persiapan] Survei Sakernas')
            ->assertSee('Lapangan Sakernas')
            ->assertSee('Pengolahan Survei Sakernas')
            ->assertDontSeeHtml('Capaian: <b>Persiapan Survei Sakernas</b>')
            ->assertDontSeeHtml('Capaian: <b>Lapangan Sakernas</b>')
            ->assertSeeInOrder(['Tahapan Persiapan', 'Tahapan Pelaksanaan', 'Tahapan Pengolahan']);

        // Verifikasi tetap per baris tahapan: menandai satu tidak mengubah yang lain.
        $test->call('tandaiUraianSesuai', $kegiatan['pelaksanaan']->id)->assertHasNoErrors();

        $this->assertSame('terverifikasi', $kegiatan['pelaksanaan']->fresh()->status_verifikasi_uraian);
        $this->assertNotSame('terverifikasi', $kegiatan['persiapan']->fresh()->status_verifikasi_uraian);
        $this->assertNotSame('terverifikasi', $kegiatan['pengolahan']->fresh()->status_verifikasi_uraian);

        // Data lama tidak diubah: record, nama folder, folder Drive, dan berkas tetap.
        $this->assertSame(3, Kegiatan::count());
        foreach ($kegiatan as $tahapan => $k) {
            $segar = $k->fresh();
            $this->assertSame("folder-{$tahapan}", $segar->drive_folder_id);
            $this->assertSame($k->nama_folder_auto, $segar->nama_folder_auto);
            $this->assertSame($k->nama_folder_kustom, $segar->nama_folder_kustom);
            $this->assertSame("drive-{$tahapan}", Berkas::where('ref_id', $k->id)->sole()->drive_file_id);
        }
    }

    public function test_dasbor_menampilkan_nama_kegiatan_utama_dengan_tahapan_sebagai_keterangan(): void
    {
        $this->login('Tim SAKIP');
        $this->kegiatanLamaPerTahapan();

        Livewire::test(DasborCapaian::class)->set('tahun', 2026)
            ->assertSee('Survei Sakernas')
            ->assertSeeHtml('class="tahapan-kegiatan"')
            ->assertSeeInOrder(['Survei Sakernas', 'Tahapan Persiapan', 'Survei Sakernas', 'Tahapan Pelaksanaan', 'Survei Sakernas', 'Tahapan Pengolahan'])
            ->assertDontSee('· Tahapan')
            ->assertDontSee('Lapangan Sakernas')
            ->assertDontSee('Persiapan Survei Sakernas');
    }

    public function test_isian_kegiatan_nama_capaian_tetap_uraian_dan_folder_tahapan_hanya_lokasi_unggah(): void
    {
        $this->login('Ketua Tim');

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $this->iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Survei Sakernas')
            ->set('blocks.0.jenis', 'survei_sensus')
            ->call('toggleTahapan', 0, 'persiapan')
            ->call('toggleTahapan', 0, 'pelaksanaan')
            ->assertSeeHtml('Capaian: <b x-text="uraianTeks.trim()">Survei Sakernas</b>')
            ->assertSeeHtml('tahapan <b>Persiapan</b>')
            ->assertSeeHtml('tahapan <b>Pelaksanaan</b>')
            // Pratinjau tujuan unggah = folder tahapan yang tombolnya diklik.
            ->assertSeeHtml("pendingFolderUnggah = 'Pelaksanaan Survei Sakernas'")
            ->assertDontSeeHtml('Capaian: <b>Pelaksanaan Survei Sakernas</b>');
    }

    public function test_nama_capaian_notula_data_lama_satu_entri_per_kegiatan_utama(): void
    {
        $kegiatan = $this->kegiatanLamaPerTahapan();
        Kegiatan::create([
            'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id,
            'uraian_kegiatan' => 'Rapat koordinasi', 'jenis' => 'bukan_survei_sensus',
            'nama_folder_auto' => 'Rapat koordinasi', 'nama_folder_kustom' => 'Folder Rapat Agustus',
            'status_dokumen' => Kegiatan::STATUS_DIAJUKAN,
        ]);

        $this->assertSame(['Survei Sakernas', 'Rapat koordinasi'], Kegiatan::daftarNamaCapaian(Kegiatan::all())->all());
        $this->assertSame('Survei Sakernas', $kegiatan['pelaksanaan']->namaCapaian());
    }
}
