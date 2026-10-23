<?php

namespace Tests\Feature;

use App\Livewire\IsianBagianKustom;
use App\Livewire\PengisianKegiatan;
use App\Livewire\VerifikasiCapaian;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\LogAktivitas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Services\FolderStructureService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Tests\PenjagaDatabaseUji;
use Tests\TestCase;

/**
 * Log aktivitas sebagai JEJAK perubahan isian Bagian Kustom (poin, bukti dukung,
 * penandaan verifikasi). Tiap test memeriksa isi tabel log_aktivitas yang benar-benar
 * tertulis, bukan sekadar bahwa sebuah metode dipanggil.
 */
class LogAktivitasBagianKustomTest extends TestCase
{
    use PenjagaDatabaseUji;
    use RefreshDatabase;

    protected MasterIku $ikuA;

    protected MasterIku $ikuB;

    protected Periode $periode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pastikanDatabaseUji();

        foreach (['Ketua Tim', 'Tim SAKIP', 'Kepala'] as $nama) {
            Role::firstOrCreate(['nama' => $nama]);
        }

        $this->ikuA = MasterIku::create(['kode' => 'LOG-A', 'indikator' => 'Indikator A', 'tim' => 'Tim Satu', 'penanggung_jawab' => 'A']);
        $this->ikuB = MasterIku::create(['kode' => 'LOG-B', 'indikator' => 'Indikator B', 'tim' => 'Tim Lain', 'penanggung_jawab' => 'B']);
        $this->periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
    }

    protected function pengguna(string $peran, string $nama = 'Pengguna Uji'): User
    {
        return User::create([
            'nama' => $nama, 'username' => 'u-'.uniqid(), 'email' => 'u-'.uniqid().'@example.test', 'password' => 'password',
            'role_id' => Role::where('nama', $peran)->value('id'), 'status_verifikasi' => 'terverifikasi',
        ]);
    }

    protected function sebagai(string $peran, string $nama = 'Pengguna Uji'): User
    {
        $user = $this->pengguna($peran, $nama);
        $this->actingAs($user);

        return $user;
    }

    protected function bagian(array $extra = []): BagianKustom
    {
        return BagianKustom::create($extra + [
            'nama' => 'Manajemen Risiko', 'aktif' => true, 'bukti_wajib' => false,
            'peran' => ['ketua_tim', 'tim_sakip', 'kepala'],
            'penempatan' => ['pengisian', 'isian_bagian_kustom', 'persetujuan'],
            'kolom' => [['kunci' => 'skor', 'label' => 'Skor Risiko', 'tipe' => 'angka', 'opsi' => [], 'wajib' => false]],
        ]);
    }

    protected function poin(BagianKustom $bagian, string $teks, ?string $menu, ?string $peran, array $extra = []): BagianKustomPoin
    {
        return BagianKustomPoin::create([
            'bagian_kustom_id' => $bagian->id, 'iku_id' => $this->ikuA->id, 'periode_id' => $this->periode->id,
            'teks' => $teks, 'menu' => $menu, 'peran_pengisi' => $peran,
        ] + $extra);
    }

    protected function isian(string $menu = 'isian_bagian_kustom')
    {
        return Livewire::test(IsianBagianKustom::class, ['menu' => $menu])->set('tahun', 2026)->set('bulan', 4);
    }

    protected function pengisian(MasterIku $iku)
    {
        return Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 4)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji')
            ->set('blocks.0.jenis', 'bukan_survei_sensus');
    }

    protected function driveTiruan(?string $driveId): void
    {
        $this->mock(FolderStructureService::class, function ($mock) use ($driveId) {
            $mock->shouldIgnoreMissing();
            // Bukti Bagian Kustom diunggah lewat Berkas::unggahKeDrive() → unggahBerkasBagianKustom().
            $unggah = $mock->shouldReceive('unggahBerkasBagianKustom');
            $driveId === null
                ? $unggah->andThrow(new RuntimeException('Drive tidak terjangkau'))
                : $unggah->andReturn(['drive_file_id' => $driveId, 'storage_account_id' => null]);
        });
    }

    /* ---------- 9: penambahan poin ---------- */

    public function test_menambah_poin_mencatat_pelaku_dari_server_dan_lokasi_yang_benar(): void
    {
        $bagian = $this->bagian();
        $user = $this->sebagai('Tim SAKIP', 'Sari SAKIP');

        $this->isian()
            ->set("baris.{$bagian->id}.0.iku_id", (string) $this->ikuA->id)
            ->set("baris.{$bagian->id}.0.teks", 'Risiko keterlambatan data')
            ->call('tambahBaris', $bagian->id)
            ->set("baris.{$bagian->id}.1.iku_id", (string) $this->ikuB->id)
            ->set("baris.{$bagian->id}.1.teks", 'Risiko kualitas data')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $log = LogAktivitas::where('aksi', 'bagian_kustom_poin.dibuat')->orderBy('id')->get();
        $this->assertCount(2, $log);

        $pertama = $log->first();
        $this->assertSame($user->id, $pertama->user_id);
        $this->assertSame('Sari SAKIP', $pertama->nama_pengguna);
        $this->assertSame('Tim SAKIP', $pertama->peran);
        $this->assertSame(LogAktivitas::STATUS_BERHASIL, $pertama->status);
        $this->assertSame('isian_bagian_kustom', $pertama->menu);
        $this->assertSame($bagian->id, $pertama->bagian_kustom_id);
        $this->assertSame($this->ikuA->id, $pertama->iku_id);
        $this->assertSame($this->periode->id, $pertama->periode_id);
        $this->assertSame(
            'Tim SAKIP menambahkan poin isian "Manajemen Risiko" pada IKU LOG-A (Indikator A), April 2026 (Triwulan II).',
            $pertama->keterangan,
        );
        $this->assertSame('Risiko keterlambatan data', $pertama->perubahan['sesudah']['teks']);

        // Dua poin dari satu kali simpan diikat kode korelasi yang sama.
        $this->assertNotNull($pertama->korelasi);
        $this->assertSame($pertama->korelasi, $log->last()->korelasi);
    }

    /* ---------- 10 & 2: perubahan nilai/uraian & IKU tetap bebas dipilih ---------- */

    public function test_mengubah_uraian_kolom_dan_iku_mencatat_sebelum_dan_sesudah(): void
    {
        $bagian = $this->bagian();
        $poin = $this->poin($bagian, 'Uraian lama', 'isian_bagian_kustom', 'tim_sakip', ['nilai_kolom' => ['skor' => '3'], 'status_verifikasi' => 'terverifikasi']);
        $this->sebagai('Tim SAKIP');

        // IKU B milik tim lain — tetap boleh dipilih; perubahannya cukup tercatat.
        $this->isian()
            ->set("baris.{$bagian->id}.0.iku_id", (string) $this->ikuB->id)
            ->set("baris.{$bagian->id}.0.teks", 'Uraian baru')
            ->set("baris.{$bagian->id}.0.kolom.skor", '5')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $this->assertSame($this->ikuB->id, $poin->fresh()->iku_id);

        $log = LogAktivitas::where('aksi', 'bagian_kustom_poin.diubah')->sole();
        $this->assertSame($poin->id, $log->objek_id);
        $this->assertSame(['sebelum' => $this->ikuA->id, 'sesudah' => $this->ikuB->id], $log->perubahan['iku_id']);
        $this->assertSame(['sebelum' => 'Uraian lama', 'sesudah' => 'Uraian baru'], $log->perubahan['teks']);
        $this->assertSame('3', $log->perubahan['nilai_kolom']['sebelum']['skor']);
        $this->assertSame('5', $log->perubahan['nilai_kolom']['sesudah']['skor']);
        $this->assertStringContainsString('mengganti IKU dari IKU LOG-A (Indikator A) menjadi IKU LOG-B (Indikator B)', $log->keterangan);
        $this->assertStringContainsString('mengubah uraian', $log->keterangan);
        $this->assertStringContainsString('"Skor Risiko"', $log->keterangan);
    }

    public function test_ketua_tim_bebas_memilih_iku_mana_pun_di_isian_kegiatan(): void
    {
        $bagian = $this->bagian();
        $this->sebagai('Ketua Tim');

        // Ketua Tim tanpa keanggotaan tim / penugasan IKU apa pun.
        $this->pengisian($this->ikuB)
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Poin pada IKU tim lain')
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $poin = BagianKustomPoin::where('teks', 'Poin pada IKU tim lain')->sole();
        $this->assertSame($this->ikuB->id, $poin->iku_id);

        $log = LogAktivitas::where('aksi', 'bagian_kustom_poin.dibuat')->sole();
        $this->assertSame('Ketua Tim', $log->peran);
        $this->assertSame('pengisian', $log->menu);
        $this->assertStringContainsString('IKU LOG-B', $log->keterangan);
    }

    /* ---------- 18: tidak ada catatan ganda ---------- */

    public function test_simpan_ulang_tanpa_perubahan_tidak_menambah_catatan(): void
    {
        $bagian = $this->bagian();
        $this->sebagai('Tim SAKIP');

        $layar = $this->isian()
            ->set("baris.{$bagian->id}.0.iku_id", (string) $this->ikuA->id)
            ->set("baris.{$bagian->id}.0.teks", 'Poin tetap')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $layar->call('simpan', $bagian->id)->assertHasNoErrors();
        $layar->call('simpan', $bagian->id)->assertHasNoErrors();

        $this->assertSame(1, LogAktivitas::count());
        $this->assertSame('bagian_kustom_poin.dibuat', LogAktivitas::sole()->aksi);
    }

    /* ---------- 11: penghapusan ---------- */

    public function test_menghapus_poin_mencatat_isi_poin_yang_dihapus(): void
    {
        $bagian = $this->bagian();
        $poin = $this->poin($bagian, 'Catatan yang akan dihapus', 'persetujuan', 'kepala', ['status_verifikasi' => 'terverifikasi']);
        $this->sebagai('Kepala');

        $this->isian('persetujuan')
            ->call('hapusBaris', $bagian->id, 0)
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('bagian_kustom_poin', ['id' => $poin->id]);

        $log = LogAktivitas::where('aksi', 'bagian_kustom_poin.dihapus')->sole();
        $this->assertSame($poin->id, $log->objek_id);
        $this->assertSame('Kepala', $log->peran);
        $this->assertSame('Catatan yang akan dihapus', $log->perubahan['sebelum']['teks']);
        $this->assertSame('Kepala menghapus poin isian "Manajemen Risiko" pada IKU LOG-A (Indikator A), April 2026 (Triwulan II).', $log->keterangan);
    }

    /* ---------- 16: transaksi gagal → tidak ada catatan berhasil ---------- */

    public function test_transaksi_gagal_tidak_meninggalkan_catatan_berhasil(): void
    {
        $bagian = $this->bagian();
        $this->sebagai('Tim SAKIP');

        // Poin kedua gagal disimpan — seluruh transaksi (termasuk poin & log pertama) batal.
        BagianKustomPoin::creating(function (BagianKustomPoin $p) {
            if ($p->teks === 'Poin gagal') {
                throw new RuntimeException('galat basis data tiruan');
            }
        });

        $layar = $this->isian()
            ->set("baris.{$bagian->id}.0.iku_id", (string) $this->ikuA->id)
            ->set("baris.{$bagian->id}.0.teks", 'Poin pertama')
            ->call('tambahBaris', $bagian->id)
            ->set("baris.{$bagian->id}.1.iku_id", (string) $this->ikuA->id)
            ->set("baris.{$bagian->id}.1.teks", 'Poin gagal');

        try {
            $layar->call('simpan', $bagian->id);
            $this->fail('Seharusnya galat tiruan dilempar.');
        } catch (RuntimeException $e) {
            $this->assertSame('galat basis data tiruan', $e->getMessage());
        }

        $this->assertDatabaseCount('bagian_kustom_poin', 0);
        $this->assertSame(0, LogAktivitas::where('status', LogAktivitas::STATUS_BERHASIL)->count());
    }

    public function test_log_gagal_ditulis_membatalkan_perubahan_data(): void
    {
        $bagian = $this->bagian();
        $this->sebagai('Tim SAKIP');

        // Penulisan log gagal (mis. tabel penuh/terkunci) → seluruh penyimpanan batal,
        // tidak ada data tanpa jejak dan tidak ada pesan "tersimpan" palsu.
        LogAktivitas::creating(fn () => throw new RuntimeException('log tidak bisa ditulis'));

        $layar = $this->isian()
            ->set("baris.{$bagian->id}.0.iku_id", (string) $this->ikuA->id)
            ->set("baris.{$bagian->id}.0.teks", 'Poin tanpa jejak');

        try {
            $layar->call('simpan', $bagian->id);
            $this->fail('Galat log seharusnya diteruskan.');
        } catch (RuntimeException $e) {
            $this->assertSame('log tidak bisa ditulis', $e->getMessage());
        }

        $this->assertDatabaseCount('bagian_kustom_poin', 0);
        $this->assertDatabaseCount('log_aktivitas', 0);
    }

    public function test_log_penolakan_gagal_ditulis_tetap_tidak_mengubah_data(): void
    {
        $bagian = $this->bagian();
        $milikKepala = $this->poin($bagian, 'Catatan Kepala', 'persetujuan', 'kepala');
        $this->sebagai('Tim SAKIP');
        LogAktivitas::creating(fn () => throw new RuntimeException('log tidak bisa ditulis'));

        try {
            $this->isian()
                ->set("baris.{$bagian->id}.0.id", $milikKepala->id)
                ->set("baris.{$bagian->id}.0.iku_id", (string) $this->ikuA->id)
                ->set("baris.{$bagian->id}.0.teks", 'Ditimpa')
                ->call('simpan', $bagian->id);
            $this->fail('Galat log seharusnya diteruskan.');
        } catch (RuntimeException) {
        }

        $this->assertSame('Catatan Kepala', $milikKepala->fresh()->teks);
        $this->assertDatabaseCount('bagian_kustom_poin', 1);
    }

    /* ---------- 5 & bentrok: ditolak, bukan "berhasil" ---------- */

    public function test_bentrok_penyimpanan_dicatat_ditolak_tanpa_catatan_berhasil(): void
    {
        $bagian = $this->bagian();
        $this->poin($bagian, 'Versi awal', 'isian_bagian_kustom', 'tim_sakip', ['status_verifikasi' => 'terverifikasi']);
        $sakip1 = $this->pengguna('Tim SAKIP', 'Sakip Satu');
        $sakip2 = $this->pengguna('Tim SAKIP', 'Sakip Dua');

        $this->actingAs($sakip1);
        $layar1 = $this->isian();
        $this->actingAs($sakip2);
        $layar2 = $this->isian();

        $this->actingAs($sakip1);
        $layar1->set("baris.{$bagian->id}.0.teks", 'Versi pengguna 1')->call('simpan', $bagian->id)->assertHasNoErrors();

        $this->actingAs($sakip2);
        $layar2->set("baris.{$bagian->id}.0.teks", 'Versi pengguna 2')->call('simpan', $bagian->id)->assertHasErrors(["baris.{$bagian->id}"]);

        $berhasil = LogAktivitas::where('status', LogAktivitas::STATUS_BERHASIL)->get();
        $this->assertCount(1, $berhasil);
        $this->assertSame('Sakip Satu', $berhasil->first()->nama_pengguna);

        $ditolak = LogAktivitas::where('status', LogAktivitas::STATUS_DITOLAK)->sole();
        $this->assertSame('Sakip Dua', $ditolak->nama_pengguna);
        $this->assertStringContainsString('bentrok penyimpanan', $ditolak->keterangan);
    }

    /* ---------- 3: konteks dimanipulasi → ditolak & tercatat ---------- */

    public function test_manipulasi_id_poin_konteks_lain_ditolak_dan_tercatat(): void
    {
        $bagian = $this->bagian();
        $milikKepala = $this->poin($bagian, 'Catatan Kepala', 'persetujuan', 'kepala');
        $this->sebagai('Tim SAKIP');

        $this->isian()
            ->set("baris.{$bagian->id}.0.id", $milikKepala->id)
            ->set("baris.{$bagian->id}.0.iku_id", (string) $this->ikuA->id)
            ->set("baris.{$bagian->id}.0.teks", 'Ditimpa')
            ->call('simpan', $bagian->id)
            ->assertForbidden();

        $this->assertSame('Catatan Kepala', $milikKepala->fresh()->teks);

        $log = LogAktivitas::sole();
        $this->assertSame(LogAktivitas::STATUS_DITOLAK, $log->status);
        $this->assertStringContainsString('ditolak: konteks data tidak sesuai', $log->keterangan);
        $this->assertSame([$milikKepala->id], $log->perubahan['id_poin_dikirim']);
    }

    public function test_pengisi_yang_kehilangan_peran_ditolak_dan_tercatat(): void
    {
        $bagian = $this->bagian();
        $kepala = $this->sebagai('Kepala');

        $layar = $this->isian('persetujuan')
            ->set("baris.{$bagian->id}.0.iku_id", (string) $this->ikuA->id)
            ->set("baris.{$bagian->id}.0.teks", 'Catatan');

        $kepala->update(['role_id' => Role::where('nama', 'Ketua Tim')->value('id')]);
        $layar->call('simpan', $bagian->id)->assertForbidden();

        $this->assertDatabaseCount('bagian_kustom_poin', 0);
        $log = LogAktivitas::sole();
        $this->assertSame(LogAktivitas::STATUS_DITOLAK, $log->status);
        $this->assertSame('Ketua Tim', $log->peran);
        $this->assertStringContainsString('tidak berwenang', $log->keterangan);
    }

    /* ---------- 7: Isian Kegiatan — ditolak sebelum berkas diunggah ---------- */

    public function test_isian_kegiatan_id_poin_iku_lain_ditolak_sebelum_unggah_berkas(): void
    {
        Storage::fake('local');
        $bagian = $this->bagian();
        $milikB = BagianKustomPoin::create([
            'bagian_kustom_id' => $bagian->id, 'iku_id' => $this->ikuB->id, 'periode_id' => $this->periode->id,
            'teks' => 'Poin IKU B', 'menu' => 'pengisian', 'peran_pengisi' => 'ketua_tim',
        ]);
        $this->mock(FolderStructureService::class, function ($mock) {
            $mock->shouldIgnoreMissing();
            $mock->shouldNotReceive('unggahBerkas');
            $mock->shouldNotReceive('unggahBerkasKegiatan');
            $mock->shouldNotReceive('unggahBerkasBagianKustom');
        });
        $this->sebagai('Ketua Tim');

        // Memilih berkas langsung memicu simpan + unggah (perilaku produksi), jadi
        // penolakannya terjadi tepat saat berkas dipilih — sebelum apa pun tersimpan.
        $this->pengisian($this->ikuA)
            ->set("bagianKustomBlocks.{$bagian->id}.0.id", $milikB->id)
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Diretas')
            ->set("bagianKustomBlocks.{$bagian->id}.0.bukti", [UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf')])
            ->assertForbidden();

        $this->assertSame('Poin IKU B', $milikB->fresh()->teks);
        $this->assertSame(0, Berkas::count());
        $this->assertSame([], Storage::disk('local')->allFiles('bukti-bagian-kustom'));

        $log = LogAktivitas::sole();
        $this->assertSame(LogAktivitas::STATUS_DITOLAK, $log->status);
        $this->assertSame('pengisian', $log->menu);
        $this->assertSame($this->ikuA->id, $log->iku_id);
        $this->assertStringContainsString('konteks data tidak sesuai', $log->keterangan);
    }

    /* ---------- 13: unggah, ganti, hapus bukti ---------- */

    public function test_unggah_bukti_mencatat_status_drive_yang_sebenarnya(): void
    {
        Storage::fake('local');
        $bagian = $this->bagian();
        $this->driveTiruan(null); // Drive gagal
        $this->sebagai('Ketua Tim');

        $this->pengisian($this->ikuA)
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Poin dengan bukti')
            ->set("bagianKustomBlocks.{$bagian->id}.0.bukti", [UploadedFile::fake()->create('register risiko.pdf', 10, 'application/pdf')])
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $berkas = Berkas::where('ref_type', BagianKustomPoin::class)->sole();
        $log = LogAktivitas::where('aksi', 'bukti.diunggah')->sole();
        $poinLog = LogAktivitas::where('aksi', 'bagian_kustom_poin.dibuat')->sole();

        $this->assertSame(LogAktivitas::OBJEK_BERKAS, $log->objek_tipe);
        $this->assertSame($berkas->id, $log->objek_id);
        $this->assertStringContainsString('"'.$berkas->nama_file.'"', $log->keterangan);
        // Unggahan Drive berjalan SETELAH transaksi simpan: saat dicatat, berkas baru
        // tersimpan di server. Karena Drive gagal, tidak ada catatan "tersalin".
        $this->assertStringContainsString('salinan Google Drive BELUM ada saat dicatat', $log->keterangan);
        $this->assertFalse($log->perubahan['sesudah']['di_drive']);
        $this->assertSame($poinLog->korelasi, $log->korelasi);
        $this->assertNull($berkas->drive_file_id);
        $this->assertSame(0, LogAktivitas::where('aksi', 'bukti.tersinkron')->count());
    }

    public function test_unggah_bukti_yang_tersalin_ke_drive_disebut_tersalin(): void
    {
        Storage::fake('local');
        $bagian = $this->bagian();
        $this->driveTiruan('drive-123');
        $this->sebagai('Ketua Tim');

        $this->pengisian($this->ikuA)
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Poin dengan bukti')
            ->set("bagianKustomBlocks.{$bagian->id}.0.bukti", [UploadedFile::fake()->create('register.pdf', 10, 'application/pdf')])
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $berkas = Berkas::where('ref_type', BagianKustomPoin::class)->sole();
        $this->assertSame('drive-123', $berkas->drive_file_id);

        // Dua catatan sesuai urutan kejadian: tersimpan di server (di dalam transaksi
        // simpan), lalu tersalin ke Drive (setelah unggahan berhasil).
        $diunggah = LogAktivitas::where('aksi', 'bukti.diunggah')->sole();
        $this->assertFalse($diunggah->perubahan['sesudah']['di_drive']);

        $tersinkron = LogAktivitas::where('aksi', 'bukti.tersinkron')->sole();
        $this->assertSame($berkas->id, $tersinkron->objek_id);
        $this->assertSame(LogAktivitas::STATUS_BERHASIL, $tersinkron->status);
        $this->assertStringContainsString('tersalin ke Google Drive', $tersinkron->keterangan);
        $this->assertTrue($tersinkron->perubahan['sesudah']['di_drive']);
        $this->assertGreaterThanOrEqual($diunggah->id, $tersinkron->id);
    }

    protected function poinDenganBuktiDitolak(BagianKustom $bagian): array
    {
        $poin = $this->poin($bagian, 'Poin revisi', 'pengisian', 'ketua_tim', ['status_verifikasi' => 'ditolak', 'catatan' => 'perbaiki']);
        Capaian::create(['iku_id' => $this->ikuA->id, 'periode_id' => $this->periode->id, 'status' => Capaian::STATUS_DIKEMBALIKAN]);
        $berkas = Berkas::create([
            'ref_id' => $poin->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom',
            'nama_file' => 'lama.pdf', 'path' => 'bukti-bagian-kustom/lama.pdf', 'status_verifikasi' => 'ditolak', 'catatan' => 'Tanpa tanda tangan',
        ]);

        return [$poin, $berkas];
    }

    public function test_mengganti_bukti_mencatat_berkas_lama_dan_baru(): void
    {
        Storage::fake('local');
        $bagian = $this->bagian();
        [, $lama] = $this->poinDenganBuktiDitolak($bagian);
        $this->driveTiruan('drive-baru');
        $this->sebagai('Ketua Tim');

        $this->pengisian($this->ikuA)
            ->set("gantiBerkas.{$lama->id}", UploadedFile::fake()->create('pengganti.pdf', 10, 'application/pdf'))
            ->assertHasNoErrors();

        $this->assertNull(Berkas::find($lama->id));
        $baru = Berkas::where('nama_file', 'pengganti.pdf')->sole();

        $log = LogAktivitas::where('aksi', 'bukti.diganti')->sole();
        $this->assertSame($baru->id, $log->objek_id);
        $this->assertSame('lama.pdf', $log->perubahan['sebelum']['nama_file']);
        $this->assertSame('pengganti.pdf', $log->perubahan['sesudah']['nama_file']);
        $this->assertSame('menunggu', $log->perubahan['sesudah']['status_verifikasi']);
        $this->assertStringContainsString('mengganti bukti dukung "lama.pdf" (ditandai Tidak Sesuai) dengan "pengganti.pdf"', $log->keterangan);
    }

    public function test_menghapus_bukti_mencatat_nama_berkas(): void
    {
        Storage::fake('local');
        $bagian = $this->bagian();
        [$poin, $berkas] = $this->poinDenganBuktiDitolak($bagian);
        $this->sebagai('Ketua Tim');

        $this->pengisian($this->ikuA)->call('hapusBuktiLamaBagianKustom', $poin->id, $berkas->id);

        $this->assertNull(Berkas::find($berkas->id));
        $log = LogAktivitas::where('aksi', 'bukti.dihapus')->sole();
        $this->assertSame($berkas->id, $log->objek_id);
        $this->assertSame('lama.pdf', $log->perubahan['sebelum']['nama_file']);
        // Berkas ini belum pernah masuk Drive; bila sudah, salinannya ikut dihapus permanen
        // (lihat DriveKonsistensiBuktiTest::test_hapus_bukti_berhasil_...).
        $this->assertStringContainsString('belum punya salinan di Google Drive', $log->keterangan);
    }

    /* ---------- 14 & 6: penandaan verifikasi & peran dicabut ---------- */

    public function test_penandaan_sesuai_dan_tidak_sesuai_tercatat_sesuai_hasil(): void
    {
        $bagian = $this->bagian();
        $poin = $this->poin($bagian, 'Poin diperiksa', 'pengisian', 'ketua_tim');
        $capaian = Capaian::create(['iku_id' => $this->ikuA->id, 'periode_id' => $this->periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);
        $this->sebagai('Tim SAKIP');

        $layar = Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian])
            ->set("catatanBagianKustom.{$poin->id}", 'Lengkapi mitigasi')
            ->call('tandaiBagianKustomTolak', $poin->id)
            ->call('tandaiBagianKustomTolak', $poin->id); // diulang, sama persis

        $tolak = LogAktivitas::where('aksi', 'bagian_kustom_poin.ditandai_tidak_sesuai')->sole();
        $this->assertSame(['sebelum' => 'menunggu', 'sesudah' => 'ditolak'], $tolak->perubahan['status_verifikasi']);
        $this->assertSame('Lengkapi mitigasi', $tolak->perubahan['catatan']['sesudah']);
        $this->assertStringContainsString('sebagai "Tidak Sesuai" dengan catatan revisi', $tolak->keterangan);

        $layar->call('tandaiBagianKustomSesuai', $poin->id);

        $sesuai = LogAktivitas::where('aksi', 'bagian_kustom_poin.ditandai_sesuai')->sole();
        $this->assertSame(['sebelum' => 'ditolak', 'sesudah' => 'terverifikasi'], $sesuai->perubahan['status_verifikasi']);
        $this->assertSame('terverifikasi', $poin->fresh()->status_verifikasi);
    }

    public function test_tim_sakip_yang_perannya_dicabut_tidak_bisa_menandai_poin(): void
    {
        $bagian = $this->bagian();
        $poin = $this->poin($bagian, 'Poin diperiksa', 'pengisian', 'ketua_tim');
        $capaian = Capaian::create(['iku_id' => $this->ikuA->id, 'periode_id' => $this->periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);
        $user = $this->sebagai('Tim SAKIP');

        $layar = Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian]);
        $user->update(['role_id' => Role::where('nama', 'Ketua Tim')->value('id')]);

        $layar->call('tandaiBagianKustomSesuai', $poin->id)->assertForbidden();

        $this->assertSame('menunggu', $poin->fresh()->status_verifikasi);
        $this->assertSame(Capaian::STATUS_DIAJUKAN, $capaian->fresh()->status);
        // Ditolak di boot() — pemeriksaan peran di SETIAP request halaman Verifikasi,
        // sebelum aksi apa pun dijalankan.
        $log = LogAktivitas::sole();
        $this->assertSame(LogAktivitas::STATUS_DITOLAK, $log->status);
        $this->assertSame('akses.ditolak', $log->aksi);
        $this->assertStringContainsString('halaman Verifikasi', $log->keterangan);
        $this->assertSame($user->id, $log->user_id);
    }

    /* ---------- 15, 17, 19, 20: integritas catatan ---------- */

    public function test_identitas_pelaku_tidak_bisa_dipalsukan_lewat_konteks(): void
    {
        $user = $this->sebagai('Ketua Tim', 'Asli');

        $log = LogAktivitas::catat('uji.aksi', 'Uji', [
            'user_id' => 999, 'nama_pengguna' => 'Palsu', 'peran' => 'Tim SAKIP', 'status' => 'berhasil', 'iku_id' => $this->ikuA->id,
        ], LogAktivitas::STATUS_DITOLAK);

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('Asli', $log->nama_pengguna);
        $this->assertSame('Ketua Tim', $log->peran);
        $this->assertSame(LogAktivitas::STATUS_DITOLAK, $log->status);
        $this->assertSame($this->ikuA->id, $log->iku_id);
    }

    public function test_catatan_tidak_bisa_diubah_maupun_dihapus_lewat_model(): void
    {
        $this->sebagai('Tim SAKIP');
        $log = LogAktivitas::catat('uji.aksi', 'Keterangan asli');

        try {
            $log->update(['keterangan' => 'Diubah']);
            $this->fail('Perubahan catatan seharusnya ditolak.');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('Penghapusan catatan seharusnya ditolak.');
        } catch (LogicException) {
        }

        $this->assertSame('Keterangan asli', LogAktivitas::sole()->keterangan);

        // Perlindungan tingkat database (trigger, migrasi 2026_10_22_100002): query
        // langsung lewat query builder juga ditolak — di SQLite maupun PostgreSQL.
        foreach ([
            fn () => DB::table('log_aktivitas')->where('id', $log->id)->update(['keterangan' => 'Lewat query langsung']),
            fn () => DB::table('log_aktivitas')->where('id', $log->id)->delete(),
        ] as $percobaan) {
            try {
                DB::transaction($percobaan);
                $this->fail('Query langsung ke log_aktivitas seharusnya ditolak trigger.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('log_aktivitas hanya boleh ditambah', $e->getMessage());
            }
        }

        $this->assertSame('Keterangan asli', LogAktivitas::sole()->keterangan);
    }

    public function test_menghapus_poin_atau_bagian_tidak_menghapus_riwayat(): void
    {
        $bagian = $this->bagian();
        $poin = $this->poin($bagian, 'Poin', 'isian_bagian_kustom', 'tim_sakip', ['status_verifikasi' => 'terverifikasi']);
        $this->sebagai('Tim SAKIP');

        $this->isian()->call('hapusBaris', $bagian->id, 0)->call('simpan', $bagian->id)->assertHasNoErrors();
        $bagian->delete();

        $log = LogAktivitas::where('aksi', 'bagian_kustom_poin.dihapus')->sole();
        $this->assertSame($bagian->id, $log->bagian_kustom_id);
        $this->assertSame($poin->id, $log->objek_id);
    }

    public function test_keterangan_tetap_benar_setelah_nama_bagian_dan_iku_berubah(): void
    {
        $bagian = $this->bagian();
        $this->sebagai('Tim SAKIP');

        $this->isian()
            ->set("baris.{$bagian->id}.0.iku_id", (string) $this->ikuA->id)
            ->set("baris.{$bagian->id}.0.teks", 'Poin')
            ->call('simpan', $bagian->id);

        $bagian->update(['nama' => 'Nama Bagian Baru']);
        $this->ikuA->update(['kode' => 'KODE-BARU', 'indikator' => 'Indikator baru']);

        $log = LogAktivitas::sole();
        $this->assertStringContainsString('"Manajemen Risiko"', $log->keterangan);
        $this->assertStringContainsString('IKU LOG-A (Indikator A)', $log->keterangan);
        $this->assertStringNotContainsString('Nama Bagian Baru', $log->keterangan);
    }

    public function test_rahasia_dibuang_dan_teks_panjang_dipotong(): void
    {
        $this->sebagai('Tim SAKIP');

        $log = LogAktivitas::catat('uji.aksi', 'Uji', ['perubahan' => [
            'password' => 'rahasia', 'google_refresh_token' => 'tok', 'api_key' => 'k', 'session_id' => 's',
            'teks' => str_repeat('a', 2000),
            'bersarang' => ['access_token' => 'x', 'nama_file' => 'a.pdf'],
        ]]);

        $simpanan = DB::table('log_aktivitas')->where('id', $log->id)->value('perubahan');
        foreach (['rahasia', '"tok"', 'refresh_token', 'access_token', 'password', 'api_key', 'session_id'] as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $simpanan);
        }

        $this->assertSame(503, mb_strlen($log->perubahan['teks'])); // 500 + "..."
        $this->assertSame(['nama_file' => 'a.pdf'], $log->perubahan['bersarang']);
    }
}
