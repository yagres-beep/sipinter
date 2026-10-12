<?php

namespace Tests\Feature;

use App\Models\Notula;
use App\Models\Periode;
use App\Support\PemulihPenggantianBerkas;
use App\Support\PenggantiBerkasAman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Pemulihan operasi penggantian berkas yang terputus. "Proses mati" disimulasikan dengan
 * SUBPROSES PHP sungguhan yang memanggil exit() di tahap tertentu (blok finally tidak
 * jalan, kunci dilepas OS saat proses berakhir) -- bukan exception yang masih sempat
 * menjalankan pembersihan. Kondisi DB (ter-commit atau belum) diatur di proses test.
 */
class PenggantianBerkasPemulihanTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $dirKerja;

    private string $skrip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'uji-pemulihan-'.uniqid();
        $this->dirKerja = $this->dir.DIRECTORY_SEPARATOR.'_kerja';
        mkdir($this->dir);
        $this->skrip = $this->dir.DIRECTORY_SEPARATOR.'_proses.php';
        file_put_contents($this->skrip, <<<'PHP'
<?php
// argv: basePath dirKerja sumber tujuan tahapMati verifikasiJson penanda tidurMs
[, $base, $dirKerja, $sumber, $tujuan, $mati, $verifikasi, $penanda, $tidur] = $argv;
require $base.'/vendor/autoload.php';

$p = new class($dirKerja, $mati, $penanda, (int) $tidur) extends App\Support\PenggantiBerkasAman {
    public function __construct(string $d, private string $mati, private string $penanda, private int $tidur)
    {
        parent::__construct($d, 1, 0, 500);
    }

    protected function titik(string $nama, array $jurnal): void
    {
        if ($nama !== $this->mati) {
            return;
        }
        if ($this->penanda !== '-') {
            file_put_contents($this->penanda, $jurnal['id']);
        }
        if ($this->tidur > 0) {
            usleep($this->tidur * 1000); // tetap memegang kunci, lalu lanjut normal

            return;
        }
        exit(0); // MATI MENDADAK: tanpa finally, tanpa pembersihan
    }
};

$p->pasang($sumber, $tujuan, function () {}, ['label' => 'uji', 'verifikasi' => json_decode($verifikasi, true)]);
PHP);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function pengganti(): PenggantiBerkasAman
    {
        return new PenggantiBerkasAman($this->dirKerja, 1, 0, 500);
    }

    private function pemulih(): PemulihPenggantianBerkas
    {
        return new PemulihPenggantianBerkas($this->pengganti());
    }

    private function tulis(string $nama, string $isi): string
    {
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.$nama, $isi);

        return $this->dir.DIRECTORY_SEPARATOR.$nama;
    }

    private function tujuan(): string
    {
        return $this->dir.DIRECTORY_SEPARATOR.'notula.pdf';
    }

    private function notula(): Notula
    {
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        return Notula::create(['periode_id' => $periode->id, 'status' => Notula::STATUS_DRAFT, 'bagian1_html' => '<p>I</p>']);
    }

    /** Verifikasi seperti yang dicatat NotulaService::gabungkan(). */
    private function verifikasi(Notula $n, ?array $sesudah = null): array
    {
        return [
            'model' => Notula::class, 'id' => $n->id,
            'sebelum' => ['status' => Notula::STATUS_DRAFT, 'pdf_gabungan' => null],
            'sesudah' => $sesudah ?? ['status' => Notula::STATUS_MENUNGGU_PERSETUJUAN, 'pdf_gabungan' => "notula/{$n->id}/draf.pdf"],
        ];
    }

    private function prosesMati(string $tahap, array $verifikasi, string $sumber, string $penanda = '-', int $tidurMs = 0): Process
    {
        return new Process([PHP_BINARY, $this->skrip, base_path(), $this->dirKerja, $sumber, $this->tujuan(), $tahap, json_encode($verifikasi), $penanda, (string) $tidurMs]);
    }

    private function matikanDi(string $tahap, array $verifikasi, string $sumber): void
    {
        $p = $this->prosesMati($tahap, $verifikasi, $sumber);
        $p->mustRun();
    }

    /** @return array<string, string> path relatif => hash|mtime -- untuk membuktikan mode periksa read-only */
    private function potretBerkas(): array
    {
        $hasil = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                clearstatcache(true, $f->getPathname());
                // Berkas kunci yang sedang di-flock tidak bisa dibaca di Windows (kunci wajib).
                $hash = @hash_file('sha256', $f->getPathname()) ?: 'terkunci';
                $hasil[substr($f->getPathname(), strlen($this->dir))] = $hash.'|'.filemtime($f->getPathname());
            }
        }
        ksort($hasil);

        return $hasil;
    }

    private function cadangan(): array
    {
        return glob($this->tujuan().'.cadangan-*') ?: [];
    }

    private function jurnal(): array
    {
        return glob($this->dirKerja.'/jurnal/*.json') ?: [];
    }

    private function satuAnalisis(): array
    {
        $laporan = $this->pemulih()->periksa();
        $this->assertCount(1, $laporan);

        return $laporan[0];
    }

    // ---------------------------------------------------------------- 12

    public function test_12_mati_setelah_berkas_lama_dicadangkan_dipulihkan_ke_berkas_lama(): void
    {
        $n = $this->notula();
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');

        $this->matikanDi('lama_dicadangkan', $this->verifikasi($n), $sumber);

        // Kondisi tertinggal: tujuan kosong, cadangan berisi berkas lama, sumber masih ada.
        $this->assertFileDoesNotExist($this->tujuan());
        $this->assertCount(1, $this->cadangan());

        $sebelum = $this->potretBerkas();
        $a = $this->satuAnalisis();
        $this->assertSame($sebelum, $this->potretBerkas(), 'Mode periksa harus read-only.');
        $this->assertSame(PemulihPenggantianBerkas::KEMBALIKAN_LAMA, $a['keputusan']);

        $hasil = $this->pemulih()->pulihkan();

        $this->assertTrue($hasil[0]['berhasil']);
        $this->assertSame('%PDF-lama', file_get_contents($this->tujuan()));
        $this->assertSame([], $this->cadangan());
        $this->assertFileDoesNotExist($sumber);
        $this->assertSame([], $this->jurnal());
        $this->assertSame(Notula::STATUS_DRAFT, $n->fresh()->status, 'DB tidak disentuh pemulihan.');
    }

    public function test_12b_mati_tepat_setelah_berkas_baru_dipindah_sebelum_jurnal_diperbarui(): void
    {
        $n = $this->notula();
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');

        $this->matikanDi('baru_dipindahkan', $this->verifikasi($n), $sumber);
        $this->assertSame('%PDF-baru', file_get_contents($this->tujuan()));

        // Lanjutan DB belum pernah jalan (jurnal masih lama_dicadangkan) -> kembali ke lama.
        $this->assertSame(PemulihPenggantianBerkas::KEMBALIKAN_LAMA, $this->satuAnalisis()['keputusan']);
        $this->pemulih()->pulihkan();

        $this->assertSame('%PDF-lama', file_get_contents($this->tujuan()));
        $this->assertSame([], $this->cadangan());
        $this->assertSame([], $this->jurnal());
    }

    // ---------------------------------------------------------------- 13

    public function test_13_mati_sebelum_transaksi_db_selesai_db_belum_commit_kembali_ke_lama(): void
    {
        $n = $this->notula();
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');

        $this->matikanDi('baru_terpasang', $this->verifikasi($n), $sumber);

        $a = $this->satuAnalisis();
        $this->assertSame(PemulihPenggantianBerkas::DB_BELUM, $a['db']);
        $this->assertSame(PemulihPenggantianBerkas::KEMBALIKAN_LAMA, $a['keputusan']);

        $this->pemulih()->pulihkan();

        $this->assertSame('%PDF-lama', file_get_contents($this->tujuan()));
        $this->assertSame([], $this->cadangan());
        $this->assertSame([], $this->jurnal());
    }

    public function test_13b_mati_setelah_commit_db_sebelum_jurnal_diperbarui_berkas_baru_dipertahankan(): void
    {
        $n = $this->notula();
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');

        $this->matikanDi('lanjutan_selesai', $this->verifikasi($n), $sumber);
        // Transaksi DB sempat ter-commit sebelum proses mati.
        $n->update(['status' => Notula::STATUS_MENUNGGU_PERSETUJUAN, 'pdf_gabungan' => "notula/{$n->id}/draf.pdf"]);

        $a = $this->satuAnalisis();
        $this->assertSame(PenggantiBerkasAman::TAHAP_BARU_TERPASANG, $a['tahap']);
        $this->assertSame(PemulihPenggantianBerkas::DB_DITERAPKAN, $a['db']);
        $this->assertSame(PemulihPenggantianBerkas::PERTAHANKAN_BARU, $a['keputusan']);

        $this->pemulih()->pulihkan();

        $this->assertSame('%PDF-baru', file_get_contents($this->tujuan()));
        $this->assertSame([], $this->cadangan());
        $this->assertSame([], $this->jurnal());
    }

    public function test_13c_kondisi_db_ambigu_semua_berkas_dipertahankan(): void
    {
        $n = $this->notula();
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');
        // Penggabungan ulang saat status & path TIDAK berubah: sebelum == sesudah.
        $verifikasi = $this->verifikasi($n, ['status' => Notula::STATUS_DRAFT, 'pdf_gabungan' => null]);

        $this->matikanDi('baru_terpasang', $verifikasi, $sumber);
        $sebelum = $this->potretBerkas();

        $a = $this->satuAnalisis();
        $this->assertSame(PemulihPenggantianBerkas::DB_AMBIGU, $a['db']);
        $this->assertSame(PemulihPenggantianBerkas::MANUAL, $a['keputusan']);

        $hasil = $this->pemulih()->pulihkan();

        $this->assertFalse($hasil[0]['berhasil']);
        $this->assertSame($sebelum, $this->potretBerkas(), 'Kondisi ambigu: tidak ada berkas yang boleh berubah.');
        $this->assertCount(1, $this->cadangan());
        $this->assertCount(1, $this->jurnal());
    }

    public function test_13d_tanpa_berkas_lama_db_belum_commit_berkas_baru_dibuang(): void
    {
        $n = $this->notula();
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');

        $this->matikanDi('baru_terpasang', $this->verifikasi($n), $sumber);
        $this->assertSame(PemulihPenggantianBerkas::KEMBALIKAN_LAMA, $this->satuAnalisis()['keputusan']);

        $this->pemulih()->pulihkan();

        $this->assertFileDoesNotExist($this->tujuan());
        $this->assertSame([], $this->jurnal());
    }

    // ---------------------------------------------------------------- 14

    public function test_14_mati_setelah_db_berhasil_sebelum_cadangan_dibersihkan(): void
    {
        $n = $this->notula();
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');

        $this->matikanDi('db_selesai', $this->verifikasi($n), $sumber);
        $n->update(['status' => Notula::STATUS_MENUNGGU_PERSETUJUAN, 'pdf_gabungan' => "notula/{$n->id}/draf.pdf"]);
        $this->assertCount(1, $this->cadangan());

        $this->assertSame(PemulihPenggantianBerkas::PERTAHANKAN_BARU, $this->satuAnalisis()['keputusan']);
        $this->pemulih()->pulihkan();

        $this->assertSame('%PDF-baru', file_get_contents($this->tujuan()));
        $this->assertSame([], $this->cadangan());
        $this->assertSame([], $this->jurnal());
    }

    public function test_14b_jurnal_db_selesai_tapi_db_masih_kondisi_lama_bertentangan_ditahan(): void
    {
        $n = $this->notula();
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');

        $this->matikanDi('db_selesai', $this->verifikasi($n), $sumber); // DB TIDAK diperbarui
        $sebelum = $this->potretBerkas();

        $this->assertSame(PemulihPenggantianBerkas::MANUAL, $this->satuAnalisis()['keputusan']);
        $this->pemulih()->pulihkan();

        $this->assertSame($sebelum, $this->potretBerkas());
        $this->assertCount(1, $this->cadangan(), 'Cadangan tidak boleh dihapus saat kondisi bertentangan.');
    }

    // ---------------------------------------------------------------- 16: tidak menghapus yang masih diperlukan

    public function test_16_cadangan_yang_isinya_berubah_tidak_dipulihkan_maupun_dihapus(): void
    {
        $n = $this->notula();
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');

        $this->matikanDi('lama_dicadangkan', $this->verifikasi($n), $sumber);
        file_put_contents($this->cadangan()[0], '%PDF-diubah-orang');
        $sebelum = $this->potretBerkas();

        $this->assertSame(PemulihPenggantianBerkas::MANUAL, $this->satuAnalisis()['keputusan']);
        $this->pemulih()->pulihkan();

        $this->assertSame($sebelum, $this->potretBerkas());
    }

    public function test_16b_operasi_yang_masih_berjalan_dilewati_dan_tidak_disentuh(): void
    {
        $n = $this->notula();
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');
        $penanda = $this->dir.DIRECTORY_SEPARATOR.'_penanda';

        // Subproses berhenti SEMENTARA di tengah operasi sambil memegang kunci.
        $proses = $this->prosesMati('baru_terpasang', $this->verifikasi($n), $sumber, $penanda, tidurMs: 4000);
        $proses->start();
        $this->tungguBerkas($penanda);

        $sebelum = $this->potretBerkas();
        $this->assertSame(PemulihPenggantianBerkas::SEDANG_BERJALAN, $this->satuAnalisis()['keputusan']);
        $hasil = $this->pemulih()->pulihkan();
        $this->assertSame(PemulihPenggantianBerkas::SEDANG_BERJALAN, $hasil[0]['keputusan']);
        $this->assertFalse($hasil[0]['berhasil']);
        $this->assertSame($sebelum, $this->potretBerkas());

        $proses->wait();
        $this->assertSame(0, $proses->getExitCode(), $proses->getErrorOutput());
        $this->assertSame('%PDF-baru', file_get_contents($this->tujuan()));
        $this->assertSame([], $this->cadangan());
        $this->assertSame([], $this->jurnal());
    }

    public function test_operasi_lebih_baru_atas_berkas_sama_membuat_yang_lama_ditahan(): void
    {
        $n = $this->notula();
        $this->tulis('notula.pdf', '%PDF-lama');
        $this->matikanDi('lama_dicadangkan', $this->verifikasi($n), $this->tulis('a.tmp', '%PDF-A'));
        // Operasi kedua juga mati -- dua jurnal atas tujuan yang sama.
        $this->tulis('notula.pdf', '%PDF-diisi-ulang');
        $this->matikanDi('lama_dicadangkan', $this->verifikasi($n), $this->tulis('b.tmp', '%PDF-B'));

        $laporan = $this->pemulih()->periksa();
        $this->assertCount(2, $laporan);
        $this->assertSame(PemulihPenggantianBerkas::MANUAL, $laporan[0]['keputusan']);
        $this->assertStringContainsString('lebih baru', $laporan[0]['alasan']);
    }

    // ---------------------------------------------------------------- 15: lintas proses

    public function test_15_dua_proses_mengganti_berkas_yang_sama_bersamaan(): void
    {
        $n = $this->notula();
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumberA = $this->tulis('a.tmp', '%PDF-A');
        $sumberB = $this->tulis('b.tmp', '%PDF-B');
        $penanda = $this->dir.DIRECTORY_SEPARATOR.'_penanda';

        $proses = $this->prosesMati('baru_terpasang', $this->verifikasi($n), $sumberA, $penanda, tidurMs: 3000);
        $proses->start();
        $this->tungguBerkas($penanda);

        try {
            $this->pengganti()->pasang($sumberB, $this->tujuan(), fn () => $this->fail('B tidak boleh jalan.'));
            $this->fail('Proses kedua harus ditolak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sedang diganti oleh proses lain', $e->getMessage());
        }

        $proses->wait();
        $this->assertSame(0, $proses->getExitCode(), $proses->getErrorOutput());
        $this->assertSame('%PDF-A', file_get_contents($this->tujuan()));
        $this->assertFileDoesNotExist($sumberB);
        $this->assertSame([], $this->cadangan());
        $this->assertSame([], $this->jurnal());

        // Setelah proses pertama selesai, penggantian berikutnya berjalan normal.
        $this->pengganti()->pasang($this->tulis('c.tmp', '%PDF-C'), $this->tujuan());
        $this->assertSame('%PDF-C', file_get_contents($this->tujuan()));
    }

    // ---------------------------------------------------------------- perintah Artisan

    public function test_perintah_periksa_read_only_lalu_pulihkan_dengan_konfirmasi_production(): void
    {
        $this->app->instance(PenggantiBerkasAman::class, $this->pengganti());
        $n = $this->notula();
        $this->tulis('notula.pdf', '%PDF-lama');
        $this->matikanDi('lama_dicadangkan', $this->verifikasi($n), $this->tulis('baru.tmp', '%PDF-baru'));
        $sebelum = $this->potretBerkas();

        $this->artisan('notula:penggantian-berkas')
            ->expectsOutputToContain(PemulihPenggantianBerkas::KEMBALIKAN_LAMA)
            ->expectsOutputToContain('read-only')
            ->assertFailed();
        $this->assertSame($sebelum, $this->potretBerkas());

        $this->app['env'] = 'production';
        $this->artisan('notula:penggantian-berkas', ['--pulihkan' => true])
            ->expectsConfirmation('Pulihkan operasi di atas pada server PRODUCTION?', 'no')
            ->assertFailed();
        $this->assertSame($sebelum, $this->potretBerkas(), 'Konfirmasi ditolak: tidak ada perubahan.');

        $this->artisan('notula:penggantian-berkas', ['--pulihkan' => true])
            ->expectsConfirmation('Pulihkan operasi di atas pada server PRODUCTION?', 'yes')
            ->assertSuccessful();
        $this->assertSame('%PDF-lama', file_get_contents($this->tujuan()));
        $this->assertSame([], $this->jurnal());
    }

    public function test_cadangan_tanpa_jurnal_hanya_dilaporkan(): void
    {
        $yatim = $this->tulis('notula.pdf.cadangan-versilama', '%PDF-lama');

        $daftar = $this->pemulih()->berkasTanpaJurnal([$this->dir]);

        $this->assertContains($yatim, $daftar);
        $this->pemulih()->pulihkan();
        $this->assertFileExists($yatim);
    }

    private function tungguBerkas(string $path): void
    {
        $batas = microtime(true) + 20;
        while (! is_file($path) && microtime(true) < $batas) {
            usleep(50_000);
        }
        $this->assertFileExists($path, 'Subproses tidak mencapai tahap yang ditunggu.');
    }
}
