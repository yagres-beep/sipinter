<?php

namespace Tests\Unit;

use App\Support\PenggantiBerkasAman;
use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * Seam uji: kegagalan pindah/hapus, perusakan isi setelah dipindah, dan hook titik()
 * bisa diatur per test. Semua operasi lain memakai filesystem SUNGGUHAN.
 */
class PenggantiUji extends PenggantiBerkasAman
{
    public ?Closure $gagalPindah = null;

    public ?Closure $gagalHapus = null;

    public ?Closure $rusakkanSetelahPindah = null;

    public ?Closure $diTitik = null;

    public int $jumlahPindah = 0;

    protected function pindahkan(string $dari, string $ke): bool
    {
        $this->jumlahPindah++;
        if ($this->gagalPindah !== null && ($this->gagalPindah)($dari, $ke)) {
            return false;
        }
        $ok = parent::pindahkan($dari, $ke);
        if ($ok && $this->rusakkanSetelahPindah !== null && ($this->rusakkanSetelahPindah)($dari, $ke)) {
            file_put_contents($ke, '%PDF-rusak');
        }

        return $ok;
    }

    protected function hapus(string $path): bool
    {
        if ($this->gagalHapus !== null && ($this->gagalHapus)($path)) {
            return false;
        }

        return parent::hapus($path);
    }

    protected function titik(string $nama, array $jurnal): void
    {
        if ($this->diTitik !== null) {
            ($this->diTitik)($nama, $jurnal);
        }
    }
}

class PenggantiBerkasAmanTest extends TestCase
{
    private string $dir;

    private string $dirKerja;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'uji-pengganti-'.uniqid();
        $this->dirKerja = $this->dir.DIRECTORY_SEPARATOR.'_kerja';
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function pengganti(int $percobaan = 1, int $tungguKunciMs = 300): PenggantiUji
    {
        return new PenggantiUji($this->dirKerja, $percobaan, 0, $tungguKunciMs);
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

    /** Berkas di folder uji selain folder kerja & $kecuali (sisa .tmp/.cadangan harus kosong). */
    private function sisaBerkas(string ...$kecuali): array
    {
        $semua = array_filter(glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [], 'is_file');

        return array_values(array_diff(array_map('basename', $semua), $kecuali));
    }

    private function jurnal(): array
    {
        return glob($this->dirKerja.DIRECTORY_SEPARATOR.'jurnal'.DIRECTORY_SEPARATOR.'*.json') ?: [];
    }

    private function bacaJurnalTunggal(): array
    {
        $j = $this->jurnal();
        $this->assertCount(1, $j, 'Harus tersisa tepat satu jurnal.');

        return json_decode(file_get_contents($j[0]), true);
    }

    // ---------------------------------------------------------------- jalur sukses

    public function test_1_tujuan_belum_ada(): void
    {
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');

        $this->pengganti()->pasang($sumber, $this->tujuan());

        $this->assertSame('%PDF-baru', file_get_contents($this->tujuan()));
        $this->assertSame([], $this->sisaBerkas('notula.pdf'));
        $this->assertSame([], $this->jurnal(), 'Operasi sukses tidak meninggalkan jurnal.');
    }

    public function test_2_tujuan_sudah_ada_diganti_cadangan_dan_jurnal_dibersihkan(): void
    {
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');

        $hasil = $this->pengganti()->pasang($sumber, $this->tujuan(), fn () => 'db-ok');

        $this->assertSame('db-ok', $hasil);
        $this->assertSame('%PDF-baru', file_get_contents($this->tujuan()));
        $this->assertSame([], $this->sisaBerkas('notula.pdf'));
        $this->assertSame([], $this->jurnal());
    }

    /**
     * Penguncian SUNGGUHAN: berkas lama dibuka (seperti sedang di-stream ke pengguna).
     * Di Windows, rename() langsung menimpanya gagal -- lewat cadangan, penggantian tetap
     * berhasil. Cadangan yang masih terbuka mungkin belum bisa dihapus; bila begitu jurnal
     * DIPERTAHANKAN (cadangan_tertinggal) dan isi lama tetap ada sampai dibersihkan.
     */
    public function test_3_tujuan_sedang_dibuka_tetap_terganti_tanpa_kehilangan_isi_lama(): void
    {
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');
        $pegangan = fopen($this->tujuan(), 'rb');

        try {
            $this->pengganti()->pasang($sumber, $this->tujuan(), fn () => null);
            $this->assertSame('%PDF-lama', fread($pegangan, 9), 'Pembaca yang sedang membuka tetap melihat isi lama.');
        } finally {
            fclose($pegangan);
        }

        $this->assertSame('%PDF-baru', file_get_contents($this->tujuan()));
        $this->assertFileDoesNotExist($sumber);

        $jurnal = $this->jurnal();
        if ($jurnal !== []) {
            $j = $this->bacaJurnalTunggal();
            $this->assertSame(PenggantiBerkasAman::TAHAP_CADANGAN_TERTINGGAL, $j['tahap']);
        }
    }

    /** Membuktikan perilaku platform yang jadi alasan desain ini (khusus Windows). */
    public function test_3b_windows_rename_langsung_ke_berkas_terbuka_memang_gagal(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Perilaku penguncian khusus Windows.');
        }

        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');
        $pegangan = fopen($this->tujuan(), 'rb');

        try {
            $this->assertFalse(@rename($sumber, $this->tujuan()));
        } finally {
            fclose($pegangan);
        }

        $this->assertSame('%PDF-lama', file_get_contents($this->tujuan()));
    }

    public function test_kunci_sesaat_dicoba_ulang_terbatas(): void
    {
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');
        $p = $this->pengganti(percobaan: 3);
        $gagalSekali = true;
        $p->gagalPindah = function ($dari, $ke) use (&$gagalSekali) {
            if ($gagalSekali && str_contains($ke, '.cadangan-')) {
                $gagalSekali = false;

                return true;
            }

            return false;
        };

        $p->pasang($sumber, $this->tujuan());

        $this->assertSame('%PDF-baru', file_get_contents($this->tujuan()));
    }

    // ---------------------------------------------------------------- kegagalan saat runtime

    public function test_4_pemindahan_lama_ke_cadangan_gagal_berkas_baru_tidak_menimpa(): void
    {
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');
        $p = $this->pengganti(percobaan: 3);
        $p->gagalPindah = fn ($dari, $ke) => str_contains($ke, '.cadangan-');

        try {
            $p->pasang($sumber, $this->tujuan(), fn () => $this->fail('Lanjutan tidak boleh jalan.'));
            $this->fail('Harus melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('terkunci atau izin ditolak', $e->getMessage());
        }

        $this->assertSame('%PDF-lama', file_get_contents($this->tujuan()));
        $this->assertSame([], $this->sisaBerkas('notula.pdf'));
        $this->assertSame([], $this->jurnal());
    }

    public function test_5_pemasangan_berkas_baru_gagal_berkas_lama_dipulihkan(): void
    {
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');
        $p = $this->pengganti();
        $p->gagalPindah = fn ($dari, $ke) => $dari === $sumber;

        try {
            $p->pasang($sumber, $this->tujuan());
            $this->fail('Harus melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('gagal dipasang', $e->getMessage());
        }

        $this->assertSame('%PDF-lama', file_get_contents($this->tujuan()));
        $this->assertSame([], $this->sisaBerkas('notula.pdf'));
        $this->assertSame([], $this->jurnal());
    }

    public function test_6_berkas_baru_terpasang_tapi_isinya_tidak_utuh_dibatalkan(): void
    {
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');
        $p = $this->pengganti();
        $p->rusakkanSetelahPindah = fn ($dari, $ke) => $dari === $sumber;

        try {
            $p->pasang($sumber, $this->tujuan(), fn () => $this->fail('Lanjutan tidak boleh jalan.'));
            $this->fail('Harus melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak utuh', $e->getMessage());
        }

        $this->assertSame('%PDF-lama', file_get_contents($this->tujuan()));
        $this->assertSame([], $this->sisaBerkas('notula.pdf'));
        $this->assertSame([], $this->jurnal());
    }

    public function test_6b_berkas_baru_rusak_tanpa_berkas_lama_dibuang(): void
    {
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');
        $p = $this->pengganti();
        $p->rusakkanSetelahPindah = fn ($dari, $ke) => $dari === $sumber;

        try {
            $p->pasang($sumber, $this->tujuan());
        } catch (RuntimeException) {
        }

        $this->assertFileDoesNotExist($this->tujuan());
        $this->assertSame([], $this->sisaBerkas());
        $this->assertSame([], $this->jurnal());
    }

    public function test_7_8_transaksi_db_gagal_berkas_lama_dipulihkan_dan_exception_diteruskan(): void
    {
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');

        try {
            $this->pengganti()->pasang($sumber, $this->tujuan(), fn () => throw new RuntimeException('update DB gagal'));
            $this->fail('Harus meneruskan exception lanjutan.');
        } catch (RuntimeException $e) {
            $this->assertSame('update DB gagal', $e->getMessage());
        }

        $this->assertSame('%PDF-lama', file_get_contents($this->tujuan()));
        $this->assertSame([], $this->sisaBerkas('notula.pdf'));
        $this->assertSame([], $this->jurnal());
    }

    public function test_7b_transaksi_db_gagal_tanpa_berkas_lama_berkas_baru_dibuang(): void
    {
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');

        try {
            $this->pengganti()->pasang($sumber, $this->tujuan(), fn () => throw new RuntimeException('update DB gagal'));
        } catch (RuntimeException) {
        }

        $this->assertFileDoesNotExist($this->tujuan());
        $this->assertSame([], $this->sisaBerkas());
        $this->assertSame([], $this->jurnal());
    }

    public function test_9_pemulihan_gagal_cadangan_dan_jurnal_dipertahankan(): void
    {
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');
        $p = $this->pengganti(percobaan: 2);
        $p->gagalPindah = fn ($dari, $ke) => str_contains($dari, '.cadangan-');

        try {
            $p->pasang($sumber, $this->tujuan(), fn () => throw new RuntimeException('update DB gagal'));
            $this->fail('Harus melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('gagal dipulihkan', $e->getMessage());
            $this->assertStringContainsString('Penyebab awal: update DB gagal', $e->getMessage(), 'Penyebab awal tidak boleh tertutupi.');
        }

        $j = $this->bacaJurnalTunggal();
        $this->assertSame(PenggantiBerkasAman::TAHAP_GAGAL_PULIH, $j['tahap']);
        $this->assertFileExists($j['cadangan'], 'Satu-satunya salinan berkas lama tidak boleh dihapus.');
        $this->assertSame('%PDF-lama', file_get_contents($j['cadangan']));
        $this->assertSame(hash('sha256', '%PDF-lama'), $j['hash_lama']);
    }

    public function test_10_folder_tujuan_tidak_bisa_dibuat(): void
    {
        $this->tulis('bukan-folder', 'x');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');
        $tujuan = $this->dir.DIRECTORY_SEPARATOR.'bukan-folder'.DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR.'notula.pdf';

        try {
            $this->pengganti()->pasang($sumber, $tujuan);
            $this->fail('Harus melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Folder penyimpanan tidak bisa dibuat', $e->getMessage());
        }

        $this->assertFileDoesNotExist($sumber);
        $this->assertSame([], $this->jurnal());
    }

    public function test_10b_folder_jurnal_tidak_bisa_ditulis_berkas_lama_tidak_disentuh(): void
    {
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');
        // Folder kerja ada (kunci bisa diambil), tapi "jurnal" berupa BERKAS biasa.
        mkdir($this->dirKerja);
        file_put_contents($this->dirKerja.DIRECTORY_SEPARATOR.'jurnal', 'bukan folder');

        try {
            $this->pengganti()->pasang($sumber, $this->tujuan());
            $this->fail('Harus melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Catatan operasi', $e->getMessage());
        }

        $this->assertSame('%PDF-lama', file_get_contents($this->tujuan()));
        $this->assertSame([], $this->sisaBerkas('notula.pdf'));
    }

    public function test_11_sumber_kosong_ditolak(): void
    {
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '');

        $this->expectExceptionMessage('kosong');
        try {
            $this->pengganti()->pasang($sumber, $this->tujuan());
        } finally {
            $this->assertSame('%PDF-lama', file_get_contents($this->tujuan()));
            $this->assertSame([], $this->sisaBerkas('notula.pdf'));
        }
    }

    public function test_11b_sumber_bukan_pdf_atau_terpotong_ditolak(): void
    {
        $this->tulis('notula.pdf', '%PDF-lama');
        $opsi = ['awalan' => '%PDF-', 'akhiran_memuat' => '%%EOF'];

        foreach (['<html>bukan pdf %%EOF' => 'bukan pdf', '%PDF-1.7 isi terpotong tanpa penutup' => 'terpotong'] as $isi => $kasus) {
            $sumber = $this->tulis('baru.tmp', $isi);
            try {
                $this->pengganti()->pasang($sumber, $this->tujuan(), null, $opsi);
                $this->fail("Sumber {$kasus} harus ditolak.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('bukan berkas yang valid', $e->getMessage());
            }
            $this->assertSame('%PDF-lama', file_get_contents($this->tujuan()));
            $this->assertSame([], $this->sisaBerkas('notula.pdf'));
        }

        $sumber = $this->tulis('baru.tmp', "%PDF-1.7\nisi\n%%EOF\n");
        $this->pengganti()->pasang($sumber, $this->tujuan(), null, $opsi);
        $this->assertStringStartsWith('%PDF-1.7', file_get_contents($this->tujuan()));
    }

    // ---------------------------------------------------------------- konkurensi (dalam proses)

    /**
     * Operasi B atas berkas yang SAMA saat A masih di tengah transaksi DB-nya: B ditolak
     * (bukan menumpuk), A selesai utuh, dan tidak ada berkas A yang ikut terhapus oleh B.
     */
    public function test_15_operasi_kedua_atas_berkas_yang_sama_ditolak_selama_yang_pertama_berjalan(): void
    {
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumberA = $this->tulis('a.tmp', '%PDF-A');
        $sumberB = $this->tulis('b.tmp', '%PDF-B');
        $pesanB = null;

        $this->pengganti()->pasang($sumberA, $this->tujuan(), function () use ($sumberB, &$pesanB) {
            try {
                $this->pengganti(tungguKunciMs: 300)->pasang($sumberB, $this->tujuan(), fn () => $this->fail('B tidak boleh jalan.'));
            } catch (RuntimeException $e) {
                $pesanB = $e->getMessage();
            }
            $this->assertSame('%PDF-A', file_get_contents($this->tujuan()), 'B tidak boleh menyentuh berkas selama A berjalan.');
        });

        $this->assertStringContainsString('sedang diganti oleh proses lain', (string) $pesanB);
        $this->assertSame('%PDF-A', file_get_contents($this->tujuan()));
        $this->assertFileDoesNotExist($sumberB);
        $this->assertSame([], $this->sisaBerkas('notula.pdf'));
        $this->assertSame([], $this->jurnal());
    }

    public function test_jurnal_mencatat_tahap_sebelum_tahap_berikutnya(): void
    {
        $this->tulis('notula.pdf', '%PDF-lama');
        $sumber = $this->tulis('baru.tmp', '%PDF-baru');
        $p = $this->pengganti();
        $tercatat = [];
        $p->diTitik = function (string $nama, array $j) use (&$tercatat) {
            $isi = json_decode(file_get_contents($this->jurnal()[0]), true);
            $tercatat[$nama] = $isi['tahap'];
        };

        $p->pasang($sumber, $this->tujuan(), null, ['verifikasi' => ['model' => 'X', 'id' => 1, 'sebelum' => [], 'sesudah' => []]]);

        $this->assertSame([
            'lama_dicadangkan' => PenggantiBerkasAman::TAHAP_DISIAPKAN,
            'baru_dipindahkan' => PenggantiBerkasAman::TAHAP_LAMA_DICADANGKAN,
            'baru_terpasang' => PenggantiBerkasAman::TAHAP_BARU_TERPASANG,
            'lanjutan_selesai' => PenggantiBerkasAman::TAHAP_BARU_TERPASANG,
            'db_selesai' => PenggantiBerkasAman::TAHAP_DB_SELESAI,
        ], $tercatat);
    }
}
