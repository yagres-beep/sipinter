<?php

namespace Tests\Integrasi;

use App\Livewire\PengisianKegiatan;
use App\Models\Berkas;
use App\Models\FolderConfig;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\RtlEvaluasi;
use App\Models\SalinanBuktiRtl;
use App\Models\StorageAccount;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Uji integrasi Google Drive SUNGGUHAN untuk salinan bukti Kegiatan → Evaluasi-RTL.
 * TIDAK ikut suite biasa (phpunit.xml hanya Unit & Feature) dan dilewati otomatis
 * bila kredensial uji tidak diberikan. JANGAN pakai akun/folder dinas.
 *
 * Variabel lingkungan:
 *   DRIVE_UJI_FOLDER_ID       ID folder uji (kosong, khusus uji) yang bisa ditulis kredensial uji
 *   DRIVE_UJI_KONFIRMASI      harus persis "folder-uji-tanpa-data-dinas"
 *   Salah satu kredensial uji:
 *     DRIVE_UJI_OAUTH_CLIENT_ID, DRIVE_UJI_OAUTH_CLIENT_SECRET, DRIVE_UJI_REFRESH_TOKEN
 *     atau DRIVE_UJI_SERVICE_ACCOUNT (path JSON; folder uji harus di Shared Drive,
 *     karena Service Account tidak punya kuota di My Drive)
 *
 * Jalankan: php vendor/bin/phpunit tests/Integrasi/SalinanBuktiRtlDriveNyataTest.php
 * Semua isi uji dibuat di subfolder "sipinter-uji-<waktu>" lalu dibuang ke Sampah.
 */
class SalinanBuktiRtlDriveNyataTest extends TestCase
{
    use RefreshDatabase;

    protected GoogleDriveService $drive;

    protected string $folderJalan;

    protected function setUp(): void
    {
        parent::setUp();

        $root = (string) getenv('DRIVE_UJI_FOLDER_ID');
        $oauth = getenv('DRIVE_UJI_REFRESH_TOKEN');
        $sa = getenv('DRIVE_UJI_SERVICE_ACCOUNT');

        if ($root === '' || (! $oauth && ! $sa)) {
            $this->markTestSkipped('Kredensial & folder Drive UJI tidak diberikan (lihat docblock kelas).');
        }

        if (getenv('DRIVE_UJI_KONFIRMASI') !== 'folder-uji-tanpa-data-dinas') {
            $this->fail('Set DRIVE_UJI_KONFIRMASI=folder-uji-tanpa-data-dinas untuk menegaskan folder ini bukan folder dinas.');
        }

        if ($root === (string) env('GOOGLE_DRIVE_FOLDER_ID')) {
            $this->fail('DRIVE_UJI_FOLDER_ID sama dengan folder Drive aplikasi — uji ditolak.');
        }

        // Kredensial aplikasi TIDAK dipakai sama sekali.
        config([
            'services.google_drive.oauth_client_id' => getenv('DRIVE_UJI_OAUTH_CLIENT_ID') ?: null,
            'services.google_drive.oauth_client_secret' => getenv('DRIVE_UJI_OAUTH_CLIENT_SECRET') ?: null,
            'services.google_drive.service_account_path' => $sa ?: '/tidak-ada',
        ]);

        $akunUji = StorageAccount::create(array_filter([
            'email_gmail_institusi' => 'akun-uji@example.test',
            'status' => StorageAccount::STATUS_AKTIF,
            'drive_folder_id' => $root,
            'google_refresh_token' => $oauth ?: null,
        ]));

        $this->drive = app(GoogleDriveService::class);
        $this->folderJalan = $this->drive->createFolder('sipinter-uji-'.now()->format('Ymd-His'), $root);
        $akunUji->update(['drive_folder_id' => $this->folderJalan]);
    }

    protected function tearDown(): void
    {
        if (isset($this->folderJalan)) {
            try {
                $this->drive->trashFile($this->folderJalan);
            } catch (\Throwable) {
                // Dibiarkan — folder uji bisa dibuang manual.
            }
        }

        parent::tearDown();
    }

    /** ID folder bernama $nama langsung di dalam $induk (gagal bila tidak ada). */
    protected function cari(?string $induk, string $nama): string
    {
        $this->assertNotNull($induk, "Folder induk untuk \"{$nama}\" tidak ditemukan.");

        foreach ($this->drive->foldersInFolder($induk) as $id => $n) {
            if ($n === $nama) {
                return $id;
            }
        }

        $this->fail("Folder \"{$nama}\" tidak ditemukan di Drive uji.");
    }

    public function test_salin_ganti_nama_coba_lagi_dan_hapus_di_drive_nyata(): void
    {
        $this->actingAs(User::create([
            'nama' => 'Ketua Uji', 'username' => 'ketua-uji-drive', 'email' => 'ketua-uji-drive@example.test',
            'password' => 'password', 'role_id' => Role::create(['nama' => 'Ketua Tim'])->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $config = FolderConfig::current();
        $pola = $config->pola_json;
        foreach ($pola['kategori'] as $i => $k) {
            if ($k['nama'] === 'Evaluasi-RTL') {
                $pola['kategori'][$i]['subfolder_per_kegiatan'] = true;
            }
        }
        $config->update(['pola_json' => $pola]);

        $iku = MasterIku::create(['kode' => 'UJI-DRV', 'indikator' => 'Uji Drive', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $juli = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $poin = RtlEvaluasi::create(['iku_id' => $iku->id, 'periode_id' => $juli->id, 'rtl_teks' => 'Rapat uji', 'pic' => 'Uji', 'batas_waktu' => '2026-09-30', 'status_dokumen' => 'diajukan']);

        $form = fn () => Livewire::test(PengisianKegiatan::class)->set('tahun', 2026)->set('bulan', 8)->set('iku_id', $iku->id);

        $form()->call('pilihRtlUntukBlock', 0, $poin->id)
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->createWithContent('undangan.pdf', '%PDF-1.4 uji drive nyata')])
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $sumber = Berkas::sole();
        $salinan = SalinanBuktiRtl::sole();
        $this->assertNotNull($sumber->drive_file_id, 'Berkas sumber tidak masuk Drive uji.');
        $this->assertSame(SalinanBuktiRtl::STATUS_TERSALIN, $salinan->status, (string) $salinan->galat);
        $this->assertNotSame($sumber->drive_file_id, $salinan->drive_file_id);

        // Isi identik & letak di folder Evaluasi-RTL/<poin>.
        $this->assertSame(
            $this->drive->downloadFileContent($sumber->drive_file_id),
            $this->drive->downloadFileContent($salinan->drive_file_id),
        );
        $tahun = $this->cari($this->folderJalan, '2026');
        $triwulan = $this->cari($tahun, 'Triwulan III');
        $folderIku = $this->cari($triwulan, 'UJI-DRV. Uji Drive');
        $folderPoin = $this->cari($this->cari($folderIku, 'Evaluasi-RTL'), 'Rapat uji');
        $this->assertContains('undangan.pdf', $this->drive->namesInFolder($folderPoin));

        // Penanda salinan bisa dicari & tidak bertabrakan dengan penanda berkas sumber.
        $this->assertSame($salinan->drive_file_id, $this->drive->cariBerkasBerpenanda('sipinter_kode', "{$sumber->kode}~RTL{$poin->id}")['id'] ?? null);
        $this->assertSame($sumber->drive_file_id, $this->drive->cariBerkasBerpenanda('sipinter_kode', $sumber->kode)['id'] ?? null);

        // Ganti nama ikut ke salinan.
        $sumber->gantiNama('Undangan Rapat');
        $this->assertSame('Undangan Rapat.pdf', $this->drive->namaFile($salinan->drive_file_id));

        // Catatan salinan hilang → coba lagi memakai salinan berpenanda, tidak dobel.
        $idSalinan = $salinan->drive_file_id;
        $salinan->update(['status' => SalinanBuktiRtl::STATUS_GAGAL, 'drive_file_id' => null, 'sumber_drive_file_id' => null]);
        $this->artisan('bukti:sinkron-rtl')->assertSuccessful();
        $this->assertSame($idSalinan, $salinan->fresh()->drive_file_id);
        $this->assertSame(['Undangan Rapat.pdf'], $this->drive->namesInFolder($folderPoin));

        // Hapus dari Kegiatan → sumber & salinan terhapus.
        $form()->call('hapusBuktiLama', 0, $sumber->id);
        $this->assertModelMissing($sumber);
        $this->assertSame([], $this->drive->namesInFolder($folderPoin));
        $this->assertSame(0, Kegiatan::sole()->berkas()->count());
    }
}
