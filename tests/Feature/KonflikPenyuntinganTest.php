<?php

namespace Tests\Feature;

use App\Livewire\BagianKustomManager;
use App\Livewire\PengisianKegiatan;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\LogAktivitas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\RiwayatKonfigurasiBagianKustom;
use App\Models\Role;
use App\Models\User;
use App\Services\FolderStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\PenjagaDatabaseUji;
use Tests\TestCase;

/**
 * Optimistic locking: penyimpanan yang berangkat dari data yang sudah diubah pengguna
 * lain dibatalkan SELURUHNYA (tidak ada simpan sebagian), isian pengguna tetap di layar,
 * dan tidak ada data ganda / berkas yatim.
 */
class KonflikPenyuntinganTest extends TestCase
{
    use PenjagaDatabaseUji;
    use RefreshDatabase;

    protected MasterIku $iku;

    protected Periode $periode;

    protected BagianKustom $bagian;

    protected User $ketuaA;

    protected User $ketuaB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pastikanDatabaseUji();
        Storage::fake('local');

        foreach (['Ketua Tim', 'Tim SAKIP', 'Kepala'] as $nama) {
            Role::firstOrCreate(['nama' => $nama]);
        }

        $this->iku = MasterIku::create(['kode' => 'KON-1', 'indikator' => 'Indikator', 'tim' => 'Tim', 'penanggung_jawab' => 'A']);
        $this->periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
        $this->bagian = BagianKustom::create([
            'nama' => 'Manajemen Risiko', 'aktif' => true, 'bukti_wajib' => false,
            'peran' => ['ketua_tim'], 'penempatan' => ['pengisian'], 'kolom' => [],
        ]);
        $this->ketuaA = $this->pengguna('Ketua Tim', 'Ketua A');
        $this->ketuaB = $this->pengguna('Ketua Tim', 'Ketua B');
    }

    protected function pengguna(string $peran, string $nama): User
    {
        return User::create([
            'nama' => $nama, 'username' => 'u-'.uniqid(), 'email' => 'u-'.uniqid().'@example.test', 'password' => 'password',
            'role_id' => Role::where('nama', $peran)->value('id'), 'status_verifikasi' => 'terverifikasi',
        ]);
    }

    protected function layar(User $user)
    {
        $this->actingAs($user);

        return Livewire::test(PengisianKegiatan::class)->set('tahun', 2026)->set('bulan', 4)->set('iku_id', $this->iku->id);
    }

    protected function poin(string $teks): BagianKustomPoin
    {
        return BagianKustomPoin::create([
            'bagian_kustom_id' => $this->bagian->id, 'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id,
            'teks' => $teks, 'menu' => 'pengisian', 'peran_pengisi' => 'ketua_tim',
        ]);
    }

    public function test_dua_ketua_tim_menyunting_poin_yang_sama_yang_kedua_dibatalkan_seluruhnya(): void
    {
        $poin = $this->poin('Versi awal');
        $this->mock(FolderStructureService::class, fn ($m) => $m->shouldNotReceive('unggahBerkas', 'unggahBerkasKegiatan'));
        $b = $this->layar($this->ketuaB);
        $a = $this->layar($this->ketuaA);

        $a->set("bagianKustomBlocks.{$this->bagian->id}.0.teks", 'Versi A')
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan dari A')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->call('simpanDraft')
            ->assertHasNoErrors();
        $this->assertSame('Versi A', $poin->fresh()->teks);

        $this->actingAs($this->ketuaB);
        $b->set("bagianKustomBlocks.{$this->bagian->id}.0.teks", 'Versi B')
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan dari B')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set("bagianKustomBlocks.{$this->bagian->id}.0.bukti", [UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf')])
            ->call('simpanDraft')
            ->assertHasErrors('bentrok')
            ->assertNoRedirect();

        // Tidak ada yang tersimpan dari B — termasuk Kegiatan yang tidak bentrok.
        $this->assertSame('Versi A', $poin->fresh()->teks);
        $this->assertSame(['Kegiatan dari A'], Kegiatan::pluck('uraian_kegiatan')->all());
        $this->assertSame(0, Berkas::count());
        $this->assertSame([], Storage::disk('local')->allFiles('bukti-bagian-kustom'));

        // Isian B tetap di layar.
        $this->assertSame('Versi B', $b->get("bagianKustomBlocks.{$this->bagian->id}.0.teks"));
        $this->assertSame('Kegiatan dari B', $b->get('blocks.0.uraian_kegiatan'));
        $this->assertNull($b->get('blocks.0.id'));
        $this->assertArrayHasKey('poin', $b->get('bentrok'));

        $log = LogAktivitas::where('aksi', 'isian_kegiatan.simpan')->sole();
        $this->assertSame(LogAktivitas::STATUS_DITOLAK, $log->status);
        $this->assertSame($this->ketuaB->id, $log->user_id);
        $this->assertStringContainsString('bentrok', $log->keterangan);
    }

    public function test_muat_ulang_bagian_bentrok_mempertahankan_isian_baru_lalu_simpan_berhasil_tanpa_ganda(): void
    {
        $poin = $this->poin('Versi awal');
        $b = $this->layar($this->ketuaB);
        $a = $this->layar($this->ketuaA);
        $a->set("bagianKustomBlocks.{$this->bagian->id}.0.teks", 'Versi A')
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan dari A')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->call('simpanDraft')->assertHasNoErrors();

        $this->actingAs($this->ketuaB);
        $b->set("bagianKustomBlocks.{$this->bagian->id}.0.teks", 'Versi B')
            ->call('addBagianKustomBlock', $this->bagian->id)
            ->set("bagianKustomBlocks.{$this->bagian->id}.1.teks", 'Poin baru B')
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan dari B')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->call('simpanDraft')
            ->assertHasErrors('bentrok');

        $b->call('muatUlangBagianBentrok')->assertHasNoErrors();

        $this->assertSame('Versi A', $b->get("bagianKustomBlocks.{$this->bagian->id}.0.teks"));
        $this->assertSame('Poin baru B', $b->get("bagianKustomBlocks.{$this->bagian->id}.1.teks"));
        $this->assertSame('Kegiatan dari B', $b->get('blocks.0.uraian_kegiatan'), 'bagian yang tidak bentrok tidak dimuat ulang');
        $this->assertSame([], $b->get('bentrok'));

        $b->call('simpanDraft')->assertHasNoErrors()->assertRedirect();

        $this->assertSame('Versi A', $poin->fresh()->teks);
        $this->assertSame(2, BagianKustomPoin::count());
        $this->assertEqualsCanonicalizing(['Kegiatan dari A', 'Kegiatan dari B'], Kegiatan::pluck('uraian_kegiatan')->all());
    }

    public function test_kegiatan_yang_diubah_pengguna_lain_tidak_tertimpa(): void
    {
        $kegiatan = Kegiatan::create(['iku_id' => $this->iku->id, 'periode_id' => $this->periode->id, 'uraian_kegiatan' => 'Awal', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => Kegiatan::STATUS_DRAFT]);
        $b = $this->layar($this->ketuaB);
        $a = $this->layar($this->ketuaA);

        $a->set('blocks.0.uraian_kegiatan', 'Ubahan A')->call('simpanDraft')->assertHasNoErrors();

        $this->actingAs($this->ketuaB);
        $b->set('blocks.0.uraian_kegiatan', 'Ubahan B')->call('simpanDraft')->assertHasErrors('bentrok');

        $this->assertSame('Ubahan A', $kegiatan->fresh()->uraian_kegiatan);
        $this->assertArrayHasKey('kegiatan', $b->get('bentrok'));
    }

    public function test_baris_yang_dihapus_pengguna_lain_terdeteksi_bentrok(): void
    {
        $kendala = KendalaSolusi::create(['iku_id' => $this->iku->id, 'periode_id' => $this->periode->id, 'kendala' => 'Kendala awal', 'solusi' => 'Solusi', 'status_dokumen' => KendalaSolusi::STATUS_DRAFT]);
        $a = $this->layar($this->ketuaA);

        $kendala->delete(); // dihapus pengguna lain

        $a->set('kendalaBlocks.0.kendala', 'Ubahan A')
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->call('simpanDraft')
            ->assertHasErrors('bentrok');

        $this->assertSame(0, KendalaSolusi::count(), 'baris yang dihapus tidak dihidupkan/digandakan');
        $this->assertSame(0, Kegiatan::count());
        $this->assertStringContainsString('sudah dihapus', collect($a->get('bentrok'))->flatten()->implode(' '));
    }

    public function test_penandaan_tim_sakip_saat_ketua_tim_menyunting_terdeteksi_bentrok(): void
    {
        $poin = $this->poin('Poin diperiksa');
        $a = $this->layar($this->ketuaA);

        $poin->update(['status_verifikasi' => 'ditolak', 'catatan' => 'Perbaiki']);

        $a->set("bagianKustomBlocks.{$this->bagian->id}.0.teks", 'Ubahan A')
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->call('simpanDraft')->assertHasErrors('bentrok');

        $this->assertSame('Poin diperiksa', $poin->fresh()->teks);
    }

    public function test_aksi_kecil_milik_sendiri_tidak_menimbulkan_bentrok_palsu(): void
    {
        $poin = $this->poin('Poin revisi');
        Capaian::create(['iku_id' => $this->iku->id, 'periode_id' => $this->periode->id, 'status' => Capaian::STATUS_DIKEMBALIKAN]);
        $berkas = Berkas::create(['ref_id' => $poin->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom', 'nama_file' => 'lama.pdf', 'path' => 'bukti-bagian-kustom/lama.pdf', 'status_verifikasi' => 'ditolak', 'catatan' => 'Buram']);
        $a = $this->layar($this->ketuaA);

        $a->call('hapusBuktiLamaBagianKustom', $poin->id, $berkas->id)
            ->set("bagianKustomBlocks.{$this->bagian->id}.0.teks", 'Poin diperbaiki')
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $this->assertSame('Poin diperbaiki', $poin->fresh()->teks);
        $this->assertSame('Buram', $poin->fresh()->catatan_bukti_dihapus);
    }

    public function test_simpan_ulang_setelah_transaksi_gagal_tidak_membuat_data_ganda(): void
    {
        $a = $this->layar($this->ketuaA);
        $gagal = true;
        Kegiatan::created(function () use (&$gagal) {
            if ($gagal) {
                throw new \RuntimeException('Galat tiruan setelah kegiatan dibuat');
            }
        });

        try {
            $a->set('blocks.0.uraian_kegiatan', 'Kegiatan baru')->set('blocks.0.jenis', 'bukan_survei_sensus')->call('simpanDraft');
            $this->fail('Galat tiruan seharusnya muncul.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, Kegiatan::count());
        $this->assertNull($a->get('blocks.0.id'), 'id baris yang batal dibuat tidak tertinggal di formulir');

        $gagal = false;
        $a->call('simpanDraft')->assertHasNoErrors();

        $this->assertSame(1, Kegiatan::count());
    }

    /* ---------- Pengaturan Bagian Kustom (dua Tim SAKIP) ---------- */

    public function test_dua_tim_sakip_menyimpan_pengaturan_yang_sama_yang_kedua_ditolak(): void
    {
        $sakipA = $this->pengguna('Tim SAKIP', 'SAKIP A');
        $sakipB = $this->pengguna('Tim SAKIP', 'SAKIP B');
        $id = $this->bagian->id;

        $this->actingAs($sakipB);
        $b = Livewire::test(BagianKustomManager::class)->call('buka', $id);
        $this->actingAs($sakipA);
        $a = Livewire::test(BagianKustomManager::class)->call('buka', $id);

        $a->set("form.{$id}.nama", 'Nama dari A')->call('simpan', $id)->assertHasNoErrors();

        $this->actingAs($sakipB);
        $b->set("form.{$id}.deskripsi", 'Deskripsi dari B')->call('simpan', $id)->assertHasErrors("form.{$id}.bentrok");

        $this->bagian->refresh();
        $this->assertSame('Nama dari A', $this->bagian->nama);
        $this->assertNull($this->bagian->deskripsi);
        $this->assertSame('Deskripsi dari B', $b->get("form.{$id}.deskripsi"), 'isian B tetap di layar');
        $this->assertSame(1, RiwayatKonfigurasiBagianKustom::where('aksi', 'ubah')->count());

        // Setelah memuat pengaturan terbaru, B bisa menerapkan ulang perubahannya.
        $b->call('batalUbah', $id)->set("form.{$id}.deskripsi", 'Deskripsi dari B')->call('simpan', $id)->assertHasNoErrors();
        $this->assertSame('Deskripsi dari B', $this->bagian->fresh()->deskripsi);
        $this->assertSame('Nama dari A', $this->bagian->fresh()->nama);
    }

    public function test_tombol_nonaktifkan_tidak_membalik_keputusan_tim_sakip_lain(): void
    {
        $sakipA = $this->pengguna('Tim SAKIP', 'SAKIP A');
        $sakipB = $this->pengguna('Tim SAKIP', 'SAKIP B');

        $this->actingAs($sakipB);
        $b = Livewire::test(BagianKustomManager::class);
        $this->actingAs($sakipA);
        Livewire::test(BagianKustomManager::class)->call('toggleAktif', $this->bagian->id, false);

        $this->actingAs($sakipB);
        $b->call('toggleAktif', $this->bagian->id, false); // B juga menekan "Nonaktifkan"

        $this->assertFalse($this->bagian->fresh()->aktif);
        $this->assertSame(1, RiwayatKonfigurasiBagianKustom::where('aksi', 'nonaktifkan')->count());
        $this->assertSame(0, RiwayatKonfigurasiBagianKustom::where('aksi', 'aktifkan')->count());
    }
}
