<?php

namespace Tests\Feature;

use App\Livewire\BagianKustomManager;
use App\Livewire\IsianBagianKustom;
use App\Livewire\PengisianKegiatan;
use App\Livewire\VerifikasiCapaian;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Services\NotulaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\PenjagaDatabaseUji;
use Tests\TestCase;

/**
 * Isolasi data & hak akses fitur Bagian Kustom (temuan audit F-01 s.d. F-04).
 * Tiap test membuktikan skenario eksploitasi yang sebelumnya berhasil kini ditolak.
 */
class BagianKustomIsolasiTest extends TestCase
{
    use PenjagaDatabaseUji;
    use RefreshDatabase;

    protected MasterIku $ikuA;

    protected MasterIku $ikuB;

    protected Periode $periode;

    protected function setUp(): void
    {
        parent::setUp();

        // Pengaman: test ini tidak boleh pernah berjalan di database selain SQLite uji.
        $this->pastikanDatabaseUji();

        foreach (['Ketua Tim', 'Tim SAKIP', 'Kepala'] as $nama) {
            Role::firstOrCreate(['nama' => $nama]);
        }

        $this->ikuA = MasterIku::create(['kode' => 'ISO-A', 'indikator' => 'IKU A', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $this->ikuB = MasterIku::create(['kode' => 'ISO-B', 'indikator' => 'IKU B', 'tim' => 'Uji', 'penanggung_jawab' => 'B']);
        $this->periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
    }

    protected function pengguna(string $peran): User
    {
        return User::create([
            'nama' => $peran.' Uji', 'username' => 'u-'.uniqid(), 'email' => 'u-'.uniqid().'@example.test', 'password' => 'password',
            'role_id' => Role::where('nama', $peran)->value('id'), 'status_verifikasi' => 'terverifikasi',
        ]);
    }

    protected function sebagai(string $peran): User
    {
        $user = $this->pengguna($peran);
        $this->actingAs($user);

        return $user;
    }

    /** Bagian yang dipasang untuk tiga peran sekaligus di tiga menu. */
    protected function bagianBersama(): BagianKustom
    {
        return BagianKustom::create([
            'nama' => 'Manajemen Risiko', 'aktif' => true, 'bukti_wajib' => false,
            'peran' => ['ketua_tim', 'tim_sakip', 'kepala'],
            'penempatan' => ['pengisian', 'isian_bagian_kustom', 'persetujuan'],
        ]);
    }

    protected function poin(BagianKustom $bagian, MasterIku $iku, string $teks, ?string $menu, ?string $peran, array $extra = []): BagianKustomPoin
    {
        return BagianKustomPoin::create([
            'bagian_kustom_id' => $bagian->id, 'iku_id' => $iku->id, 'periode_id' => $this->periode->id,
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

    /* ---------- 1 & 8: definisi hanya Tim SAKIP (F-04) ---------- */

    public function test_ketua_tim_dan_kepala_tidak_dapat_membuka_maupun_mengubah_definisi(): void
    {
        $bagian = $this->bagianBersama();

        foreach (['Ketua Tim', 'Kepala'] as $peran) {
            $this->sebagai($peran);
            Livewire::test(BagianKustomManager::class)->assertForbidden();
        }

        $this->assertSame('Manajemen Risiko', $bagian->fresh()->nama);
        $this->assertTrue($bagian->fresh()->aktif);
    }

    public function test_tim_sakip_yang_kehilangan_peran_ditolak_pada_aksi_berikutnya(): void
    {
        $bagian = $this->bagianBersama();
        $idSakip = Role::where('nama', 'Tim SAKIP')->value('id');
        $idKepala = Role::where('nama', 'Kepala')->value('id');
        $user = $this->sebagai('Tim SAKIP');

        // Tiap aksi diuji pada halaman yang dibuka saat masih Tim SAKIP, lalu perannya
        // dicabut sebelum aksi dikirim.
        $aksi = [
            fn ($k) => $k->call('simpan', $bagian->id),
            fn ($k) => $k->call('toggleAktif', $bagian->id),
            fn ($k) => $k->call('tambah'),
            fn ($k) => $k->call('turunkan', $bagian->id),
        ];

        foreach ($aksi as $kirim) {
            $user->update(['role_id' => $idSakip]);
            $komponen = Livewire::test(BagianKustomManager::class)->assertOk();
            $user->update(['role_id' => $idKepala]);

            $kirim($komponen)->assertForbidden();
        }

        $this->assertSame('Manajemen Risiko', $bagian->fresh()->nama);
        $this->assertTrue($bagian->fresh()->aktif);
        $this->assertDatabaseMissing('bagian_kustom', ['nama' => 'Bagian Baru']);
    }

    public function test_pengisi_yang_kehilangan_peran_ditolak_saat_menyimpan(): void
    {
        $bagian = $this->bagianBersama();
        $kepala = $this->sebagai('Kepala');

        $komponen = $this->isian('persetujuan')
            ->set("baris.{$bagian->id}.0.iku_id", (string) $this->ikuA->id)
            ->set("baris.{$bagian->id}.0.teks", 'Catatan');

        $kepala->update(['role_id' => Role::where('nama', 'Ketua Tim')->value('id')]);
        $komponen->call('simpan', $bagian->id)->assertForbidden();

        $ketua = $this->sebagai('Ketua Tim');
        $komponenKetua = $this->pengisian($this->ikuA)->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Poin ketua');
        $ketua->update(['role_id' => Role::where('nama', 'Kepala')->value('id')]);
        $komponenKetua->call('simpanDraft')->assertForbidden();

        $this->assertDatabaseCount('bagian_kustom_poin', 0);
    }

    /* ---------- 2: properti menu terkunci (F-01) ---------- */

    public function test_properti_menu_dan_daftar_dimuat_tidak_bisa_diubah_dari_browser(): void
    {
        $this->sebagai('Kepala');

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->isian('persetujuan')->set('menu', 'isian_bagian_kustom');
    }

    public function test_daftar_poin_dimuat_tidak_bisa_dimanipulasi(): void
    {
        $bagian = $this->bagianBersama();
        $this->sebagai('Tim SAKIP');

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->isian()->set("dimuat.{$bagian->id}", [999 => 'x']);
    }

    public function test_menu_tidak_sah_atau_menu_isian_kegiatan_ditolak_saat_dimuat(): void
    {
        $this->sebagai('Tim SAKIP');

        Livewire::test(IsianBagianKustom::class, ['menu' => 'pengisian'])->assertNotFound();
        Livewire::test(IsianBagianKustom::class, ['menu' => 'menu_karangan'])->assertNotFound();
    }

    public function test_menu_di_halaman_mengikuti_rute_server(): void
    {
        $bagian = BagianKustom::create(['nama' => 'Catatan Pimpinan', 'aktif' => true, 'peran' => ['kepala'], 'penempatan' => ['persetujuan']]);
        $this->sebagai('Kepala');

        // Bagian menu Persetujuan tampil di Persetujuan, tapi tidak di halaman menu lain.
        $this->get(route('persetujuan.index'))->assertOk()->assertSee($bagian->nama);
        $this->get(route('bagian-kustom.index'))->assertOk()->assertDontSee($bagian->nama);
    }

    /* ---------- 3 & 7: Ketua Tim & id poin/bukti milik IKU lain (F-03) ---------- */

    public function test_ketua_tim_tidak_dapat_mengubah_poin_iku_lain_lewat_id(): void
    {
        $bagian = $this->bagianBersama();
        $milikB = $this->poin($bagian, $this->ikuB, 'Poin asli IKU B', 'pengisian', 'ketua_tim');
        $this->sebagai('Ketua Tim');

        $this->pengisian($this->ikuA)
            ->set("bagianKustomBlocks.{$bagian->id}.0.id", $milikB->id)
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Diretas')
            ->call('simpanDraft')
            ->assertForbidden();

        $this->assertSame('Poin asli IKU B', $milikB->fresh()->teks);
        $this->assertSame($this->ikuB->id, $milikB->fresh()->iku_id);
        $this->assertDatabaseCount('kegiatan', 0);
    }

    public function test_ketua_tim_tidak_dapat_mengubah_poin_kepala_pada_iku_yang_sama(): void
    {
        $bagian = $this->bagianBersama();
        $milikKepala = $this->poin($bagian, $this->ikuA, 'Catatan Kepala', 'persetujuan', 'kepala');
        $this->sebagai('Ketua Tim');

        $komponen = $this->pengisian($this->ikuA)->assertDontSee('Catatan Kepala');

        $komponen->set("bagianKustomBlocks.{$bagian->id}.0.id", $milikKepala->id)
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Ditimpa ketua')
            ->call('simpanDraft')
            ->assertForbidden();

        $this->assertSame('Catatan Kepala', $milikKepala->fresh()->teks);
    }

    public function test_ketua_tim_tidak_dapat_mengubah_poin_saat_isian_terkunci(): void
    {
        $bagian = $this->bagianBersama();
        $poin = $this->poin($bagian, $this->ikuA, 'Sudah diverifikasi', 'pengisian', 'ketua_tim');
        Capaian::create(['iku_id' => $this->ikuA->id, 'periode_id' => $this->periode->id, 'status' => Capaian::STATUS_DIVERIFIKASI]);
        $this->sebagai('Ketua Tim');

        $this->pengisian($this->ikuA)
            ->set("bagianKustomBlocks.{$bagian->id}.0.id", $poin->id)
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Diubah diam-diam')
            ->call('simpanDraft')
            ->assertForbidden();

        $this->assertSame('Sudah diverifikasi', $poin->fresh()->teks);
    }

    public function test_bukti_poin_iku_lain_tidak_bisa_dihapus_lewat_id(): void
    {
        $bagian = $this->bagianBersama();
        $milikA = $this->poin($bagian, $this->ikuA, 'Poin A', 'pengisian', 'ketua_tim');
        $milikB = $this->poin($bagian, $this->ikuB, 'Poin B', 'pengisian', 'ketua_tim');
        $berkasB = Berkas::create([
            'ref_id' => $milikB->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom',
            'nama_file' => 'b.pdf', 'status_verifikasi' => 'ditolak', 'catatan' => 'salah',
        ]);
        $this->sebagai('Ketua Tim');

        $komponen = $this->pengisian($this->ikuA);
        $komponen->call('hapusBuktiLamaBagianKustom', $milikB->id, $berkasB->id);
        $komponen->call('hapusBuktiLamaBagianKustom', $milikA->id, $berkasB->id);

        $this->assertDatabaseHas('berkas', ['id' => $berkasB->id]);
        $this->assertNull($milikB->fresh()->catatan_bukti_dihapus);
    }

    /* ---------- 4 & 7: Tim SAKIP & Kepala di luar konteksnya (F-02) ---------- */

    public function test_tim_sakip_tidak_melihat_maupun_menimpa_poin_kepala_dan_ketua_tim(): void
    {
        $bagian = $this->bagianBersama();
        $kepala = $this->poin($bagian, $this->ikuA, 'Catatan Kepala', 'persetujuan', 'kepala');
        $ketua = $this->poin($bagian, $this->ikuA, 'Poin Ketua Tim', 'pengisian', 'ketua_tim');
        $this->sebagai('Tim SAKIP');

        $this->isian()->assertSet("baris.{$bagian->id}", [[
            'id' => null, 'iku_id' => null, 'teks' => '', 'kolom' => [],
        ]]);

        foreach ([$kepala, $ketua] as $sasaran) {
            $this->isian()->set("baris.{$bagian->id}.0.id", $sasaran->id)
                ->set("baris.{$bagian->id}.0.iku_id", (string) $this->ikuB->id)
                ->set("baris.{$bagian->id}.0.teks", 'Ditimpa SAKIP')
                ->call('simpan', $bagian->id)
                ->assertForbidden();
        }

        $this->assertSame('Catatan Kepala', $kepala->fresh()->teks);
        $this->assertSame('Poin Ketua Tim', $ketua->fresh()->teks);
        $this->assertSame($this->ikuA->id, $ketua->fresh()->iku_id);
    }

    public function test_id_poin_ganda_dalam_satu_kiriman_ditolak(): void
    {
        $bagian = $this->bagianBersama();
        $milik = $this->poin($bagian, $this->ikuA, 'Milik SAKIP', 'isian_bagian_kustom', 'tim_sakip', ['status_verifikasi' => 'terverifikasi']);
        $this->sebagai('Tim SAKIP');

        $this->isian()
            ->call('tambahBaris', $bagian->id)
            ->set("baris.{$bagian->id}.1.id", $milik->id)
            ->set("baris.{$bagian->id}.1.iku_id", (string) $this->ikuA->id)
            ->set("baris.{$bagian->id}.1.teks", 'Salinan')
            ->call('simpan', $bagian->id)
            ->assertForbidden();

        $this->assertSame('Milik SAKIP', $milik->fresh()->teks);
        $this->assertDatabaseCount('bagian_kustom_poin', 1);
    }

    /* ---------- 5: menyimpan satu layar tidak menghapus data konteks lain ---------- */

    public function test_menyimpan_layar_tim_sakip_tidak_menghapus_poin_peran_atau_menu_lain(): void
    {
        $bagian = $this->bagianBersama();
        $kepala = $this->poin($bagian, $this->ikuA, 'Catatan Kepala', 'persetujuan', 'kepala');
        $ketua = $this->poin($bagian, $this->ikuA, 'Poin Ketua Tim', 'pengisian', 'ketua_tim');
        $lama = $this->poin($bagian, $this->ikuB, 'Poin lama tanpa konteks', null, null);
        $user = $this->sebagai('Tim SAKIP');

        $this->isian()
            ->set("baris.{$bagian->id}.0.iku_id", (string) $this->ikuA->id)
            ->set("baris.{$bagian->id}.0.teks", 'Isian SAKIP')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        foreach ([$kepala, $ketua, $lama] as $poin) {
            $this->assertDatabaseHas('bagian_kustom_poin', ['id' => $poin->id, 'teks' => $poin->teks]);
        }

        $this->assertDatabaseHas('bagian_kustom_poin', [
            'teks' => 'Isian SAKIP', 'menu' => 'isian_bagian_kustom', 'peran_pengisi' => 'tim_sakip',
            'diisi_oleh' => $user->id, 'diubah_oleh' => $user->id,
        ]);
    }

    public function test_kepala_menghapus_barisnya_sendiri_tidak_menyentuh_konteks_lain(): void
    {
        $bagian = $this->bagianBersama();
        $milikKepala = $this->poin($bagian, $this->ikuA, 'Catatan Kepala', 'persetujuan', 'kepala', ['status_verifikasi' => 'terverifikasi']);
        $sakip = $this->poin($bagian, $this->ikuA, 'Isian SAKIP', 'isian_bagian_kustom', 'tim_sakip', ['status_verifikasi' => 'terverifikasi']);
        $ketua = $this->poin($bagian, $this->ikuA, 'Poin Ketua', 'pengisian', 'ketua_tim');
        $this->sebagai('Kepala');

        $this->isian('persetujuan')
            ->assertSet("baris.{$bagian->id}.0.id", $milikKepala->id)
            ->assertCount("baris.{$bagian->id}", 1)
            ->call('hapusBaris', $bagian->id, 0)
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('bagian_kustom_poin', ['id' => $milikKepala->id]);
        $this->assertDatabaseHas('bagian_kustom_poin', ['id' => $sakip->id]);
        $this->assertDatabaseHas('bagian_kustom_poin', ['id' => $ketua->id]);
    }

    /* ---------- 6: dua pengguna menyimpan bersamaan ---------- */

    public function test_dua_pengguna_menambah_poin_bersamaan_tidak_saling_menghapus(): void
    {
        $bagian = $this->bagianBersama();
        $sakip1 = $this->pengguna('Tim SAKIP');
        $sakip2 = $this->pengguna('Tim SAKIP');

        $this->actingAs($sakip1);
        $layar1 = $this->isian();
        $this->actingAs($sakip2);
        $layar2 = $this->isian();

        $this->actingAs($sakip1);
        $layar1->set("baris.{$bagian->id}.0.iku_id", (string) $this->ikuA->id)
            ->set("baris.{$bagian->id}.0.teks", 'Poin pengguna 1')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        // Layar 2 dimuat sebelum poin pengguna 1 ada — dulu poin itu ikut terhapus.
        $this->actingAs($sakip2);
        $layar2->set("baris.{$bagian->id}.0.iku_id", (string) $this->ikuB->id)
            ->set("baris.{$bagian->id}.0.teks", 'Poin pengguna 2')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('bagian_kustom_poin', ['teks' => 'Poin pengguna 1', 'diisi_oleh' => $sakip1->id]);
        $this->assertDatabaseHas('bagian_kustom_poin', ['teks' => 'Poin pengguna 2', 'diisi_oleh' => $sakip2->id]);
    }

    public function test_perubahan_pengguna_lain_tidak_tertimpa_atau_terhapus_layar_basi(): void
    {
        $bagian = $this->bagianBersama();
        $poin = $this->poin($bagian, $this->ikuA, 'Versi awal', 'isian_bagian_kustom', 'tim_sakip', ['status_verifikasi' => 'terverifikasi']);
        $sakip1 = $this->pengguna('Tim SAKIP');
        $sakip2 = $this->pengguna('Tim SAKIP');

        $this->actingAs($sakip1);
        $layar1 = $this->isian();
        $this->actingAs($sakip2);
        $layar2 = $this->isian();

        $this->actingAs($sakip1);
        $layar1->set("baris.{$bagian->id}.0.teks", 'Versi pengguna 1')->call('simpan', $bagian->id)->assertHasNoErrors();

        // Pengguna 2 (layar basi) mencoba menghapus poin yang sama — ditolak sebagai bentrok.
        $this->actingAs($sakip2);
        $layar2->call('hapusBaris', $bagian->id, 0)
            ->call('simpan', $bagian->id)
            ->assertHasErrors(["baris.{$bagian->id}"]);

        $this->assertSame('Versi pengguna 1', $poin->fresh()->teks);

        // Setelah dimuat ulang, layar 2 menampilkan versi terbaru dan bisa menyimpan lagi.
        $layar2->assertSet("baris.{$bagian->id}.0.teks", 'Versi pengguna 1')
            ->set("baris.{$bagian->id}.0.teks", 'Versi pengguna 2')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $this->assertSame('Versi pengguna 2', $poin->fresh()->teks);
        $this->assertSame($sakip2->id, $poin->fresh()->diubah_oleh);
    }

    public function test_poin_baru_tetap_ada_di_layar_saat_bentrok(): void
    {
        $bagian = $this->bagianBersama();
        $this->poin($bagian, $this->ikuA, 'Versi awal', 'isian_bagian_kustom', 'tim_sakip', ['status_verifikasi' => 'terverifikasi']);
        $sakip1 = $this->pengguna('Tim SAKIP');
        $sakip2 = $this->pengguna('Tim SAKIP');

        $this->actingAs($sakip1);
        $layar1 = $this->isian();
        $this->actingAs($sakip2);
        $layar2 = $this->isian();

        $this->actingAs($sakip1);
        $layar1->set("baris.{$bagian->id}.0.teks", 'Versi pengguna 1')->call('simpan', $bagian->id);

        $this->actingAs($sakip2);
        $layar2->call('tambahBaris', $bagian->id)
            ->set("baris.{$bagian->id}.1.iku_id", (string) $this->ikuB->id)
            ->set("baris.{$bagian->id}.1.teks", 'Poin baru pengguna 2')
            ->call('simpan', $bagian->id)
            ->assertHasErrors(["baris.{$bagian->id}"])
            ->assertSet("baris.{$bagian->id}.1.teks", 'Poin baru pengguna 2');

        $this->assertDatabaseMissing('bagian_kustom_poin', ['teks' => 'Poin baru pengguna 2']);
    }

    /* ---------- 9: data lama tetap terbaca ---------- */

    public function test_poin_lama_tanpa_konteks_tetap_terbaca_dan_bisa_diedit_ketua_tim(): void
    {
        $bagian = $this->bagianBersama();
        $lama = $this->poin($bagian, $this->ikuA, 'Poin lama', null, null);
        $ketua = $this->sebagai('Ketua Tim');

        $this->pengisian($this->ikuA)
            ->assertSet("bagianKustomBlocks.{$bagian->id}.0.id", $lama->id)
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Poin lama diperbarui')
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $lama->refresh();
        $this->assertSame('Poin lama diperbarui', $lama->teks);
        $this->assertSame('pengisian', $lama->menu);
        $this->assertSame('ketua_tim', $lama->peran_pengisi);
        $this->assertSame($ketua->id, $lama->diubah_oleh);
        $this->assertNull($lama->diisi_oleh);
        $this->assertDatabaseCount('bagian_kustom_poin', 1);
    }

    public function test_poin_baru_ketua_tim_mencatat_konteks_dan_pengisi(): void
    {
        $bagian = $this->bagianBersama();
        $ketua = $this->sebagai('Ketua Tim');

        $this->pengisian($this->ikuA)
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Poin baru ketua')
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('bagian_kustom_poin', [
            'teks' => 'Poin baru ketua', 'iku_id' => $this->ikuA->id, 'menu' => 'pengisian',
            'peran_pengisi' => 'ketua_tim', 'diisi_oleh' => $ketua->id,
        ]);
    }

    /* ---------- 10: verifikasi, notula, dan penempatan menu ---------- */

    public function test_verifikasi_hanya_memuat_poin_isian_kegiatan(): void
    {
        $bagian = $this->bagianBersama();
        $capaian = Capaian::create(['iku_id' => $this->ikuA->id, 'periode_id' => $this->periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);
        $ketua = $this->poin($bagian, $this->ikuA, 'Poin Ketua', 'pengisian', 'ketua_tim');
        $lama = $this->poin($bagian, $this->ikuA, 'Poin lama', null, null);
        $kepala = $this->poin($bagian, $this->ikuA, 'Catatan Kepala', 'persetujuan', 'kepala');
        $this->sebagai('Tim SAKIP');

        $komponen = Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian]);
        $ids = $komponen->instance()->bagianKustomList()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$ketua->id, $lama->id], $ids);

        // Poin Kepala tidak bisa ditandai lewat verifikasi capaian.
        $komponen->call('tandaiBagianKustomSesuai', $kepala->id);
        $this->assertSame('menunggu', $kepala->fresh()->status_verifikasi);

        $komponen->call('tandaiBagianKustomSesuai', $ketua->id);
        $this->assertSame('terverifikasi', $ketua->fresh()->status_verifikasi);
    }

    public function test_notula_tetap_memuat_poin_bagian_kustom(): void
    {
        $this->fakeKonversiBagian1KeXmlMentah();
        $bagian = $this->bagianBersama();
        $this->poin($bagian, $this->ikuA, 'Poin Ketua di notula', 'pengisian', 'ketua_tim');
        $this->poin($bagian, $this->ikuA, 'Poin lama di notula', null, null);

        $notula = app(NotulaService::class)->untukTriwulan(2026, 2);
        $html = app(NotulaService::class)->susunBagianSatu($notula);

        $this->assertStringContainsString('Poin Ketua di notula', $html);
        $this->assertStringContainsString('Poin lama di notula', $html);
    }

    public function test_melepas_menu_isian_kegiatan_ditolak_selama_ada_poin_menunggu_verifikasi(): void
    {
        $bagian = $this->bagianBersama();
        $this->poin($bagian, $this->ikuA, 'Menunggu', 'pengisian', 'ketua_tim');
        $capaian = Capaian::create(['iku_id' => $this->ikuA->id, 'periode_id' => $this->periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);
        $this->sebagai('Tim SAKIP');

        $komponen = Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.penempatan", ['isian_bagian_kustom', 'persetujuan'])
            ->call('simpan', $bagian->id)
            ->assertHasErrors(["form.{$bagian->id}.penempatan"]);

        $this->assertContains('pengisian', $bagian->fresh()->penempatan);

        // Setelah verifikasi selesai, penempatan boleh diubah (dengan peringatan data tetap tersimpan).
        $capaian->update(['status' => Capaian::STATUS_DIVERIFIKASI]);
        $komponen->call('simpan', $bagian->id)->assertHasNoErrors();

        $this->assertNotContains('pengisian', $bagian->fresh()->penempatan);
        $this->assertDatabaseCount('bagian_kustom_poin', 1);
    }
}
