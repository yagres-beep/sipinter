<?php

namespace Tests\Feature;

use App\Livewire\PengisianKegiatan;
use App\Livewire\VerifikasiCapaian;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\FolderConfig;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\RiwayatVerifikasiBerkas;
use App\Models\Role;
use App\Models\RtlEvaluasi;
use App\Models\SalinanBuktiRtl;
use App\Models\StorageAccount;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\DrivePalsu;
use Tests\TestCase;

/**
 * Bukti kegiatan tertaut RTL tersalin fisik ke folder Evaluasi-RTL sesuai Struktur
 * Folder Drive, unggahan mandiri Evaluasi RTL memakai pembentukan folder yang sama
 * dengan Kegiatan, dan status verifikasi bukti yang sama hanya punya satu sumber.
 */
class SalinanBuktiRtlTest extends TestCase
{
    use RefreshDatabase;

    protected DrivePalsu $drive;

    protected User $ketua;

    protected MasterIku $iku;

    protected Periode $agustus;

    protected RtlEvaluasi $poin;

    protected RtlEvaluasi $poinLain;

    protected const AKAR = 'root/2026/Triwulan III/UJI-SLN. Indikator salinan';

    protected function setUp(): void
    {
        parent::setUp();

        $this->drive = new DrivePalsu;
        $this->app->instance(GoogleDriveService::class, $this->drive);

        StorageAccount::create(['email_gmail_institusi' => 'aktif@example.test', 'status' => StorageAccount::STATUS_AKTIF, 'drive_folder_id' => 'root']);

        $this->ketua = User::create([
            'nama' => 'Ketua Uji', 'username' => 'ketua-salinan@example.test', 'email' => 'ketua-salinan@example.test',
            'password' => 'password', 'role_id' => Role::create(['nama' => 'Ketua Tim'])->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($this->ketua);

        $this->iku = MasterIku::create(['kode' => 'UJI-SLN', 'indikator' => 'Indikator salinan', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $juli = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->agustus = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        $rtl = fn (string $teks) => RtlEvaluasi::create([
            'iku_id' => $this->iku->id, 'periode_id' => $juli->id, 'rtl_teks' => $teks,
            'berlaku_bulan' => 'RTL untuk Juli, Agustus, dan September', 'pic' => 'Tim Uji', 'batas_waktu' => '2026-09-30',
            'status_dokumen' => RtlEvaluasi::STATUS_DIAJUKAN,
        ]);
        $this->poin = $rtl('Rapat koordinasi mitra');
        $this->poinLain = $rtl('Pelatihan petugas lapangan');

        $this->aturKategori('Evaluasi-RTL', ['subfolder_per_kegiatan' => true, 'subfolder_per_bulan' => false]);
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

    protected function form(int $bulan = 8)
    {
        return Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', $bulan)
            ->set('iku_id', $this->iku->id);
    }

    protected function pdf(string $nama): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nama, '%PDF-1.4 '.$nama);
    }

    /** Kegiatan tertaut $poin dengan bukti $berkas, disimpan sebagai draf. */
    protected function kegiatanTertaut(array $berkas, ?RtlEvaluasi $poin = null, int $bulan = 8)
    {
        return $this->form($bulan)
            ->call('pilihRtlUntukBlock', 0, ($poin ?? $this->poin)->id)
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', array_map(fn ($n) => $this->pdf($n), $berkas))
            ->call('simpanDraft')
            ->assertHasNoErrors();
    }

    /** Capaian Agustus (dibuat Simpan Draft bila sudah ada isian) dengan status $status. */
    protected function capaianAgustus(string $status): Capaian
    {
        $capaian = Capaian::firstOrCreate(['iku_id' => $this->iku->id, 'periode_id' => $this->agustus->id]);
        $capaian->update(['status' => $status]);

        return $capaian;
    }

    protected function sakip(): User
    {
        return User::create([
            'nama' => 'Tim SAKIP Uji', 'username' => 'sakip-salinan@example.test', 'email' => 'sakip-salinan@example.test',
            'password' => 'password', 'role_id' => Role::create(['nama' => 'Tim SAKIP'])->id, 'status_verifikasi' => 'terverifikasi',
        ]);
    }

    /** Uji 1, 2, 16 — satu unggahan di Kegiatan tersedia di kedua folder, tanpa duplikat. */
    public function test_bukti_kegiatan_tertaut_tersalin_ke_folder_evaluasi_rtl(): void
    {
        $this->kegiatanTertaut(['undangan.pdf']);

        $sumber = Berkas::sole();
        $this->assertSame(Kegiatan::class, $sumber->ref_type);
        $this->assertSame([
            self::AKAR.'/Capaian/Agustus/Rapat koordinasi mitra/undangan.pdf',
            self::AKAR.'/Evaluasi-RTL/Rapat koordinasi mitra/undangan.pdf',
        ], $this->drive->semuaBerkas());

        $salinan = SalinanBuktiRtl::sole();
        $this->assertSame(SalinanBuktiRtl::STATUS_TERSALIN, $salinan->status);
        $this->assertSame($sumber->drive_file_id, $salinan->sumber_drive_file_id);
        $this->assertSame($this->poin->id, $salinan->rtl_evaluasi_id);
        $this->assertNotSame($sumber->drive_file_id, $salinan->drive_file_id);
        // Isi identik (disalin di sisi Drive dari berkas sumber).
        $this->assertSame($this->drive->item[$sumber->drive_file_id]['isi'], $this->drive->item[$salinan->drive_file_id]['isi']);

        // Simpan ulang & sinkron ulang tidak menyalin lagi.
        $this->form()->call('simpanDraft')->assertHasNoErrors();
        $this->artisan('bukti:sinkron-rtl')->assertSuccessful();
        $this->assertSame(1, $this->drive->jumlahSalin);
        $this->assertSame(1, SalinanBuktiRtl::count());
        $this->assertSame(1, Berkas::count());

        $this->form()->assertSee('Tersalin juga di folder Evaluasi-RTL / Rapat koordinasi mitra');
    }

    /** Uji 3 — subfolder per bulan (kedua urutan) & tanpa subfolder per kegiatan. */
    public function test_salinan_mengikuti_pengaturan_bulan_dan_kegiatan(): void
    {
        $this->aturKategori('Evaluasi-RTL', ['subfolder_per_kegiatan' => true, 'subfolder_per_bulan' => true, 'urutan_bulan_kegiatan' => 'bulan_dulu']);
        $this->kegiatanTertaut(['a.pdf']);
        $this->assertContains(self::AKAR.'/Evaluasi-RTL/Agustus/Rapat koordinasi mitra/a.pdf', $this->drive->semuaBerkas());

        $this->aturKategori('Evaluasi-RTL', ['urutan_bulan_kegiatan' => 'kegiatan_dulu']);
        $this->kegiatanTertaut(['b.pdf'], $this->poinLain);
        $this->assertContains(self::AKAR.'/Evaluasi-RTL/Pelatihan petugas lapangan/Agustus/b.pdf', $this->drive->semuaBerkas());
    }

    public function test_tanpa_subfolder_per_kegiatan_salinan_langsung_di_kategori(): void
    {
        $this->aturKategori('Evaluasi-RTL', ['subfolder_per_kegiatan' => false, 'subfolder_per_bulan' => false]);
        $this->kegiatanTertaut(['a.pdf']);

        $this->assertContains(self::AKAR.'/Evaluasi-RTL/a.pdf', $this->drive->semuaBerkas());
    }

    /** Uji 4, 5 — beberapa tahapan & subfolder bukti: susunannya ikut ke Evaluasi-RTL. */
    public function test_tahapan_dan_subfolder_kegiatan_ikut_tersalin(): void
    {
        $test = $this->form()
            ->call('pilihRtlUntukBlock', 0, $this->poin->id)
            ->set('blocks.0.jenis', 'survei_sensus')
            ->call('toggleTahapan', 0, 'persiapan')
            ->call('aturSusunanFolder', 0, true, 'persiapan')
            ->call('tambahSubfolder', 0, 'Undangan', 'persiapan');

        $test->set('unggahFolder.0.0.1', [$this->pdf('undangan.pdf')])->assertHasNoErrors();
        $test->set('unggahFolder.0.0.0', [$this->pdf('laporan.pdf')])->assertHasNoErrors();

        $this->assertSame([
            self::AKAR.'/Capaian/Agustus/Rapat koordinasi mitra/Persiapan/Undangan/undangan.pdf',
            self::AKAR.'/Capaian/Agustus/Rapat koordinasi mitra/Persiapan/laporan.pdf',
            self::AKAR.'/Evaluasi-RTL/Rapat koordinasi mitra/Persiapan/Undangan/undangan.pdf',
            self::AKAR.'/Evaluasi-RTL/Rapat koordinasi mitra/Persiapan/laporan.pdf',
        ], $this->drive->semuaBerkas());
    }

    /** Folder kegiatan bertahapan diganti nama → salinan pindah ke folder tahapan bernama sama. */
    public function test_ganti_nama_folder_tahapan_salinan_ikut_pindah(): void
    {
        $this->form()
            ->call('pilihRtlUntukBlock', 0, $this->poin->id)
            ->set('blocks.0.jenis', 'survei_sensus')
            ->call('toggleTahapan', 0, 'persiapan')
            ->set('unggahFolder.0.0.0', [$this->pdf('undangan.pdf')])
            ->assertHasNoErrors();

        $kegiatan = Kegiatan::sole();
        $this->form()->call('gantiNamaFolderKegiatan', $kegiatan->id, 'Persiapan Rakor');

        $this->assertSame([
            self::AKAR.'/Capaian/Agustus/Rapat koordinasi mitra/Persiapan Rakor/undangan.pdf',
            self::AKAR.'/Evaluasi-RTL/Rapat koordinasi mitra/Persiapan Rakor/undangan.pdf',
        ], $this->drive->semuaBerkas());
        $this->assertSame(1, $this->drive->jumlahSalin);
    }

    /** Nama folder tahapan salinan mengikuti nama folder asli di Drive, bukan hitungan ulang. */
    public function test_folder_tahapan_salinan_mengikuti_nama_folder_asli_di_drive(): void
    {
        $this->drive->gagalSalin = true;
        $this->form()
            ->call('pilihRtlUntukBlock', 0, $this->poin->id)
            ->set('blocks.0.jenis', 'survei_sensus')
            ->call('toggleTahapan', 0, 'persiapan')
            ->set('unggahFolder.0.0.0', [$this->pdf('undangan.pdf')])
            ->assertHasNoErrors();

        // Folder asli sempat diganti nama langsung di Drive (mis. sebelum fitur ini ada).
        $this->drive->renameFile(Kegiatan::sole()->drive_folder_id, 'Tahap Awal');
        $this->drive->gagalSalin = false;
        $this->artisan('bukti:sinkron-rtl')->assertSuccessful();

        $this->assertContains(self::AKAR.'/Evaluasi-RTL/Rapat koordinasi mitra/Tahap Awal/undangan.pdf', $this->drive->semuaBerkas());
    }

    /** Folder bulan: unggah mandiri = bulan isian, salinan = bulan kegiatan; bukti lama tetap periode poin. */
    public function test_folder_bulan_unggah_mandiri_dan_salinan_konsisten(): void
    {
        $this->aturKategori('Evaluasi-RTL', ['subfolder_per_kegiatan' => true, 'subfolder_per_bulan' => true, 'urutan_bulan_kegiatan' => 'bulan_dulu']);

        $this->kegiatanTertaut(['kegiatan.pdf']);
        $this->form()->set("unggahRtl.{$this->poinLain->id}.0.0", [$this->pdf('mandiri.pdf')])->assertHasNoErrors();

        $this->assertContains(self::AKAR.'/Evaluasi-RTL/Agustus/Rapat koordinasi mitra/kegiatan.pdf', $this->drive->semuaBerkas());
        $this->assertContains(self::AKAR.'/Evaluasi-RTL/Agustus/Pelatihan petugas lapangan/mandiri.pdf', $this->drive->semuaBerkas());
        $this->assertSame($this->agustus->id, $this->poinLain->berkas()->sole()->periode_id);

        // Bukti lama tanpa periode_id yang dicoba ulang tetap ke periode poin (Juli) — perilaku lama.
        $lokal = 'bukti-evaluasi-rtl/lama.pdf';
        Storage::disk('local')->put($lokal, '%PDF lama');
        $lama = Berkas::create(['ref_id' => $this->poinLain->id, 'ref_type' => RtlEvaluasi::class, 'kategori' => 'evaluasi_rtl', 'nama_file' => 'lama.pdf', 'path' => $lokal, 'status_verifikasi' => 'menunggu']);
        $this->artisan('berkas:sinkron-drive')->assertSuccessful();

        $this->assertNotNull($lama->fresh()->drive_file_id);
        $this->assertContains(self::AKAR.'/Evaluasi-RTL/Juli/Pelatihan petugas lapangan/lama.pdf', $this->drive->semuaBerkas());
    }

    /** Berkas pengganti unggahan mandiri tetap di folder tahapan/subfolder yang sama. */
    public function test_berkas_pengganti_rtl_mandiri_tetap_di_folder_tahapan(): void
    {
        $this->form()
            ->call('aturTahapanRtl', $this->poinLain->id, true)
            ->call('tambahTahapanRtl', $this->poinLain->id, 'Tahapan 1')
            ->set("unggahRtl.{$this->poinLain->id}.0.0", [$this->pdf('salah.pdf')])
            ->assertHasNoErrors();

        $lama = $this->poinLain->berkas()->sole();
        $lama->update(['status_verifikasi' => 'ditolak', 'catatan' => 'Salah']);
        $this->capaianAgustus(Capaian::STATUS_DIKEMBALIKAN);

        $this->form()->set("gantiBerkas.{$lama->id}", [$this->pdf('benar.pdf')])->assertHasNoErrors();

        $baru = $this->poinLain->berkas()->sole();
        $this->assertSame('Tahapan 1', $baru->tahapan);
        $this->assertSame([self::AKAR.'/Evaluasi-RTL/Pelatihan petugas lapangan/Tahapan 1/benar.pdf'], $this->drive->semuaBerkas());
    }

    /** Uji 6 — unggah mandiri Evaluasi RTL dengan tahapan & subfolder. */
    public function test_unggah_mandiri_rtl_memakai_pembentukan_folder_yang_sama(): void
    {
        $test = $this->form()
            ->call('aturTahapanRtl', $this->poinLain->id, true)
            ->call('tambahTahapanRtl', $this->poinLain->id, 'Tahapan 1')
            ->call('tambahTahapanRtl', $this->poinLain->id, 'Tahapan 2')
            ->call('aturSusunanFolderRtl', $this->poinLain->id, true, 'Tahapan 1')
            ->call('tambahSubfolderRtl', $this->poinLain->id, 'Undangan', 'Tahapan 1')
            ->assertSeeHtml('wire:model="unggahRtl.'.$this->poinLain->id.'.0.1"')
            ->assertSeeHtml('wire:model="unggahRtl.'.$this->poinLain->id.'.1.0"');

        $test->set("unggahRtl.{$this->poinLain->id}.0.1", [$this->pdf('undangan.pdf')])->assertHasNoErrors();
        $test->set("unggahRtl.{$this->poinLain->id}.1.0", [$this->pdf('laporan.pdf')])->assertHasNoErrors();

        $this->assertSame([
            self::AKAR.'/Evaluasi-RTL/Pelatihan petugas lapangan/Tahapan 1/Undangan/undangan.pdf',
            self::AKAR.'/Evaluasi-RTL/Pelatihan petugas lapangan/Tahapan 2/laporan.pdf',
        ], $this->drive->semuaBerkas());

        $berkas = $this->poinLain->berkas()->orderBy('id')->get();
        $this->assertSame(['Tahapan 1', 'Tahapan 2'], $berkas->pluck('tahapan')->all());
        $this->assertSame(['Undangan', null], $berkas->pluck('subfolder')->all());
        $this->assertSame(0, SalinanBuktiRtl::count());

        // Dibuka ulang: susunan folder terbaca kembali dari bukti tersimpan.
        $this->form()
            ->assertSet("evaluasi.{$this->poinLain->id}.pisah_tahapan", true)
            ->assertSet("evaluasi.{$this->poinLain->id}.tahapan", ['Tahapan 1', 'Tahapan 2'])
            ->assertSet("evaluasi.{$this->poinLain->id}.subfolders_tahapan.Tahapan 1", ['Undangan']);
    }

    public function test_unggah_mandiri_tanpa_subfolder_per_kegiatan_tidak_memaksakan_tahapan(): void
    {
        $this->aturKategori('Evaluasi-RTL', ['subfolder_per_kegiatan' => false]);

        $this->form()
            ->assertSee('Pemisahan tahapan')
            ->assertDontSeeHtml('aturTahapanRtl(')
            ->set("unggahRtl.{$this->poinLain->id}.0.0", [$this->pdf('realisasi.pdf')])
            ->assertHasNoErrors();

        $this->assertSame([self::AKAR.'/Evaluasi-RTL/realisasi.pdf'], $this->drive->semuaBerkas());
    }

    /** Uji 7 — poin yang dilaksanakan kegiatan tidak menawarkan unggah ulang. */
    public function test_poin_tertaut_kegiatan_tidak_meminta_unggahan_ulang(): void
    {
        $this->kegiatanTertaut(['undangan.pdf']);

        $this->form()
            ->assertSee('Dari Bukti Capaian Kegiatan (Bagian 2)')
            ->assertDontSeeHtml('wire:model="unggahRtl.'.$this->poin->id.'.0.0"')
            ->assertSeeHtml('wire:model="unggahRtl.'.$this->poinLain->id.'.0.0"')
            ->call('aturTahapanRtl', $this->poin->id, true)
            ->assertSet("evaluasi.{$this->poin->id}.pisah_tahapan", false);
    }

    /** Uji 8 — kegiatan yang tidak tertaut RTL tidak disalin. */
    public function test_kegiatan_tidak_tertaut_tidak_disalin(): void
    {
        $this->form()
            ->set('blocks.0.uraian_kegiatan', 'Rapat koordinasi mitra')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [$this->pdf('rapat.pdf')])
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $this->assertSame(0, SalinanBuktiRtl::count());
        $this->assertSame(0, $this->drive->jumlahSalin);
        $this->assertSame([self::AKAR.'/Capaian/Agustus/Rapat koordinasi mitra/rapat.pdf'], $this->drive->semuaBerkas());
    }

    /** Tautan RTL dipindah: salinan lama ke Sampah, salinan baru di poin yang benar. */
    public function test_tautan_rtl_diganti_salinan_pindah_ke_poin_baru(): void
    {
        $this->kegiatanTertaut(['undangan.pdf']);

        $this->form()->call('pilihRtlUntukBlock', 0, $this->poinLain->id)->call('simpanDraft')->assertHasNoErrors();

        $salinan = SalinanBuktiRtl::sole();
        $this->assertSame($this->poinLain->id, $salinan->rtl_evaluasi_id);
        $this->assertContains(self::AKAR.'/Evaluasi-RTL/Pelatihan petugas lapangan/undangan.pdf', $this->drive->semuaBerkas());
        $this->assertSame([self::AKAR.'/Evaluasi-RTL/Rapat koordinasi mitra/undangan.pdf'], $this->drive->berkasDiSampah());
    }

    /** Uji 15 — salin gagal: sumber tetap tersimpan, dicoba lagi tanpa dobel. */
    public function test_salin_gagal_dicoba_lagi_tanpa_duplikat(): void
    {
        $this->drive->gagalSalin = true;
        $this->kegiatanTertaut(['undangan.pdf']);

        $salinan = SalinanBuktiRtl::sole();
        $this->assertSame(SalinanBuktiRtl::STATUS_GAGAL, $salinan->status);
        $this->assertSame(1, $salinan->percobaan);
        $this->assertNotNull(Berkas::sole()->drive_file_id);
        $this->form()->assertSee('Belum tersalin ke folder Evaluasi-RTL');

        // Masih gagal lewat tombol Coba lagi → tetap satu baris, alasan tampil.
        $this->form()->call('cobaLagiSalinanRtl', Berkas::sole()->id)->assertDispatched('notify', type: 'error');

        $this->drive->gagalSalin = false;
        $this->artisan('bukti:sinkron-rtl')->assertSuccessful();

        $this->assertSame(SalinanBuktiRtl::STATUS_TERSALIN, $salinan->fresh()->status);
        $this->assertSame(1, $this->drive->jumlahSalin);

        // Salinan sudah ada di Drive tapi catatannya hilang (DB gagal setelah Drive
        // sukses): percobaan ulang memakai salinan berpenanda itu, tidak menyalin lagi.
        $salinan->update(['status' => SalinanBuktiRtl::STATUS_GAGAL, 'drive_file_id' => null, 'sumber_drive_file_id' => null]);
        $this->artisan('bukti:sinkron-rtl')->assertSuccessful();

        $this->assertSame(SalinanBuktiRtl::STATUS_TERSALIN, $salinan->fresh()->status);
        $this->assertSame(1, $this->drive->jumlahSalin);
        $this->assertCount(2, $this->drive->semuaBerkas());
    }

    /** Uji 12 — hapus bukti menghapus berkas sumber & salinannya; Drive gagal = tidak ada yang terhapus. */
    public function test_hapus_bukti_menghapus_kedua_lokasi(): void
    {
        $this->kegiatanTertaut(['salah.pdf', 'benar.pdf']);
        [$salah] = Berkas::orderBy('id')->get()->all();

        $this->drive->gagalHapus = true;
        $this->form()->call('hapusBuktiLama', 0, $salah->id)->assertDispatched('notify', type: 'error');
        $this->assertModelExists($salah);
        $this->assertSame(2, SalinanBuktiRtl::count());
        $this->assertCount(4, $this->drive->semuaBerkas());

        $this->drive->gagalHapus = false;
        $this->form()->call('hapusBuktiLama', 0, $salah->id);

        $this->assertModelMissing($salah);
        $this->assertSame(1, SalinanBuktiRtl::count());
        $this->assertSame([
            self::AKAR.'/Capaian/Agustus/Rapat koordinasi mitra/benar.pdf',
            self::AKAR.'/Evaluasi-RTL/Rapat koordinasi mitra/benar.pdf',
        ], $this->drive->semuaBerkas());
    }

    /** Proses lain sedang menyalin baris yang sama → proses ini mundur, tidak menyalin dobel. */
    public function test_salinan_yang_sedang_diproses_lain_tidak_disalin_dobel(): void
    {
        $this->drive->gagalSalin = true;
        $this->kegiatanTertaut(['undangan.pdf']);
        $this->drive->gagalSalin = false;
        $salinan = SalinanBuktiRtl::sole();

        $kunci = Cache::lock("salinan-bukti-rtl:{$salinan->id}", 120);
        $this->assertTrue($kunci->get());

        $this->artisan('bukti:sinkron-rtl')->assertSuccessful();
        $this->assertSame(0, $this->drive->jumlahSalin);
        $this->assertSame(SalinanBuktiRtl::STATUS_GAGAL, $salinan->fresh()->status);

        $kunci->release();
        $this->artisan('bukti:sinkron-rtl')->assertSuccessful();
        $this->assertSame(1, $this->drive->jumlahSalin);
    }

    /** Salinan terbuang tapi sumber gagal dihapus → salinan dibuat ulang, tidak hilang selamanya. */
    public function test_hapus_sumber_gagal_setelah_salinan_terbuang_salinan_dibuat_ulang(): void
    {
        $this->kegiatanTertaut(['bukti.pdf']);
        $sumber = Berkas::sole();

        // Drive gagal tepat saat berkas SUMBER dibuang (salinannya sudah terhapus).
        $drive = $this->drive;
        $idSumber = $sumber->drive_file_id;
        $this->app->instance(GoogleDriveService::class, $palsu = new class($drive, $idSumber) extends DrivePalsu
        {
            public function __construct(DrivePalsu $asal, protected string $idSumber)
            {
                $this->item = $asal->item;
                $this->jumlahSalin = $asal->jumlahSalin;
            }

            public function deleteFile(string $fileId, ?StorageAccount $akun = null): void
            {
                if ($fileId === $this->idSumber) {
                    throw new \RuntimeException('Drive sedang tidak bisa dihubungi');
                }

                parent::deleteFile($fileId, $akun);
            }
        });

        $this->form()->call('hapusBuktiLama', 0, $sumber->id)->assertDispatched('notify', type: 'error');

        $this->assertModelExists($sumber);
        $this->assertSame(SalinanBuktiRtl::STATUS_MENUNGGU, SalinanBuktiRtl::sole()->status);
        $this->assertSame([self::AKAR.'/Capaian/Agustus/Rapat koordinasi mitra/bukti.pdf'], $palsu->semuaBerkas());

        $this->artisan('bukti:sinkron-rtl')->assertSuccessful();

        $this->assertSame(SalinanBuktiRtl::STATUS_TERSALIN, SalinanBuktiRtl::sole()->status);
        $this->assertContains(self::AKAR.'/Evaluasi-RTL/Rapat koordinasi mitra/bukti.pdf', $palsu->semuaBerkas());
    }

    /** Salinan basi yang gagal dibuang saat tautan diganti dibersihkan oleh perintah terjadwal. */
    public function test_salinan_basi_dibersihkan_perintah_terjadwal(): void
    {
        $this->kegiatanTertaut(['undangan.pdf']);
        $salinanLama = SalinanBuktiRtl::sole();

        $this->drive->gagalHapus = true;
        $this->form()->call('pilihRtlUntukBlock', 0, $this->poinLain->id)->call('simpanDraft')->assertHasNoErrors();
        $this->assertModelExists($salinanLama);

        $this->drive->gagalHapus = false;
        $this->artisan('bukti:sinkron-rtl')->assertSuccessful();

        $this->assertModelMissing($salinanLama);
        $this->assertSame([$this->poinLain->id], SalinanBuktiRtl::pluck('rtl_evaluasi_id')->all());
        $this->assertSame([self::AKAR.'/Evaluasi-RTL/Rapat koordinasi mitra/undangan.pdf'], $this->drive->berkasDiSampah());
    }

    /** Ganti nama di Kegiatan → salinan ikut bernama sama. */
    public function test_ganti_nama_bukti_ikut_ke_salinan(): void
    {
        $this->kegiatanTertaut(['undangan.pdf']);
        $berkas = Berkas::sole();

        $berkas->gantiNama('Undangan Rapat');

        $this->assertSame('Undangan Rapat.pdf', $this->drive->item[SalinanBuktiRtl::sole()->drive_file_id]['nama']);
    }

    /** Uji 9, 10, 11 — status bukti RTL mengikuti satu sumber: berkas kegiatan. */
    public function test_verifikasi_bukti_kegiatan_berlaku_di_evaluasi_rtl(): void
    {
        $this->kegiatanTertaut(['undangan.pdf']);
        Kegiatan::query()->update(['status_dokumen' => Kegiatan::STATUS_DIAJUKAN]);
        $capaian = $this->capaianAgustus(Capaian::STATUS_DIAJUKAN);
        $berkas = Berkas::sole();

        $this->actingAs($this->sakip());
        $halaman = Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian])
            ->assertSee('Dari Bukti Kegiatan')
            ->assertSee('Ada bukti belum diperiksa')
            ->assertSee('… Belum diperiksa');

        $halaman->call('tandaiSesuai', $berkas->id)
            ->assertSee('Semua bukti Sesuai')
            ->assertSee('Status bukti: ✓ Sesuai');

        $this->assertSame(RtlEvaluasi::KESESUAIAN_SESUAI, RtlEvaluasi::kesesuaianBukti($this->poin->buktiKegiatan()->get()));
        // Bukti Sesuai TIDAK membuat realisasi RTL otomatis Sesuai.
        $this->assertSame('menunggu', $this->poin->fresh()->status_verifikasi);

        $halaman->set("catatanBerkas.{$berkas->id}", 'Tanggal tidak terbaca')
            ->call('tandaiTolak', $berkas->id)
            ->assertSee('Ada bukti Tidak Sesuai')
            ->assertDontSee('Semua bukti Sesuai');

        $riwayat = RiwayatVerifikasiBerkas::orderBy('id')->get();
        $this->assertSame(['terverifikasi', 'ditolak'], $riwayat->pluck('status_baru')->all());
        $this->assertSame([$berkas->id, $berkas->id], $riwayat->pluck('berkas_id')->all());
        $this->assertSame(1, Berkas::count());
    }

    /** Uji 12 — berkas pengganti: status ditinjau ulang, salinan lama diganti. */
    public function test_berkas_pengganti_membuat_status_ditinjau_ulang_dan_salinan_baru(): void
    {
        $this->kegiatanTertaut(['salah.pdf']);
        $lama = Berkas::sole();
        $lama->update(['status_verifikasi' => 'ditolak', 'catatan' => 'Salah berkas']);
        $this->capaianAgustus(Capaian::STATUS_DIKEMBALIKAN);
        Kegiatan::query()->update(['status_dokumen' => Kegiatan::STATUS_DIKEMBALIKAN]);

        $this->form()->set("gantiBerkas.{$lama->id}", [$this->pdf('benar.pdf')])->assertHasNoErrors();

        $baru = Berkas::sole();
        $this->assertNotSame($lama->id, $baru->id);
        $this->assertSame('menunggu', $baru->status_verifikasi);
        $this->assertSame(RtlEvaluasi::KESESUAIAN_MENUNGGU, RtlEvaluasi::kesesuaianBukti($this->poin->buktiKegiatan()->get()));
        $this->assertSame($baru->id, SalinanBuktiRtl::sole()->berkas_id);
        $this->assertSame([
            self::AKAR.'/Capaian/Agustus/Rapat koordinasi mitra/benar.pdf',
            self::AKAR.'/Evaluasi-RTL/Rapat koordinasi mitra/benar.pdf',
        ], $this->drive->semuaBerkas());
    }

    /** Uji 13, 14, 17 — bukti bulan lain: status historis dipakai apa adanya, tidak bisa diubah dari bulan ini. */
    public function test_bukti_bulan_sebelumnya_hanya_lihat_dan_status_historis_tetap(): void
    {
        $this->kegiatanTertaut(['juli.pdf'], bulan: 7);
        $juli = Berkas::sole();
        $juli->update(['status_verifikasi' => 'terverifikasi']);
        Kegiatan::query()->update(['status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);

        $kegiatanAgustus = Kegiatan::create([
            'iku_id' => $this->iku->id, 'periode_id' => $this->agustus->id, 'uraian_kegiatan' => 'Kegiatan Agustus',
            'jenis' => 'bukan_survei_sensus', 'status_dokumen' => Kegiatan::STATUS_DIAJUKAN,
        ]);
        $capaian = $this->capaianAgustus(Capaian::STATUS_DIAJUKAN);
        $sebelum = $juli->fresh()->only(['status_verifikasi', 'catatan', 'nama_file', 'drive_file_id']);

        $this->actingAs($this->sakip());
        $halaman = Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian])
            ->assertSee('juli.pdf')
            ->assertSee('isian bulan Juli')
            ->assertSee('Semua bukti Sesuai')
            ->assertSee('diperiksa pada verifikasi isian bulan itu');

        $this->assertFalse($halaman->instance()->berkasBisaDiverifikasi($juli->id));

        $halaman->set("catatanBerkas.{$juli->id}", 'coba ubah')->call('tandaiTolak', $juli->id);

        $this->assertSame($sebelum, $juli->fresh()->only(['status_verifikasi', 'catatan', 'nama_file', 'drive_file_id']));
        $this->assertSame(0, RiwayatVerifikasiBerkas::count());
        $this->assertModelExists($kegiatanAgustus);
    }

    /** Uji 17 — Ketua Tim tidak bisa membuka verifikasi & tidak bisa menghapus bukti IKU lain. */
    public function test_tanpa_kewenangan_tidak_bisa_memverifikasi_atau_menghapus(): void
    {
        $this->kegiatanTertaut(['undangan.pdf']);
        $capaian = $this->capaianAgustus(Capaian::STATUS_DIAJUKAN);

        $this->get(route('verifikasi.show', $capaian))->assertForbidden();

        $ikuLain = MasterIku::create(['kode' => 'UJI-LAIN', 'indikator' => 'Lain', 'tim' => 'Lain', 'penanggung_jawab' => 'Lain']);
        $rtlLain = RtlEvaluasi::create(['iku_id' => $ikuLain->id, 'periode_id' => $this->poin->periode_id, 'rtl_teks' => 'RTL lain', 'pic' => 'x', 'batas_waktu' => '2026-09-30']);
        $berkasLain = Berkas::create(['ref_id' => $rtlLain->id, 'ref_type' => RtlEvaluasi::class, 'kategori' => 'evaluasi_rtl', 'nama_file' => 'lain.pdf', 'status_verifikasi' => 'ditolak']);

        $this->form()
            ->call('hapusBuktiLamaEvaluasi', $rtlLain->id, $berkasLain->id)
            ->call('cobaLagiSalinanRtl', $berkasLain->id);

        $this->assertModelExists($berkasLain);
    }

    /** Bukti kegiatan draf belum terlihat oleh Tim SAKIP di Evaluasi RTL. */
    public function test_bukti_kegiatan_draf_tidak_tampil_di_verifikasi_rtl(): void
    {
        $this->kegiatanTertaut(['draf.pdf']);
        $capaian = $this->capaianAgustus(Capaian::STATUS_DIAJUKAN);

        $this->actingAs($this->sakip());
        Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian])->assertDontSee('draf.pdf');
    }

    /** Bukti lama (sebelum fitur salinan) hanya didaftarkan lewat perintah eksplisit. */
    public function test_bukti_lama_tidak_disalin_massal_tanpa_perintah(): void
    {
        $kegiatan = Kegiatan::create([
            'iku_id' => $this->iku->id, 'rtl_evaluasi_id' => $this->poin->id, 'periode_id' => $this->agustus->id,
            'uraian_kegiatan' => 'Rapat koordinasi mitra', 'jenis' => 'bukan_survei_sensus', 'nama_folder_auto' => 'Rapat koordinasi mitra',
            'status_dokumen' => Kegiatan::STATUS_DISETUJUI,
        ]);
        $lokal = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($lokal, '%PDF lama');
        $idLama = $this->drive->uploadFile($lokal, 'lama.pdf', $this->drive->findOrCreateFolder('Lama', 'root'))['id'];
        $lama = Berkas::create(['ref_id' => $kegiatan->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian', 'nama_file' => 'lama.pdf', 'drive_file_id' => $idLama, 'status_verifikasi' => 'terverifikasi']);

        $this->artisan('bukti:sinkron-rtl')->assertSuccessful();

        // Pratinjau: hanya menghitung — Drive & database tidak berubah sama sekali.
        $driveSebelum = $this->drive->item;
        $berkasSebelum = Berkas::orderBy('id')->get()->toArray();
        $this->artisan('bukti:sinkron-rtl --bukti-lama')->expectsOutputToContain('Pratinjau: 1')->assertSuccessful();
        $this->assertSame(0, SalinanBuktiRtl::count());
        $this->assertSame($driveSebelum, $this->drive->item);
        $this->assertSame(0, $this->drive->jumlahSalin);
        $this->assertSame($berkasSebelum, Berkas::orderBy('id')->get()->toArray());

        $this->artisan('bukti:sinkron-rtl --bukti-lama --jalankan')->assertSuccessful();

        $this->assertSame(SalinanBuktiRtl::STATUS_TERSALIN, SalinanBuktiRtl::sole()->status);
        $this->assertContains(self::AKAR.'/Evaluasi-RTL/Rapat koordinasi mitra/lama.pdf', $this->drive->semuaBerkas());
        // Data historis tidak berubah.
        $this->assertSame('terverifikasi', $lama->fresh()->status_verifikasi);
        $this->assertSame(Kegiatan::STATUS_DISETUJUI, $kegiatan->fresh()->status_dokumen);
    }
}
