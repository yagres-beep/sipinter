<?php

namespace Tests\Feature;

use App\Livewire\PengisianKegiatan;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\LogAktivitas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Services\FolderStructureService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\PenjagaDatabaseUji;
use Tests\TestCase;

/**
 * Google Drive & bukti dukung: unggahan baru dijalankan SETELAH transaksi database
 * berhasil, percobaan ulang tidak menggandakan berkas, penggantian/hapus tidak
 * meninggalkan keadaan setengah jadi, dan pemeriksa konsistensi benar-benar baca-saja.
 */
class DriveKonsistensiBuktiTest extends TestCase
{
    use PenjagaDatabaseUji;
    use RefreshDatabase;

    protected MasterIku $iku;

    protected Periode $periode;

    protected BagianKustom $bagian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pastikanDatabaseUji();
        Storage::fake('local');

        foreach (['Ketua Tim', 'Tim SAKIP', 'Kepala'] as $nama) {
            Role::firstOrCreate(['nama' => $nama]);
        }

        $this->iku = MasterIku::create(['kode' => 'DRV-1', 'indikator' => 'Indikator', 'tim' => 'Tim', 'penanggung_jawab' => 'A']);
        $this->periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
        $this->bagian = BagianKustom::create(['nama' => 'Manajemen Risiko', 'aktif' => true, 'bukti_wajib' => false, 'peran' => ['ketua_tim'], 'penempatan' => ['pengisian'], 'kolom' => []]);

        $this->actingAs(User::create([
            'nama' => 'Ketua', 'username' => 'ketua', 'email' => 'ketua@example.test', 'password' => 'password',
            'role_id' => Role::where('nama', 'Ketua Tim')->value('id'), 'status_verifikasi' => 'terverifikasi',
        ]));
    }

    protected function layarDenganBukti()
    {
        return Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)->set('bulan', 4)->set('iku_id', $this->iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Rapat')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('undangan.pdf', 10, 'application/pdf')])
            ->set("bagianKustomBlocks.{$this->bagian->id}.0.teks", 'Risiko data')
            ->set("bagianKustomBlocks.{$this->bagian->id}.0.bukti", [UploadedFile::fake()->create('register.pdf', 10, 'application/pdf')]);
    }

    protected function semuaBerkasLokal(): array
    {
        return array_merge(...array_map(fn ($d) => Storage::disk('local')->allFiles($d), ['bukti-capaian', 'bukti-evaluasi-rtl', 'bukti-bagian-kustom']));
    }

    /* ---------- Unggah: transaksi gagal / Drive gagal / pencatatan hasil gagal ---------- */

    public function test_transaksi_gagal_tidak_mengunggah_ke_drive_dan_tidak_meninggalkan_berkas_lokal(): void
    {
        $this->mock(FolderStructureService::class, function ($m) {
            $m->shouldNotReceive('unggahBerkas');
            $m->shouldNotReceive('unggahBerkasKegiatan');
            $m->shouldNotReceive('unggahBerkasBagianKustom');
        });
        Capaian::created(fn () => throw new RuntimeException('Galat database tiruan'));

        try {
            $this->layarDenganBukti()->call('simpanDraft');
            $this->fail('Galat tiruan seharusnya muncul.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Berkas::count());
        $this->assertSame(0, Kegiatan::count());
        $this->assertSame(0, LogAktivitas::count());
        $this->assertSame([], $this->semuaBerkasLokal());
    }

    public function test_unggahan_drive_baru_berjalan_setelah_data_tersimpan(): void
    {
        $saatUnggah = [];
        $this->mock(FolderStructureService::class, function ($m) use (&$saatUnggah) {
            $catat = function (...$arg) use (&$saatUnggah) {
                // Saat Drive dipanggil, transaksi sudah selesai dan barisnya sudah ada.
                $saatUnggah[] = [DB::transactionLevel(), Berkas::count()];

                return ['drive_file_id' => 'drive-'.count($saatUnggah), 'storage_account_id' => null];
            };
            $m->shouldReceive('unggahBerkasKegiatan')->andReturnUsing($catat);
            $m->shouldReceive('unggahBerkasBagianKustom')->andReturnUsing($catat);
        });

        $this->layarDenganBukti()->call('simpanDraft')->assertHasNoErrors()->assertRedirect();

        $levelDasar = DB::transactionLevel();
        $this->assertCount(2, $saatUnggah);
        foreach ($saatUnggah as [$level, $jumlah]) {
            $this->assertSame($levelDasar, $level, 'unggah tidak boleh di dalam transaksi simpan');
            $this->assertSame(2, $jumlah);
        }
        $this->assertSame(0, Berkas::whereNull('drive_file_id')->count());
        $this->assertSame(1, LogAktivitas::where('aksi', 'bukti.tersinkron')->count());
    }

    public function test_drive_gagal_berkas_aman_lokal_lalu_disusulkan_tanpa_dobel(): void
    {
        $this->mock(FolderStructureService::class, function ($m) {
            $m->shouldReceive('unggahBerkasKegiatan', 'unggahBerkasBagianKustom')->andThrow(new RuntimeException('Drive mati'));
        });

        $this->layarDenganBukti()->call('simpanDraft')->assertHasNoErrors()->assertRedirect();

        // Unggahan pertama terjadi saat berkas dipilih (simpan otomatis, perilaku
        // produksi); "Simpan Draft" lalu mencoba ulang dan berhenti di kegagalan pertama.
        $this->assertSame(2, Berkas::whereNull('drive_file_id')->count());
        $this->assertCount(2, $this->semuaBerkasLokal());
        $this->assertNotEmpty(session('driveGagal'));

        $buktiKegiatan = Berkas::where('ref_type', Kegiatan::class)->sole();
        $buktiBagian = Berkas::where('ref_type', BagianKustomPoin::class)->sole();

        // Belum ada salinan bertanda di Drive → diunggah ulang SEKALI, membawa kode
        // berkasnya sebagai penanda (supaya percobaan berikutnya tidak menggandakan).
        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldReceive('cariBerkasBerpenanda')->twice()->andReturn(null);
        $this->mock(FolderStructureService::class, function ($m) use ($buktiKegiatan, $buktiBagian) {
            $m->shouldReceive('unggahBerkasKegiatan')->once()
                ->withArgs(fn (...$a) => in_array($buktiKegiatan->kode, $a, true))
                ->andReturn(['drive_file_id' => 'drive-keg', 'storage_account_id' => null, 'nama_file' => $buktiKegiatan->nama_file]);
            $m->shouldReceive('unggahBerkasBagianKustom')->once()
                ->withArgs(fn (...$a) => in_array($buktiBagian->kode, $a, true))
                ->andReturn(['drive_file_id' => 'drive-bag', 'storage_account_id' => null, 'nama_file' => $buktiBagian->nama_file]);
        });

        $this->artisan('berkas:sinkron-drive')->assertSuccessful();

        $this->assertSame(0, Berkas::whereNull('drive_file_id')->count());
        $log = LogAktivitas::where('aksi', 'bukti.tersinkron')->sole();
        $this->assertSame($buktiBagian->id, $log->objek_id);
        $this->assertStringContainsString('disusulkan otomatis', $log->keterangan);
    }

    public function test_drive_berhasil_tetapi_pencatatan_gagal_tidak_menggagalkan_simpan(): void
    {
        $this->mock(FolderStructureService::class, function ($m) {
            $m->shouldReceive('unggahBerkasKegiatan', 'unggahBerkasBagianKustom')->andReturn(['drive_file_id' => 'drive-x', 'storage_account_id' => null]);
        });
        Berkas::updating(function (Berkas $b) {
            if ($b->isDirty('drive_file_id')) {
                throw new RuntimeException('Gagal mencatat hasil Drive');
            }
        });

        $this->layarDenganBukti()->call('simpanDraft')->assertHasNoErrors()->assertRedirect();

        // Data isian tersimpan; berkas tetap aman lokal dan masuk antrean sinkron.
        $this->assertSame(2, Berkas::count());
        $this->assertSame(2, Berkas::belumDiDrive()->count());
        $this->assertSame(0, LogAktivitas::where('aksi', 'bukti.tersinkron')->count());
        $this->assertNotEmpty(session('driveGagal'));
    }

    public function test_percobaan_ulang_memakai_salinan_drive_bertanda_tanpa_mengunggah_lagi(): void
    {
        $poin = BagianKustomPoin::create(['bagian_kustom_id' => $this->bagian->id, 'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id, 'teks' => 'Poin', 'menu' => 'pengisian', 'peran_pengisi' => 'ketua_tim']);
        Storage::disk('local')->put('bukti-bagian-kustom/a.pdf', 'isi');
        $berkas = Berkas::create(['ref_id' => $poin->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom', 'nama_file' => 'a.pdf', 'path' => 'bukti-bagian-kustom/a.pdf', 'status_verifikasi' => 'menunggu']);

        // Unggahan sebelumnya sebenarnya SUDAH masuk Drive (bertanda kode berkas ini)
        // tetapi hasilnya tidak sempat tercatat: dipakai ulang, tidak diunggah lagi.
        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldReceive('cariBerkasBerpenanda')->once()->with(FolderStructureService::PENANDA_KODE, $berkas->kode)->andReturn(['id' => 'drive-ada', 'name' => 'Risiko.pdf', 'size_bytes' => 3]);
        $drive->shouldNotReceive('uploadFile');
        $this->mock(FolderStructureService::class, fn ($m) => $m->shouldNotReceive('unggahBerkasBagianKustom'));

        $this->assertTrue($berkas->sinkronUlangKeDrive());

        $berkas->refresh();
        $this->assertSame('drive-ada', $berkas->drive_file_id);
        $this->assertSame('Risiko.pdf', $berkas->nama_file);
        $this->assertSame(1, LogAktivitas::where('aksi', 'bukti.tersinkron')->where('objek_id', $berkas->id)->count());
    }

    public function test_unggahan_pertama_memasang_penanda_kode_dan_penanda_aplikasi(): void
    {
        \App\Models\StorageAccount::create(['email_gmail_institusi' => 'aktif@example.test', 'status' => \App\Models\StorageAccount::STATUS_AKTIF, 'drive_folder_id' => 'root']);
        Storage::disk('local')->put('bukti-bagian-kustom/a.pdf', 'isi');

        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldReceive('findOrCreateFolder')->andReturnUsing(fn ($nama, $induk) => "{$induk}/{$nama}");
        $drive->shouldReceive('foldersInFolder')->andReturn([]);
        $drive->shouldReceive('createFolder')->andReturnUsing(fn ($nama, $induk) => "{$induk}/{$nama}");
        $drive->shouldReceive('namesInFolder')->andReturn([]);
        $drive->shouldReceive('uploadFile')->once()
            ->withArgs(fn ($lokal, $nama, $folder, $mime, $penanda) => $penanda === [FolderStructureService::PENANDA_KODE => 'BKS-202610-AAAA-BBBB', GoogleDriveService::PENANDA_APLIKASI => '1'])
            ->andReturn(['id' => 'drive-baru', 'name' => 'a.pdf', 'web_view_link' => null, 'size_bytes' => 3]);

        $hasil = app(FolderStructureService::class)->unggahBerkas(
            $this->periode, $this->iku, 'bagian_kustom', Storage::disk('local')->path('bukti-bagian-kustom/a.pdf'),
            namaFolderOverride: 'Manajemen Risiko', kodeBerkas: 'BKS-202610-AAAA-BBBB',
        );

        $this->assertSame('drive-baru', $hasil['drive_file_id']);
    }

    /* ---------- Penggantian & penghapusan ---------- */

    protected function buktiDitolak(): array
    {
        $poin = BagianKustomPoin::create(['bagian_kustom_id' => $this->bagian->id, 'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id, 'teks' => 'Poin', 'menu' => 'pengisian', 'peran_pengisi' => 'ketua_tim', 'status_verifikasi' => 'ditolak']);
        Capaian::create(['iku_id' => $this->iku->id, 'periode_id' => $this->periode->id, 'status' => Capaian::STATUS_DIKEMBALIKAN]);
        Storage::disk('local')->put('bukti-bagian-kustom/lama.pdf', 'lama');
        $lama = Berkas::create(['ref_id' => $poin->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom', 'nama_file' => 'lama.pdf', 'path' => 'bukti-bagian-kustom/lama.pdf', 'drive_file_id' => 'drive-lama', 'status_verifikasi' => 'ditolak', 'catatan' => 'Buram']);

        return [$poin, $lama];
    }

    protected function layar()
    {
        return Livewire::test(PengisianKegiatan::class)->set('tahun', 2026)->set('bulan', 4)->set('iku_id', $this->iku->id);
    }

    public function test_log_penggantian_gagal_sebelum_drive_disentuh_bukti_lama_tetap_utuh(): void
    {
        [$poin, $lama] = $this->buktiDitolak();
        $this->mock(FolderStructureService::class, fn ($m) => $m->shouldReceive('unggahBerkasBagianKustom')->andReturn(['drive_file_id' => 'drive-baru', 'storage_account_id' => null]));
        // Drive bukti lama TIDAK boleh disentuh: pencatatan log gagal lebih dulu.
        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldNotReceive('deleteFile', 'trashFile');
        LogAktivitas::creating(fn (LogAktivitas $log) => $log->aksi === 'bukti.diganti' ? throw new RuntimeException('Log gagal') : null);

        try {
            $this->layar()->set("gantiBerkas.{$lama->id}", UploadedFile::fake()->create('baru.pdf', 10, 'application/pdf'));
            $this->fail('Galat tiruan seharusnya muncul.');
        } catch (RuntimeException) {
        }

        // Bukti lama utuh (baris, salinan Drive, berkas lokal); berkas pengganti tersimpan
        // di sampingnya — keadaan konsisten, tidak ada rujukan ke berkas yang sudah hilang.
        $this->assertSame('drive-lama', $lama->fresh()?->drive_file_id);
        Storage::disk('local')->assertExists('bukti-bagian-kustom/lama.pdf');
        $this->assertSame(2, Berkas::count());
        $this->assertNull($poin->fresh()->catatan_bukti_dihapus);
        $this->assertSame(0, LogAktivitas::where('aksi', 'bukti.diganti')->count());
    }

    public function test_penggantian_berhasil_mengunggah_pengganti_lalu_menghapus_permanen_bukti_lama(): void
    {
        [$poin, $lama] = $this->buktiDitolak();
        $saatUnggah = null;
        $this->mock(FolderStructureService::class, function ($m) use (&$saatUnggah) {
            $m->shouldReceive('unggahBerkasBagianKustom')->once()->andReturnUsing(function () use (&$saatUnggah) {
                // Perilaku produksi: pengganti diunggah dulu, bukti lama masih ada.
                $saatUnggah = [DB::transactionLevel(), Berkas::where('nama_file', 'lama.pdf')->count()];

                return ['drive_file_id' => 'drive-baru', 'storage_account_id' => null];
            });
        });
        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldReceive('deleteFile')->once()->with('drive-lama', \Mockery::any());

        $this->layar()->set("gantiBerkas.{$lama->id}", UploadedFile::fake()->create('baru.pdf', 10, 'application/pdf'))->assertHasNoErrors();

        $this->assertSame([DB::transactionLevel(), 1], $saatUnggah, 'pengganti diunggah di luar transaksi, sebelum bukti lama dihapus');
        $baru = Berkas::sole();
        $this->assertSame('drive-baru', $baru->drive_file_id);
        $this->assertSame('menunggu', $baru->status_verifikasi);
        $this->assertSame('Buram', $poin->fresh()->catatan_bukti_dihapus);
        $this->assertSame(['bukti.tersinkron', 'bukti.diganti'], LogAktivitas::orderBy('id')->pluck('aksi')->all());
        Storage::disk('local')->assertMissing('bukti-bagian-kustom/lama.pdf');
    }

    public function test_penggantian_saat_drive_lama_gagal_dihapus_bukti_lama_dibiarkan(): void
    {
        [, $lama] = $this->buktiDitolak();
        $this->mock(FolderStructureService::class, fn ($m) => $m->shouldReceive('unggahBerkasBagianKustom')->andReturn(['drive_file_id' => 'drive-baru', 'storage_account_id' => null]));
        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldReceive('deleteFile')->once()->andThrow(new RuntimeException('Drive mati'));

        $this->layar()->set("gantiBerkas.{$lama->id}", UploadedFile::fake()->create('baru.pdf', 10, 'application/pdf'));

        $this->assertNotNull($lama->fresh());
        Storage::disk('local')->assertExists('bukti-bagian-kustom/lama.pdf');
        $this->assertSame(2, Berkas::count());
        $this->assertSame(['bukti.tersinkron', 'bukti.diunggah'], LogAktivitas::orderBy('id')->pluck('aksi')->all());
    }

    public function test_penggantian_ganda_atas_berkas_yang_sama_hanya_berhasil_sekali(): void
    {
        [, $lama] = $this->buktiDitolak();
        $this->mock(FolderStructureService::class, fn ($m) => $m->shouldReceive('unggahBerkasBagianKustom')->andReturn(['drive_file_id' => 'd', 'storage_account_id' => null]));
        $this->mock(GoogleDriveService::class)->shouldReceive('deleteFile');
        $kedua = $this->layar();

        $this->layar()->set("gantiBerkas.{$lama->id}", UploadedFile::fake()->create('pertama.pdf', 10, 'application/pdf'))->assertHasNoErrors();

        // Layar kedua masih menampilkan berkas lama; penggantiannya diabaikan karena
        // berkas lama sudah tidak ada (tidak ada pengganti kedua / berkas ganda).
        $kedua->set("gantiBerkas.{$lama->id}", UploadedFile::fake()->create('kedua.pdf', 10, 'application/pdf'));

        $this->assertSame(['pertama.pdf'], Berkas::pluck('nama_file')->all());
    }

    public function test_hapus_bukti_saat_log_gagal_drive_tidak_disentuh_dan_bukti_utuh(): void
    {
        [$poin, $lama] = $this->buktiDitolak();
        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldNotReceive('deleteFile', 'trashFile');
        LogAktivitas::creating(fn (LogAktivitas $log) => $log->aksi === 'bukti.dihapus' ? throw new RuntimeException('Log gagal') : null);

        try {
            $this->layar()->call('hapusBuktiLamaBagianKustom', $poin->id, $lama->id);
            $this->fail('Galat tiruan seharusnya muncul.');
        } catch (RuntimeException) {
        }

        $this->assertSame('drive-lama', $lama->fresh()?->drive_file_id);
        $this->assertNull($poin->fresh()->catatan_bukti_dihapus);
        Storage::disk('local')->assertExists('bukti-bagian-kustom/lama.pdf');
    }

    public function test_hapus_bukti_saat_drive_gagal_bukti_dibiarkan_utuh(): void
    {
        [$poin, $lama] = $this->buktiDitolak();
        $this->mock(GoogleDriveService::class)->shouldReceive('deleteFile')->once()->andThrow(new RuntimeException('Drive mati'));

        $this->layar()->call('hapusBuktiLamaBagianKustom', $poin->id, $lama->id);

        $this->assertNotNull($lama->fresh());
        $this->assertNull($poin->fresh()->catatan_bukti_dihapus);
        Storage::disk('local')->assertExists('bukti-bagian-kustom/lama.pdf');
        $this->assertSame(0, LogAktivitas::where('aksi', 'bukti.dihapus')->count());
    }

    public function test_hapus_bukti_berhasil_menghapus_permanen_dari_drive_dan_mencatat_log(): void
    {
        [$poin, $lama] = $this->buktiDitolak();
        $this->mock(GoogleDriveService::class)->shouldReceive('deleteFile')->once()->with('drive-lama', \Mockery::any());

        $this->layar()->call('hapusBuktiLamaBagianKustom', $poin->id, $lama->id);

        $this->assertNull($lama->fresh());
        $this->assertSame('Buram', $poin->fresh()->catatan_bukti_dihapus);
        Storage::disk('local')->assertMissing('bukti-bagian-kustom/lama.pdf');
        $log = LogAktivitas::where('aksi', 'bukti.dihapus')->sole();
        $this->assertStringContainsString('ikut dihapus permanen', $log->keterangan);
    }

    public function test_hapus_bukti_yang_gagal_di_database_tidak_menghapus_berkas_lokal(): void
    {
        [$poin, $lama] = $this->buktiDitolak();
        $this->mock(GoogleDriveService::class)->shouldReceive('deleteFile');
        Berkas::deleting(fn () => throw new RuntimeException('Hapus gagal'));

        try {
            $this->layar()->call('hapusBuktiLamaBagianKustom', $poin->id, $lama->id);
            $this->fail('Galat tiruan seharusnya muncul.');
        } catch (RuntimeException) {
        }

        $this->assertNotNull($lama->fresh());
        Storage::disk('local')->assertExists('bukti-bagian-kustom/lama.pdf');
        $this->assertSame(0, LogAktivitas::where('aksi', 'bukti.dihapus')->count());
    }

    /* ---------- Pemeriksa konsistensi (baca-saja) & pembersih ---------- */

    protected function siapkanTemuan(): array
    {
        $kegiatan = Kegiatan::create(['iku_id' => $this->iku->id, 'periode_id' => $this->periode->id, 'uraian_kegiatan' => 'K', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => Kegiatan::STATUS_DRAFT]);

        Storage::disk('local')->put('bukti-capaian/menunggu.pdf', 'x');
        Storage::disk('local')->put('bukti-capaian/yatim-lama.pdf', 'x');
        Storage::disk('local')->put('bukti-capaian/yatim-baru.pdf', 'x');
        touch(Storage::disk('local')->path('bukti-capaian/yatim-lama.pdf'), time() - 3 * 86400);
        touch(Storage::disk('local')->path('bukti-capaian/menunggu.pdf'), time() - 3 * 86400);

        return [
            'hilang' => Berkas::create(['ref_id' => $kegiatan->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian', 'nama_file' => 'hilang.pdf', 'path' => 'bukti-capaian/hilang.pdf', 'status_verifikasi' => 'menunggu']),
            'menunggu' => Berkas::create(['ref_id' => $kegiatan->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian', 'nama_file' => 'menunggu.pdf', 'path' => 'bukti-capaian/menunggu.pdf', 'status_verifikasi' => 'menunggu']),
            'tanpa_pemilik' => Berkas::create(['ref_id' => 99999, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian', 'nama_file' => 'tanpa.pdf', 'drive_file_id' => 'drive-tanpa', 'status_verifikasi' => 'menunggu']),
            'di_drive' => Berkas::create(['ref_id' => $kegiatan->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian', 'nama_file' => 'ok.pdf', 'drive_file_id' => 'drive-ok', 'status_verifikasi' => 'menunggu']),
        ];
    }

    public function test_pemeriksa_konsistensi_melaporkan_temuan_tanpa_mengubah_apa_pun(): void
    {
        $temuan = $this->siapkanTemuan();
        $berkasSebelum = Berkas::orderBy('id')->get()->toArray();
        $lokalSebelum = $this->semuaBerkasLokal();
        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldReceive('infoBerkas')->andReturnUsing(fn ($id) => $id === 'drive-ok' ? ['id' => $id, 'name' => 'ok.pdf'] : null);
        $drive->shouldReceive('daftarBerkasBertanda')->andReturn([
            ['id' => 'drive-yatim', 'nama' => 'yatim.pdf', 'kode' => 'CPN-202610-ZZZZ-ZZZZ'],
        ]);
        $drive->shouldNotReceive('uploadFile', 'renameFile', 'createFolder');

        $tulis = [];
        DB::listen(function ($q) use (&$tulis) {
            if (! preg_match('/^\s*(select|pragma)/i', $q->sql)) {
                $tulis[] = $q->sql;
            }
        });

        $this->artisan('bukti:periksa-konsistensi', ['--drive' => true])
            ->expectsOutputToContain('BACA-SAJA')
            ->expectsOutputToContain('Berkas TANPA salinan (Drive kosong & berkas lokal hilang) — perlu diunggah ulang pengguna: 1')
            ->expectsOutputToContain('menunggu disusulkan ke Drive (akan dicoba berkas:sinkron-drive): 1')
            ->expectsOutputToContain('pemiliknya (kegiatan/poin/RTL) sudah tidak ada: 1')
            ->expectsOutputToContain('Berkas lokal tanpa baris database (kandidat bukti:bersihkan-lokal-yatim): 2')
            ->expectsOutputToContain('Salinan Drive tidak ditemukan / di tempat sampah (200 berkas terbaru): 1')
            ->expectsOutputToContain('Berkas Drive bertanda SIPINTER tanpa padanan database: 1')
            ->assertSuccessful();

        $this->assertSame([], $tulis, 'pemeriksa tidak boleh menulis ke database');
        $this->assertSame($berkasSebelum, Berkas::orderBy('id')->get()->toArray());
        $this->assertSame($lokalSebelum, $this->semuaBerkasLokal());
        $this->assertNotNull($temuan['hilang']->fresh());
    }

    public function test_pembersih_bawaannya_hanya_laporan(): void
    {
        $this->siapkanTemuan();

        $this->artisan('bukti:bersihkan-lokal-yatim')->expectsOutputToContain('MODE LAPORAN')->assertSuccessful();

        Storage::disk('local')->assertExists('bukti-capaian/yatim-lama.pdf');
        $this->assertSame(0, LogAktivitas::count());
    }

    public function test_pembersih_hanya_menghapus_berkas_lokal_yatim_yang_sudah_lama(): void
    {
        $temuan = $this->siapkanTemuan();
        $jumlahBerkas = Berkas::count();

        $this->artisan('bukti:bersihkan-lokal-yatim', ['--jalankan' => true])->assertSuccessful();

        Storage::disk('local')->assertMissing('bukti-capaian/yatim-lama.pdf');
        // Berkas yang masih baru, dan berkas yang dirujuk database, tidak dihapus.
        Storage::disk('local')->assertExists('bukti-capaian/yatim-baru.pdf');
        Storage::disk('local')->assertExists('bukti-capaian/menunggu.pdf');
        $this->assertSame($jumlahBerkas, Berkas::count(), 'baris database tidak disentuh');
        $this->assertNotNull($temuan['tanpa_pemilik']->fresh());

        $log = LogAktivitas::where('aksi', 'bukti.lokal_yatim_dibersihkan')->sole();
        $this->assertStringContainsString('1 dihapus', $log->keterangan);
        $this->assertSame(['bukti-capaian/yatim-lama.pdf'], $log->perubahan['sebelum']['berkas_lokal']);
    }
}
