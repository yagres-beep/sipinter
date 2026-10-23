<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserHasRole;
use App\Livewire\BagianKustomManager;
use App\Livewire\IsianBagianKustom;
use App\Livewire\LogAktivitasViewer;
use App\Livewire\PengisianKegiatan;
use App\Livewire\VerifikasiCapaian;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\LogAktivitas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\PenjagaDatabaseUji;
use Tests\TestCase;

/**
 * Otorisasi menyeluruh: pemeriksaan peran di SETIAP request Livewire (termasuk halaman
 * yang masih terbuka setelah peran dicabut), id baris dari browser yang tidak dimuat
 * server, dan kebebasan memilih IKU yang tetap dipertahankan.
 */
class OtorisasiFinalisasiTest extends TestCase
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

        $this->ikuA = MasterIku::create(['kode' => 'OTZ-A', 'indikator' => 'Indikator A', 'tim' => 'Tim Satu', 'penanggung_jawab' => 'A']);
        $this->ikuB = MasterIku::create(['kode' => 'OTZ-B', 'indikator' => 'Indikator B', 'tim' => 'Tim Lain', 'penanggung_jawab' => 'B']);
        $this->periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
    }

    protected function sebagai(string $peran): User
    {
        $user = User::create([
            'nama' => 'Pengguna '.$peran, 'username' => 'u-'.uniqid(), 'email' => 'u-'.uniqid().'@example.test', 'password' => 'password',
            'role_id' => Role::where('nama', $peran)->value('id'), 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($user);

        return $user;
    }

    protected function cabutPeran(User $user, string $peranBaru = 'Ketua Tim'): void
    {
        $user->update(['role_id' => Role::where('nama', $peranBaru)->value('id')]);
    }

    protected function pengisian(MasterIku $iku)
    {
        return Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 4)
            ->set('iku_id', $iku->id);
    }

    /* ---------- Pemeriksaan peran di setiap request ---------- */

    public function test_middleware_peran_dijalankan_ulang_di_setiap_request_livewire(): void
    {
        $this->assertContains(EnsureUserHasRole::class, Livewire::getPersistentMiddleware());
    }

    public function test_aksi_di_halaman_terbuka_ditolak_setelah_peran_dicabut_lewat_http_sungguhan(): void
    {
        $this->withoutVite();
        $user = $this->sebagai('Ketua Tim');

        $html = $this->get(route('pengisian.index'))->assertOk()->getContent();
        $snapshot = $this->snapshotKomponen($html, 'pengisian-kegiatan');
        $muatan = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]];

        // Sebelum dicabut: aksi berjalan normal.
        $this->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), $muatan)->assertOk();
        // Akhir siklus request (di server sungguhan terjadi otomatis tiap request).
        app('livewire')->flushState();

        $this->cabutPeran($user, 'Kepala');

        // Setelah dicabut, halaman yang masih terbuka tidak bisa menjalankan aksi lagi.
        $this->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), $muatan)->assertForbidden();

        $log = LogAktivitas::where('aksi', 'akses.ditolak')->sole();
        $this->assertSame(LogAktivitas::STATUS_DITOLAK, $log->status);
        $this->assertSame('Kepala', $log->peran);
        $this->assertSame($user->id, $log->user_id);
    }

    protected function snapshotKomponen(string $html, string $nama): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $cocok);

        foreach ($cocok[1] as $mentah) {
            $json = html_entity_decode($mentah, ENT_QUOTES);

            if ((json_decode($json, true)['memo']['name'] ?? null) === $nama) {
                return $json;
            }
        }

        $this->fail("Snapshot komponen {$nama} tidak ditemukan di halaman.");
    }

    public function test_verifikasi_pengelola_bagian_dan_log_ditolak_untuk_peran_lain(): void
    {
        $capaian = Capaian::create(['iku_id' => $this->ikuA->id, 'periode_id' => $this->periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);

        foreach (['Ketua Tim', 'Kepala'] as $peran) {
            $this->sebagai($peran);

            Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian])->assertForbidden();
            Livewire::test(BagianKustomManager::class)->assertForbidden();
            Livewire::test(LogAktivitasViewer::class)->assertForbidden();
        }

        $this->assertSame(6, LogAktivitas::where('aksi', 'akses.ditolak')->count());
        $this->assertSame(0, LogAktivitas::where('status', '!=', LogAktivitas::STATUS_DITOLAK)->count());
    }

    public function test_tim_sakip_berhasil_lalu_ditolak_begitu_peran_dicabut_di_semua_halaman_tim_sakip(): void
    {
        $bagian = BagianKustom::create(['nama' => 'Manajemen Risiko', 'aktif' => true, 'peran' => ['ketua_tim'], 'penempatan' => ['pengisian'], 'kolom' => []]);
        $capaian = Capaian::create(['iku_id' => $this->ikuA->id, 'periode_id' => $this->periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);
        $user = $this->sebagai('Tim SAKIP');

        $manager = Livewire::test(BagianKustomManager::class)->call('buka', $bagian->id)->assertOk();
        $verifikasi = Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian])->assertOk();
        $log = Livewire::test(LogAktivitasViewer::class)->assertOk();

        $this->cabutPeran($user);

        $manager->call('toggleAktif', $bagian->id, false)->assertForbidden();
        $verifikasi->call('$refresh')->assertForbidden();
        $log->call('resetFilter')->assertForbidden();

        $this->assertTrue($bagian->fresh()->aktif);
    }

    /* ---------- Id dari browser yang tidak dimuat server ---------- */

    public function test_kegiatan_iku_lain_tidak_bisa_ditimpa_lewat_id_manipulasi(): void
    {
        $milikB = Kegiatan::create(['iku_id' => $this->ikuB->id, 'periode_id' => $this->periode->id, 'uraian_kegiatan' => 'Kegiatan IKU B', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => Kegiatan::STATUS_DRAFT]);
        $this->sebagai('Ketua Tim');

        $this->pengisian($this->ikuA)
            ->set('blocks.0.id', $milikB->id)
            ->set('blocks.0.status_dokumen', Kegiatan::STATUS_DRAFT)
            ->set('blocks.0.uraian_kegiatan', 'Diretas')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->call('simpanDraft')
            ->assertForbidden();

        $this->assertSame('Kegiatan IKU B', $milikB->fresh()->uraian_kegiatan);
        $this->assertSame(1, Kegiatan::count());
        $this->assertSame('isian_kegiatan.simpan', LogAktivitas::sole()->aksi);
    }

    public function test_kendala_iku_lain_tidak_bisa_ditimpa_lewat_id_manipulasi(): void
    {
        $milikB = KendalaSolusi::create(['iku_id' => $this->ikuB->id, 'periode_id' => $this->periode->id, 'kendala' => 'Kendala B', 'solusi' => 'Solusi B', 'status_dokumen' => KendalaSolusi::STATUS_DRAFT]);
        $this->sebagai('Ketua Tim');

        $this->pengisian($this->ikuA)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan A')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('kendalaBlocks.0.id', $milikB->id)
            ->set('kendalaBlocks.0.kendala', 'Diretas')
            ->call('simpanDraft')
            ->assertForbidden();

        $this->assertSame('Kendala B', $milikB->fresh()->kendala);
        $this->assertSame(0, Kegiatan::count());
    }

    public function test_hapus_blok_dan_hapus_bukti_tidak_bisa_menyasar_kegiatan_iku_lain(): void
    {
        Storage::fake('local');
        $milikB = Kegiatan::create(['iku_id' => $this->ikuB->id, 'periode_id' => $this->periode->id, 'uraian_kegiatan' => 'Kegiatan IKU B', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => Kegiatan::STATUS_DIKEMBALIKAN]);
        Storage::disk('local')->put('bukti-capaian/b.pdf', 'isi');
        $berkasB = Berkas::create(['ref_id' => $milikB->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian', 'nama_file' => 'b.pdf', 'path' => 'bukti-capaian/b.pdf', 'status_verifikasi' => 'ditolak']);
        $this->sebagai('Ketua Tim');

        $this->pengisian($this->ikuA)
            ->set('blocks.0.id', $milikB->id)
            ->set('blocks.0.status_dokumen', Kegiatan::STATUS_DRAFT)
            ->call('hapusBuktiLama', 0, $berkasB->id)
            ->call('removeBlock', 0);

        $this->assertNotNull($milikB->fresh());
        $this->assertNotNull($berkasB->fresh());
        Storage::disk('local')->assertExists('bukti-capaian/b.pdf');
    }

    public function test_hapus_blok_milik_sendiri_tetap_berjalan(): void
    {
        Storage::fake('local');
        $milikA = Kegiatan::create(['iku_id' => $this->ikuA->id, 'periode_id' => $this->periode->id, 'uraian_kegiatan' => 'Kegiatan A', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => Kegiatan::STATUS_DRAFT]);
        Storage::disk('local')->put('bukti-capaian/a.pdf', 'isi');
        Berkas::create(['ref_id' => $milikA->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian', 'nama_file' => 'a.pdf', 'path' => 'bukti-capaian/a.pdf', 'status_verifikasi' => 'menunggu']);
        $this->sebagai('Ketua Tim');

        $this->pengisian($this->ikuA)->call('removeBlock', 0);

        $this->assertNull($milikA->fresh());
        $this->assertSame(0, Berkas::count());
        Storage::disk('local')->assertMissing('bukti-capaian/a.pdf');
    }

    public function test_permintaan_ditolak_tidak_membuat_baris_periode(): void
    {
        $milikB = Kegiatan::create(['iku_id' => $this->ikuB->id, 'periode_id' => $this->periode->id, 'uraian_kegiatan' => 'Kegiatan IKU B', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => Kegiatan::STATUS_DRAFT]);
        $this->sebagai('Ketua Tim');

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $this->ikuA->id)
            ->set('blocks.0.id', $milikB->id)
            ->set('blocks.0.uraian_kegiatan', 'Diretas')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->call('simpanDraft')
            ->assertForbidden();

        $this->assertFalse(Periode::where('tahun', 2026)->where('bulan', 9)->exists());
    }

    public function test_penyimpanan_normal_ketua_tim_tetap_berjalan(): void
    {
        $this->sebagai('Ketua Tim');

        $this->pengisian($this->ikuA)
            ->set('blocks.0.uraian_kegiatan', 'Rapat koordinasi')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('kendalaBlocks.0.kendala', 'Data terlambat')
            ->call('simpanDraft')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertSame('Rapat koordinasi', Kegiatan::sole()->uraian_kegiatan);
        $this->assertSame('Data terlambat', KendalaSolusi::sole()->kendala);
    }

    /* ---------- U-6: menonaktifkan bagian tidak menyembunyikan poin dari verifikasi ---------- */

    public function test_poin_bagian_yang_dinonaktifkan_tetap_bisa_diverifikasi(): void
    {
        $bagian = BagianKustom::create(['nama' => 'Manajemen Risiko', 'aktif' => true, 'peran' => ['ketua_tim'], 'penempatan' => ['pengisian'], 'kolom' => []]);
        $poin = BagianKustomPoin::create(['bagian_kustom_id' => $bagian->id, 'iku_id' => $this->ikuA->id, 'periode_id' => $this->periode->id, 'teks' => 'Menunggu', 'menu' => 'pengisian', 'peran_pengisi' => 'ketua_tim']);
        $capaian = Capaian::create(['iku_id' => $this->ikuA->id, 'periode_id' => $this->periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);
        $this->sebagai('Tim SAKIP');

        Livewire::test(BagianKustomManager::class)->call('toggleAktif', $bagian->id, false);
        $this->assertFalse($bagian->fresh()->aktif);

        $layar = Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian]);
        $this->assertTrue($layar->instance()->bagianKustomList()->contains('id', $poin->id));

        $layar->call('tandaiBagianKustomSesuai', $poin->id);
        $this->assertSame('terverifikasi', $poin->fresh()->status_verifikasi);
    }

    /* ---------- Kebebasan memilih IKU tetap ---------- */

    public function test_pemilihan_iku_tetap_bebas_untuk_ketua_tim_dan_tim_sakip(): void
    {
        $bagian = BagianKustom::create(['nama' => 'Catatan', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom'], 'kolom' => []]);
        $this->sebagai('Ketua Tim');

        // Ketua Tim bisa mengisi IKU mana pun, termasuk IKU tim lain.
        $this->pengisian($this->ikuB)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan IKU tim lain')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->call('simpanDraft')
            ->assertHasNoErrors();
        $this->assertSame($this->ikuB->id, Kegiatan::sole()->iku_id);

        $this->sebagai('Tim SAKIP');

        Livewire::test(IsianBagianKustom::class, ['menu' => 'isian_bagian_kustom'])
            ->set('tahun', 2026)->set('bulan', 4)
            ->set("baris.{$bagian->id}.0.iku_id", (string) $this->ikuB->id)
            ->set("baris.{$bagian->id}.0.teks", 'Catatan IKU B')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $this->assertSame($this->ikuB->id, BagianKustomPoin::sole()->iku_id);
    }
}
