<?php

namespace Tests\Feature;

use App\Events\NotulaStatusDiubah;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Services\LibreOfficeConversionService;
use App\Services\NotulaService;
use App\Support\PemulihPenggantianBerkas;
use App\Support\PenggantiBerkasAman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * gabungkan()/setujui()/terimaUploadBagian() mengganti berkas yang mungkin sudah ada:
 * berkas lama tidak boleh hilang, dan PDF di disk tidak boleh berbeda dari isi DB bila
 * pemasangan berkas atau pembaruan DB gagal.
 */
class NotulaPenggantianBerkasTest extends TestCase
{
    use RefreshDatabase;

    /** PDF tiruan yang lolos validasi format (diawali %PDF-, ditutup %%EOF). */
    private const PDF_BARU = "%PDF-1.7 baru\n%%EOF\n";

    private ?string $dirNotula = null;

    /** Jurnal & kunci PenggantiBerkasAman ditulis ke folder sementara, bukan storage sungguhan. */
    private string $dirKerja;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dirKerja = sys_get_temp_dir().DIRECTORY_SEPARATOR.'uji-notula-ganti-'.uniqid();
        $this->app->instance(PenggantiBerkasAman::class, new PenggantiBerkasAman($this->dirKerja, 1, 0, 500));
    }

    protected function tearDown(): void
    {
        if ($this->dirNotula !== null) {
            File::deleteDirectory($this->dirNotula);
        }
        File::deleteDirectory($this->dirKerja);
        parent::tearDown();
    }

    private function jurnalTersisa(): array
    {
        return glob($this->dirKerja.'/jurnal/*.json') ?: [];
    }

    private function notulaLengkap(string $status = Notula::STATUS_DRAFT): Notula
    {
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $notula = Notula::create(['periode_id' => $periode->id, 'status' => $status, 'bagian1_html' => '<p>I baru</p>', 'bagian2_html' => '<p>II</p>', 'bagian3_html' => '<p>III</p>']);
        $this->dirNotula = storage_path("app/private/notula/{$notula->id}");

        return $notula;
    }

    private function tulisBerkasLama(string $path): void
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, '%PDF-lama');
    }

    /** Berkas .tmp / .cadangan-* yang tertinggal di folder notula. */
    private function sisaSementara(): array
    {
        return array_values(array_filter(
            glob(($this->dirNotula ?? '').DIRECTORY_SEPARATOR.'*') ?: [],
            fn ($f) => str_ends_with($f, '.tmp') || str_contains($f, '.cadangan-')
        ));
    }

    private function konversiBerhasil(string $html = '<p>Bagian II baru</p>'): void
    {
        $this->instance(LibreOfficeConversionService::class, Mockery::mock(LibreOfficeConversionService::class, function ($mock) use ($html) {
            $mock->shouldReceive('convertToPdf')->andReturnUsing(function (string $masuk, string $dir) {
                $keluar = $dir.DIRECTORY_SEPARATOR.pathinfo($masuk, PATHINFO_FILENAME).'.pdf';
                file_put_contents($keluar, self::PDF_BARU);

                return $keluar;
            });
            $mock->shouldReceive('convertToHtml')->andReturn($html);
        }));
    }

    private function penggantiYangGagalMemasang(): void
    {
        // Hanya pemasangan berkas sementara hasil render/konversi (di LUAR folder kerja)
        // yang digagalkan -- penulisan jurnal tetap normal.
        $this->instance(PenggantiBerkasAman::class, new class($this->dirKerja) extends PenggantiBerkasAman
        {
            public function __construct(private string $kerja)
            {
                parent::__construct($kerja, 1, 0, 500);
            }

            protected function pindahkan(string $dari, string $ke): bool
            {
                $sumberRender = str_ends_with($dari, '.tmp') && ! str_starts_with($dari, $this->kerja);

                return $sumberRender ? false : parent::pindahkan($dari, $ke);
            }
        });
    }

    /**
     * Pasang pengganti yang mencatat status DB (menurut deskripsi verifikasi di jurnal
     * buatan NotulaService) tepat SEBELUM lanjutan DB dan tepat SETELAH-nya.
     *
     * @return \ArrayObject<string, string>
     */
    private function catatStatusDbDiJurnal(): \ArrayObject
    {
        $catatan = new \ArrayObject;
        $this->instance(PenggantiBerkasAman::class, new class($this->dirKerja, $catatan) extends PenggantiBerkasAman
        {
            public function __construct(string $kerja, private \ArrayObject $catatan)
            {
                parent::__construct($kerja, 1, 0, 500);
            }

            protected function titik(string $nama, array $jurnal): void
            {
                if (in_array($nama, ['baru_terpasang', 'lanjutan_selesai'], true)) {
                    $this->catatan[$nama] = (new PemulihPenggantianBerkas($this))->statusDb($jurnal['verifikasi']);
                }
            }
        });

        return $catatan;
    }

    public function test_verifikasi_jurnal_dari_ketiga_alur_membedakan_db_sebelum_dan_sesudah_commit(): void
    {
        Storage::fake('local');
        $role = Role::firstOrCreate(['nama' => 'Kepala']);
        $kepala = User::create(['nama' => 'Kepala V', 'username' => 'kv@example.test', 'email' => 'kv@example.test', 'password' => 'password', 'role_id' => $role->id, 'status_verifikasi' => 'terverifikasi']);
        $notula = $this->notulaLengkap();
        $harapan = ['baru_terpasang' => PemulihPenggantianBerkas::DB_BELUM, 'lanjutan_selesai' => PemulihPenggantianBerkas::DB_DITERAPKAN];

        $catatan = $this->catatStatusDbDiJurnal();
        $this->konversiBerhasil('<p>Bagian II versi uji</p>');
        app(NotulaService::class)->terimaUploadBagian($notula, 2, UploadedFile::fake()->create('b2.docx', 5));
        $this->assertSame($harapan, $catatan->getArrayCopy(), 'terimaUploadBagian');

        $catatan = $this->catatStatusDbDiJurnal();
        app(NotulaService::class)->gabungkan($notula->fresh());
        $this->assertSame($harapan, $catatan->getArrayCopy(), 'gabungkan');

        $catatan = $this->catatStatusDbDiJurnal();
        app(NotulaService::class)->setujui($notula->fresh(), $kepala);
        $this->assertSame($harapan, $catatan->getArrayCopy(), 'setujui');

        $this->assertSame([], $this->jurnalTersisa());
    }

    // ---------------------------------------------------------------- gabungkan()

    public function test_gabungkan_ulang_versi_sama_mengganti_draf_lama_tanpa_sisa_berkas(): void
    {
        $notula = $this->notulaLengkap();
        $path = $this->dirNotula.'/'.$notula->namaUnduhan('draf');
        $this->tulisBerkasLama($path);

        app(NotulaService::class)->gabungkan($notula);

        $this->assertNotSame('%PDF-lama', file_get_contents($path));
        $this->assertStringStartsWith('%PDF-', file_get_contents($path));
        $this->assertSame(Notula::STATUS_MENUNGGU_PERSETUJUAN, $notula->fresh()->status);
        $this->assertSame([], $this->sisaSementara());
        $this->assertSame([], $this->jurnalTersisa(), 'Tidak boleh ada jurnal tertinggal.');
    }

    public function test_gabungkan_gagal_update_db_memulihkan_draf_lama(): void
    {
        $notula = $this->notulaLengkap();
        $path = $this->dirNotula.'/'.$notula->namaUnduhan('draf');
        $this->tulisBerkasLama($path);
        Event::listen(NotulaStatusDiubah::class, fn () => throw new RuntimeException('kirim ke Kepala gagal'));

        try {
            app(NotulaService::class)->gabungkan($notula);
            $this->fail('Harus melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertSame('kirim ke Kepala gagal', $e->getMessage());
        }

        $this->assertSame('%PDF-lama', file_get_contents($path), 'Draf lama harus dipulihkan.');
        $this->assertSame(Notula::STATUS_DRAFT, $notula->fresh()->status);
        $this->assertNull($notula->fresh()->pdf_gabungan);
        $this->assertSame(Notula::STATUS_DRAFT, $notula->status, 'Model di memori ikut dikembalikan.');
        $this->assertSame([], $this->sisaSementara());
        $this->assertSame([], $this->jurnalTersisa(), 'Tidak boleh ada jurnal tertinggal.');
    }

    public function test_gabungkan_pemasangan_berkas_gagal_tidak_mengubah_db_maupun_draf_lama(): void
    {
        $notula = $this->notulaLengkap();
        $path = $this->dirNotula.'/'.$notula->namaUnduhan('draf');
        $this->tulisBerkasLama($path);
        $this->penggantiYangGagalMemasang();

        try {
            app(NotulaService::class)->gabungkan($notula);
            $this->fail('Harus melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('gagal dipasang', $e->getMessage());
        }

        $this->assertSame('%PDF-lama', file_get_contents($path));
        $this->assertSame(Notula::STATUS_DRAFT, $notula->fresh()->status);
        $this->assertNull($notula->fresh()->pdf_gabungan);
        $this->assertSame([], $this->sisaSementara());
        $this->assertSame([], $this->jurnalTersisa(), 'Tidak boleh ada jurnal tertinggal.');
    }

    public function test_gabungkan_folder_notula_tidak_bisa_dibuat_menghasilkan_pesan_jelas(): void
    {
        $notula = $this->notulaLengkap();
        // Folder notula/{id} sengaja berupa BERKAS -> folder tidak bisa dibuat/ditulisi.
        File::ensureDirectoryExists(dirname($this->dirNotula));
        file_put_contents($this->dirNotula, 'bukan folder');

        try {
            app(NotulaService::class)->gabungkan($notula);
            $this->fail('Harus melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Folder penyimpanan tidak bisa', $e->getMessage());
        } finally {
            @unlink($this->dirNotula);
        }

        $this->assertSame(Notula::STATUS_DRAFT, $notula->fresh()->status);
        $this->assertNull($notula->fresh()->pdf_gabungan);
    }

    // ---------------------------------------------------------------- setujui()

    public function test_setujui_gagal_memulihkan_berkas_yang_sudah_ada_di_path_final(): void
    {
        $role = Role::firstOrCreate(['nama' => 'Kepala']);
        $kepala = User::create(['nama' => 'Kepala Uji', 'username' => 'kpl@example.test', 'email' => 'kpl@example.test', 'password' => 'password', 'role_id' => $role->id, 'status_verifikasi' => 'terverifikasi']);
        $notula = $this->notulaLengkap(Notula::STATUS_MENUNGGU_PERSETUJUAN);
        $iku = MasterIku::create(['kode' => 'P1', 'indikator' => 'Indikator P1', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $kegiatan = Kegiatan::create(['iku_id' => $iku->id, 'periode_id' => $notula->periode_id, 'uraian_kegiatan' => 'K', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);

        $path = $this->dirNotula.'/'.$notula->namaUnduhan('final');
        $this->tulisBerkasLama($path);
        // Langkah TERAKHIR transaksi persetujuan dibuat gagal.
        Notula::updating(function (Notula $n) {
            if ($n->isDirty('pdf_final')) {
                throw new RuntimeException('simpan pdf_final gagal');
            }
        });

        try {
            app(NotulaService::class)->setujui($notula, $kepala);
            $this->fail('Harus melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertSame('simpan pdf_final gagal', $e->getMessage());
        }

        $this->assertSame('%PDF-lama', file_get_contents($path), 'Berkas lama di path final tidak boleh dihapus.');
        $this->assertSame(Notula::STATUS_MENUNGGU_PERSETUJUAN, $notula->fresh()->status);
        $this->assertNull($notula->fresh()->pdf_final);
        $this->assertSame(Kegiatan::STATUS_DIVERIFIKASI, $kegiatan->fresh()->status_dokumen);
        $this->assertSame([], $this->sisaSementara());
        $this->assertSame([], $this->jurnalTersisa(), 'Tidak boleh ada jurnal tertinggal.');
    }

    // ---------------------------------------------------------------- terimaUploadBagian()

    public function test_unggah_ulang_bagian2_mengganti_pdf_dan_html_bersamaan(): void
    {
        Storage::fake('local');
        $notula = $this->notulaLengkap();
        Storage::disk('local')->put("notula/{$notula->id}/bagian2.pdf", '%PDF-lama');
        $this->konversiBerhasil('<p>Bagian II baru</p>');

        app(NotulaService::class)->terimaUploadBagian($notula, 2, UploadedFile::fake()->create('b2.docx', 5));

        $this->assertSame(self::PDF_BARU, Storage::disk('local')->get("notula/{$notula->id}/bagian2.pdf"));
        $this->assertSame('<p>Bagian II baru</p>', $notula->fresh()->bagian2_html);
        $this->assertSame(["notula/{$notula->id}/bagian2.pdf"], Storage::disk('local')->files("notula/{$notula->id}"));
        $this->assertSame([], Storage::disk('local')->files('notula-sementara'));
    }

    public function test_unggah_bagian2_gagal_update_db_mempertahankan_pdf_dan_html_lama(): void
    {
        Storage::fake('local');
        $notula = $this->notulaLengkap();
        Storage::disk('local')->put("notula/{$notula->id}/bagian2.pdf", '%PDF-lama');
        $notula->update(['bagian2_pdf' => "notula/{$notula->id}/bagian2.pdf", 'bagian2_html' => '<p>Bagian II lama</p>']);
        $this->konversiBerhasil();
        Notula::updating(fn () => throw new RuntimeException('update DB gagal'));

        try {
            app(NotulaService::class)->terimaUploadBagian($notula, 2, UploadedFile::fake()->create('b2.docx', 5));
            $this->fail('Harus melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertSame('update DB gagal', $e->getMessage());
        }

        $this->assertSame('%PDF-lama', Storage::disk('local')->get("notula/{$notula->id}/bagian2.pdf"));
        $this->assertSame('<p>Bagian II lama</p>', $notula->fresh()->bagian2_html);
        $this->assertSame('<p>Bagian II lama</p>', $notula->bagian2_html, 'Model di memori ikut dikembalikan.');
        $this->assertSame(["notula/{$notula->id}/bagian2.pdf"], Storage::disk('local')->files("notula/{$notula->id}"));
        $this->assertSame([], Storage::disk('local')->files('notula-sementara'));
    }

    public function test_unggah_bagian2_pemasangan_gagal_tidak_mengubah_db(): void
    {
        Storage::fake('local');
        $notula = $this->notulaLengkap();
        Storage::disk('local')->put("notula/{$notula->id}/bagian2.pdf", '%PDF-lama');
        $notula->update(['bagian2_html' => '<p>Bagian II lama</p>']);
        $this->konversiBerhasil();
        $this->penggantiYangGagalMemasang();

        try {
            app(NotulaService::class)->terimaUploadBagian($notula, 2, UploadedFile::fake()->create('b2.docx', 5));
            $this->fail('Harus melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('gagal dipasang', $e->getMessage());
        }

        $this->assertSame('%PDF-lama', Storage::disk('local')->get("notula/{$notula->id}/bagian2.pdf"));
        $this->assertSame('<p>Bagian II lama</p>', $notula->fresh()->bagian2_html);
        $this->assertSame(["notula/{$notula->id}/bagian2.pdf"], Storage::disk('local')->files("notula/{$notula->id}"));
        $this->assertSame([], Storage::disk('local')->files('notula-sementara'));
    }
}
