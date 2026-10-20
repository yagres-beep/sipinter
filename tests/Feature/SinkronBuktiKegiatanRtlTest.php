<?php

namespace Tests\Feature;

use App\Livewire\PengisianKegiatan;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bukti capaian kegiatan (Bagian 2) yang tertaut ke poin RTL tampil sebagai bukti
 * realisasi poin itu di Evaluasi RTL (Bagian 4) lewat relasi kegiatan.rtl_evaluasi_id —
 * baris berkas yang SAMA (satu status verifikasi); salinan fisiknya di folder
 * Evaluasi-RTL dicatat terpisah di salinan_bukti_rtl (lihat SalinanBuktiRtlTest) —
 * dan tahapan tidak pernah menggantikan nama kegiatan.
 */
class SinkronBuktiKegiatanRtlTest extends TestCase
{
    use RefreshDatabase;

    protected MasterIku $iku;

    protected RtlEvaluasi $poin;

    protected RtlEvaluasi $poinLain;

    /** @var list<array{kegiatan: int, tahapan: ?string, nama: ?string}> */
    protected array $unggahan = [];

    protected function setUp(): void
    {
        parent::setUp();

        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $this->actingAs(User::create([
            'nama' => 'Ketua Uji', 'username' => 'ketua-sinkron@example.test', 'email' => 'ketua-sinkron@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $this->iku = MasterIku::create(['kode' => 'UJI-SNK', 'indikator' => 'Indikator sinkron', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $periodeJuli = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        $this->poin = RtlEvaluasi::create([
            'iku_id' => $this->iku->id, 'periode_id' => $periodeJuli->id,
            'rtl_teks' => 'Terlaksananya Persiapan kegiatan Survei Pertanian Terintegrasi (SITASI) 2026',
            'berlaku_bulan' => 'RTL untuk Juli, Agustus, dan September', 'pic' => 'Tim Uji', 'batas_waktu' => '2026-09-30',
        ]);
        $this->poinLain = RtlEvaluasi::create([
            'iku_id' => $this->iku->id, 'periode_id' => $periodeJuli->id,
            'rtl_teks' => 'Terlaksananya pengumpulan data KSA Padi',
            'berlaku_bulan' => 'RTL untuk Juli, Agustus, dan September', 'pic' => 'Tim Uji', 'batas_waktu' => '2026-09-30',
        ]);

        $this->mock(FolderStructureService::class, function ($mock) {
            $mock->shouldReceive('unggahBerkasKegiatan')->andReturnUsing(function (Kegiatan $kegiatan, $kategori, $path, $ekstensi = '.pdf', $mime = 'application/pdf', $nama = null) {
                $this->unggahan[] = ['kegiatan' => $kegiatan->id, 'tahapan' => $kegiatan->tahapan_survei, 'nama' => $nama];

                return ['drive_file_id' => 'drive-'.count($this->unggahan), 'storage_account_id' => null, 'nama_file' => $nama.'.pdf'];
            });
            $mock->shouldReceive('unggahBerkasRtl')->andReturnUsing(fn ($poin, $path, $ekstensi = '.pdf', $mime = 'application/pdf', $nama = null) => ['drive_file_id' => 'drive-rtl', 'storage_account_id' => null, 'nama_file' => $nama.'.pdf']);
        });
    }

    protected function form(int $bulan = 8)
    {
        return Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', $bulan)
            ->set('iku_id', $this->iku->id);
    }

    protected function pdf(string $nama): UploadedFile
    {
        return UploadedFile::fake()->create($nama, 10, 'application/pdf');
    }

    /** Skenario 1 — kegiatan dari RTL, satu folder: bukti tampil di kedua bagian tanpa salinan. */
    public function test_bukti_kegiatan_satu_folder_tampil_di_evaluasi_rtl_tanpa_salinan(): void
    {
        $this->form()
            ->call('pilihRtlUntukBlock', 0, $this->poin->id)
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [$this->pdf('undangan.pdf'), $this->pdf('daftar hadir.pdf')])
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $kegiatan = Kegiatan::sole();
        $this->assertSame($this->poin->id, $kegiatan->rtl_evaluasi_id);

        // Hanya 2 baris berkas & 2 unggahan Drive — milik kegiatan, tidak ada salinan RTL.
        $this->assertSame(2, Berkas::count());
        $this->assertSame(2, Berkas::where('ref_type', Kegiatan::class)->where('ref_id', $kegiatan->id)->count());
        $this->assertCount(2, $this->unggahan);
        $this->assertSame(0, $this->poin->berkas()->count());

        $this->assertSame(2, $this->poin->buktiKegiatan()->count());
        $this->assertTrue($this->poin->sudahDievaluasi());
        $this->assertFalse($this->poinLain->sudahDievaluasi());
        $this->assertEqualsCanonicalizing([$this->poin->id], RtlEvaluasi::yangSudahDievaluasi()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$this->poinLain->id], RtlEvaluasi::yangBelumDievaluasi()->pluck('id')->all());

        // Dibuka ulang: Bagian 4 menampilkan berkas yang sama, dan kotak unggah RTL
        // hanya ditawarkan untuk poin yang belum dilaksanakan kegiatan.
        $this->form()
            ->assertSee('Dari Bukti Capaian Kegiatan (Bagian 2)')
            ->assertSee('undangan.pdf')
            ->assertSee('daftar hadir.pdf')
            ->assertDontSeeHtml('wire:model="unggahRtl.'.$this->poin->id.'.0.0"')
            ->assertSeeHtml('wire:model="unggahRtl.'.$this->poinLain->id.'.0.0"');

        $this->assertSame(2, Berkas::count());
    }

    /** Skenario 2 — dipisah ke beberapa tahapan: nama tetap uraian induk, semua bukti tersinkron. */
    public function test_kegiatan_bertahapan_tetap_atas_nama_uraian_induk_dan_semua_bukti_tersinkron(): void
    {
        $test = $this->form()
            ->set('modeNamaBerkas', 'sistem')
            ->call('pilihRtlUntukBlock', 0, $this->poin->id)
            ->set('blocks.0.jenis', 'survei_sensus')
            ->call('toggleTahapan', 0, 'persiapan')
            ->call('toggleTahapan', 0, 'pelaksanaan')
            ->set('blocks.0.bukti', [$this->pdf('a.pdf'), $this->pdf('b.pdf')]);

        [, $berkasKedua] = $test->get('blocks.0.bukti');
        $test->set('tahapanBuktiBaru.'.$test->instance()->kunciBuktiBaru($berkasKedua), 'pelaksanaan')
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $uraian = $this->poin->rtl_teks;
        $baris = Kegiatan::orderBy('id')->get();

        $this->assertCount(2, $baris);
        foreach ($baris as $kegiatan) {
            $this->assertSame($uraian, $kegiatan->uraian_kegiatan);
            $this->assertSame($uraian, $kegiatan->uraianTampil());
            $this->assertSame($uraian, $kegiatan->namaCapaian());
            $this->assertSame($this->poin->id, $kegiatan->rtl_evaluasi_id);
        }
        $this->assertSame(['persiapan', 'pelaksanaan'], $baris->pluck('tahapan_survei')->all());

        // Nama bukti bawaan atas nama kegiatan induk, bukan "<Tahapan> <uraian>".
        $this->assertSame(['Kegiatan '.$uraian.' 1', 'Kegiatan '.$uraian.' 2'], array_column($this->unggahan, 'nama'));
        $this->assertSame(['persiapan', 'pelaksanaan'], array_column($this->unggahan, 'tahapan'));

        $this->assertSame(2, $this->poin->buktiKegiatan()->count());
        $this->assertSame(2, Berkas::count());

        // Dibuka ulang: tahapan ditandai sebagai keterangan di bawah satu kegiatan.
        $form = $this->form();
        $this->assertSame('Kegiatan 1 · Tahapan Persiapan', $form->instance()->judulBlokKegiatan(0));
        $this->assertSame('Kegiatan 1 · Tahapan Pelaksanaan', $form->instance()->judulBlokKegiatan(1));
        $form->assertSee('Tahapan Persiapan')->assertSee('Tahapan Pelaksanaan')
            ->assertSee('Kegiatan '.$uraian.' 1.pdf');
    }

    /** Bukti kegiatan bulan lain di triwulan yang sama juga tampil (hanya-lihat) di Evaluasi RTL. */
    public function test_bukti_kegiatan_bulan_lain_triwulan_ini_ikut_tampil(): void
    {
        $this->form(7)
            ->call('pilihRtlUntukBlock', 0, $this->poin->id)
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [$this->pdf('bukti juli.pdf')])
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $this->form(8)
            ->assertSee('bukti juli.pdf')
            ->assertSee('diunggah di isian bulan Juli')
            ->assertDontSeeHtml('wire:model="unggahRtl.'.$this->poin->id.'.0.0"');
    }

    /** Skenario 3 — uraian disunting: tautan RTL (berbasis ID) tidak putus. */
    public function test_menyunting_uraian_tidak_memutus_tautan_rtl(): void
    {
        $this->form()
            ->call('pilihRtlUntukBlock', 0, $this->poin->id)
            ->set('blocks.0.uraian_kegiatan', 'Persiapan SITASI 2026 (pelatihan petugas)')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [$this->pdf('pelatihan.pdf')])
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $kegiatan = Kegiatan::sole();
        $this->assertSame($this->poin->id, $kegiatan->rtl_evaluasi_id);
        $this->assertSame(1, $this->poin->buktiKegiatan()->count());

        $this->form()->assertSee('Kegiatan: Persiapan SITASI 2026 (pelatihan petugas)', false);
    }

    /** Skenario 4 — kegiatan manual berteks mirip RTL tidak ikut tersinkron. */
    public function test_kegiatan_manual_mirip_rtl_tidak_tersinkron(): void
    {
        $this->form()
            ->set('blocks.0.uraian_kegiatan', 'Terlaksananya pengumpulan data KSA Padi')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [$this->pdf('ksa.pdf')])
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $this->assertNull(Kegiatan::sole()->rtl_evaluasi_id);
        $this->assertSame(0, $this->poinLain->buktiKegiatan()->count());
        $this->assertFalse($this->poinLain->sudahDievaluasi());

        $this->form()->assertSeeHtml('wire:model="unggahRtl.'.$this->poinLain->id.'.0.0"');
    }

    /** Skenario 5 — unggah di Bagian 4 untuk poin yang tidak tertaut tetap milik poin RTL saja. */
    public function test_unggah_di_evaluasi_rtl_tidak_menggandakan_ke_kegiatan(): void
    {
        $this->form()
            ->call('pilihRtlUntukBlock', 0, $this->poin->id)
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [$this->pdf('sitasi.pdf')])
            ->set("evaluasi.{$this->poinLain->id}.bukti", [$this->pdf('ksa realisasi.pdf')]);

        $this->assertSame(1, $this->poinLain->berkas()->count());
        $this->assertSame(0, $this->poinLain->buktiKegiatan()->count());
        $this->assertSame(1, $this->poin->buktiKegiatan()->count());
        $this->assertSame(0, $this->poin->berkas()->count());
        $this->assertSame(2, Berkas::count());
    }

    /** Skenario 6 — menghapus bukti tertaut menghapus satu berkas yang sama dari kedua bagian. */
    public function test_hapus_bukti_tertaut_menghapus_dari_kegiatan_dan_evaluasi_rtl(): void
    {
        $this->form()
            ->call('pilihRtlUntukBlock', 0, $this->poin->id)
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [$this->pdf('salah.pdf'), $this->pdf('benar.pdf')])
            ->call('simpanDraft');

        [$salah, $benar] = Berkas::orderBy('id')->get()->all();

        $this->mock(GoogleDriveService::class)->shouldReceive('deleteFile')->once()->with($salah->drive_file_id, \Mockery::any());

        $this->form()
            ->call('hapusBuktiLama', 0, $salah->id)
            ->assertDontSee('salah.pdf')
            ->assertSee('benar.pdf');

        $this->assertModelMissing($salah);
        $this->assertModelExists($benar);
        $this->assertSame(1, $this->poin->buktiKegiatan()->count());
    }

    public function test_hapus_bukti_tertaut_gagal_di_drive_tidak_menghapus_apa_pun(): void
    {
        $this->form()
            ->call('pilihRtlUntukBlock', 0, $this->poin->id)
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [$this->pdf('bukti.pdf')])
            ->call('simpanDraft');

        $berkas = Berkas::sole();
        $this->mock(GoogleDriveService::class)->shouldReceive('deleteFile')->andThrow(new \RuntimeException('Drive tidak bisa dihubungi'));

        $this->form()->call('hapusBuktiLama', 0, $berkas->id)->assertDispatched('notify');

        $this->assertModelExists($berkas);
        $this->assertTrue($this->poin->sudahDievaluasi());
    }

    /** Skenario 7 — bukti kegiatan tertaut memenuhi syarat bukti realisasi di bulan terakhir triwulan. */
    public function test_bukti_kegiatan_tertaut_memenuhi_syarat_bukti_realisasi_bulan_terakhir(): void
    {
        $test = $this->form(9)
            ->call('pilihRtlUntukBlock', 0, $this->poin->id)
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [$this->pdf('sitasi.pdf')]);

        // Belum disimpan pun sudah dihitung; hanya poin yang tidak tertaut yang kurang.
        $kekurangan = implode("\n", $test->instance()->kekuranganPengajuan());
        $this->assertStringContainsString('1 poin evaluasi RTL', $kekurangan);

        $test->call('simpanDraft');
        $this->assertTrue($this->poin->sudahDievaluasi());

        // Simpan ulang tidak menggandakan bukti.
        $this->form(9)->call('simpanDraft')->assertHasNoErrors();
        $this->assertSame(1, Berkas::count());
        $this->assertSame(Kegiatan::sole()->id, Berkas::sole()->ref_id);
    }
}
