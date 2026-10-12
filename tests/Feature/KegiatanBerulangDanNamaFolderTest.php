<?php

namespace Tests\Feature;

use App\Livewire\PengisianKegiatan;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\RtlEvaluasi;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class KegiatanBerulangDanNamaFolderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{iku: MasterIku, poin: RtlEvaluasi, periodeJuli: Periode}
     */
    protected function siapkan(): array
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'username' => 'ketua-berulang@example.test', 'email' => 'ketua-berulang@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-BRL',
            'indikator' => 'Indikator uji berulang',
            'tim' => 'Uji',
            'penanggung_jawab' => 'Ketua Uji',
        ]);

        $periodeJuli = Periode::create([
            'tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false,
        ]);

        $poin = RtlEvaluasi::create([
            'iku_id' => $iku->id, 'periode_id' => $periodeJuli->id,
            'rtl_teks' => 'Survei Sakernas', 'berlaku_bulan' => 'Juli, Agustus, dan September', 'pic' => 'PIC', 'batas_waktu' => '2026-09-30',
        ]);

        $this->actingAs($ketua);

        return compact('iku', 'poin', 'periodeJuli');
    }

    public function test_poin_rtl_bisa_dipilih_berulang_dengan_tahapan_berbeda(): void
    {
        ['iku' => $iku, 'poin' => $poin] = $this->siapkan();

        $test = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id)
            ->call('pilihRtlUntukBlock', 0, $poin->id)
            ->set('blocks.0.jenis', 'survei_sensus')
            ->set('blocks.0.tahapan_survei', 'persiapan')
            ->call('addBlock')
            ->call('pilihRtlUntukBlock', 1, $poin->id)
            ->assertSet('blocks.1.rtl_evaluasi_id', $poin->id)
            ->set('blocks.1.jenis', 'survei_sensus')
            // Opsi RTL yang sudah dipilih tetap tampil, diberi penanda.
            ->assertSee('sudah dipilih 1×');

        $this->assertSame(['persiapan'], $test->instance()->tahapanTerpakai(1));

        $test->set('blocks.1.tahapan_survei', 'pelaksanaan')
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $this->assertSame(2, Kegiatan::where('rtl_evaluasi_id', $poin->id)->count());
    }

    public function test_tahapan_yang_sama_untuk_kegiatan_yang_sama_ditolak(): void
    {
        ['iku' => $iku, 'poin' => $poin] = $this->siapkan();

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id)
            ->call('pilihRtlUntukBlock', 0, $poin->id)
            ->set('blocks.0.jenis', 'survei_sensus')
            ->set('blocks.0.tahapan_survei', 'persiapan')
            ->call('addBlock')
            ->call('pilihRtlUntukBlock', 1, $poin->id)
            ->set('blocks.1.jenis', 'survei_sensus')
            ->set('blocks.1.tahapan_survei', 'persiapan')
            ->call('simpanDraft')
            ->assertHasErrors(['blocks.1.tahapan_survei'])
            ->assertHasNoErrors(['blocks.0.tahapan_survei']);

        $this->assertSame(0, Kegiatan::count());
    }

    public function test_tahapan_yang_sudah_dipakai_bulan_lain_di_triwulan_yang_sama_tidak_bisa_dipilih(): void
    {
        ['iku' => $iku, 'poin' => $poin, 'periodeJuli' => $periodeJuli] = $this->siapkan();

        Kegiatan::create([
            'iku_id' => $iku->id, 'rtl_evaluasi_id' => $poin->id, 'periode_id' => $periodeJuli->id,
            'uraian_kegiatan' => 'Survei Sakernas', 'jenis' => 'survei_sensus', 'tahapan_survei' => 'persiapan',
            'status_dokumen' => Kegiatan::STATUS_DIAJUKAN,
        ]);

        $test = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id)
            ->call('pilihRtlUntukBlock', 0, $poin->id)
            ->set('blocks.0.jenis', 'survei_sensus');

        $this->assertSame(['persiapan'], $test->instance()->tahapanTerpakai(0));

        $test->set('blocks.0.tahapan_survei', 'persiapan')
            ->call('simpanDraft')
            ->assertHasErrors(['blocks.0.tahapan_survei']);
    }

    public function test_kegiatan_bukan_survei_boleh_dipilih_berulang(): void
    {
        ['iku' => $iku, 'poin' => $poin] = $this->siapkan();

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id)
            ->call('pilihRtlUntukBlock', 0, $poin->id)
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->call('addBlock')
            ->call('pilihRtlUntukBlock', 1, $poin->id)
            ->set('blocks.1.jenis', 'bukan_survei_sensus')
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $this->assertSame(2, Kegiatan::where('rtl_evaluasi_id', $poin->id)->count());
    }

    public function test_nama_folder_bisa_diganti_ketua_tim(): void
    {
        ['iku' => $iku] = $this->siapkan();

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Rapat koordinasi')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.nama_folder', 'Rapat Koordinasi Agustus')
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $kegiatan = Kegiatan::firstOrFail();
        $this->assertSame('Rapat koordinasi', $kegiatan->nama_folder_auto);
        $this->assertSame('Rapat Koordinasi Agustus', $kegiatan->nama_folder_kustom);
        $this->assertSame('Rapat Koordinasi Agustus', $kegiatan->namaFolder());
    }

    public function test_ganti_nama_folder_ikut_mengganti_folder_di_drive(): void
    {
        ['iku' => $iku, 'periodeJuli' => $periodeJuli] = $this->siapkan();

        $kegiatan = Kegiatan::create([
            'iku_id' => $iku->id, 'periode_id' => $periodeJuli->id,
            'uraian_kegiatan' => 'Rapat koordinasi', 'jenis' => 'bukan_survei_sensus',
            'nama_folder_auto' => 'Rapat koordinasi', 'drive_folder_id' => 'folder-123',
            'status_dokumen' => Kegiatan::STATUS_DIAJUKAN,
        ]);

        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldReceive('renameFile')->once()->with('folder-123', 'Folder Baru');

        $hasil = $kegiatan->gantiNamaFolder('Folder Baru');

        $this->assertSame('Folder Baru', $hasil['nama_folder']);
        $this->assertNull($hasil['peringatan']);
        $this->assertSame('Folder Baru', $kegiatan->fresh()->nama_folder_kustom);

        // Dikosongkan = kembali ke rekomendasi sistem.
        $drive->shouldReceive('renameFile')->once()->with('folder-123', 'Rapat koordinasi');
        $kegiatan->gantiNamaFolder('');
        $this->assertNull($kegiatan->fresh()->nama_folder_kustom);
    }

    public function test_nama_default_berkas_diberi_nomor_bila_lebih_dari_satu(): void
    {
        $this->siapkan();
        $komponen = Livewire::test(PengisianKegiatan::class)->instance();

        $this->assertSame('Kegiatan Rapat', $komponen->namaDefaultBuktiBaru('Kegiatan Rapat', 0, 0, 1));
        $this->assertSame('Kegiatan Rapat 1', $komponen->namaDefaultBuktiBaru('Kegiatan Rapat', 0, 0, 2));
        $this->assertSame('Kegiatan Rapat 2', $komponen->namaDefaultBuktiBaru('Kegiatan Rapat', 0, 1, 2));
        // Melanjutkan nomor setelah berkas yang sudah tersimpan.
        $this->assertSame('Kegiatan Rapat 3', $komponen->namaDefaultBuktiBaru('Kegiatan Rapat', 2, 0, 1));
    }

    public function test_nama_berkas_bawaan_memakai_nama_asli_dan_bisa_pindah_ke_rekomendasi_sistem(): void
    {
        $this->siapkan();
        $test = Livewire::test(PengisianKegiatan::class);
        $komponen = $test->instance();
        $file = UploadedFile::fake()->create('Notulen: Rapat.pdf', 10, 'application/pdf');

        $this->assertSame('asli', $komponen->modeNamaBerkas);
        $this->assertSame('Notulen Rapat', $komponen->namaBuktiBaruAtauDefault($file, 'Kegiatan Rapat'));

        $komponen->modeNamaBerkas = 'sistem';
        $this->assertSame('Kegiatan Rapat', $komponen->namaBuktiBaruAtauDefault($file, 'Kegiatan Rapat'));

        // Nama yang diganti lewat ✏️ tetap diutamakan apa pun modenya.
        $komponen->aturNamaBuktiBaru($komponen->kunciBuktiBaru($file), 'Nama Pilihan');
        $this->assertSame('Nama Pilihan', $komponen->namaBuktiBaruAtauDefault($file, 'Kegiatan Rapat'));

        // Nilai di luar pilihan dikembalikan ke bawaan.
        $test->set('modeNamaBerkas', 'aneh')->assertSet('modeNamaBerkas', 'asli');
    }
}
