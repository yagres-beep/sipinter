<?php

namespace Tests\Feature;

use App\Livewire\PengisianKegiatan;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\PenjagaDatabaseUji;
use Tests\TestCase;

/**
 * Menu (bilah navigasi bagian) Bagian Kustom di Isian Kegiatan tidak bergantung pada
 * IKU maupun data isian: bagian aktif langsung terdaftar sejak halaman dibuka, hanya
 * formulirnya yang menunggu IKU dipilih.
 */
class BagianKustomMenuTanpaIkuTest extends TestCase
{
    use PenjagaDatabaseUji;
    use RefreshDatabase;

    protected MasterIku $iku;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pastikanDatabaseUji();

        $this->actingAs($this->pengguna('Ketua Tim'));
        $this->iku = MasterIku::create(['kode' => 'IKM-1', 'indikator' => 'Uji menu', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
    }

    protected function pengguna(string $peran): User
    {
        return User::create([
            'nama' => $peran.' Uji', 'username' => 'u-'.uniqid(), 'email' => 'u-'.uniqid().'@example.test', 'password' => 'password',
            'role_id' => Role::firstOrCreate(['nama' => $peran])->id, 'status_verifikasi' => 'terverifikasi',
        ]);
    }

    public function test_bagian_aktif_muncul_di_menu_sebelum_iku_dipilih(): void
    {
        $bagian = BagianKustom::create(['nama' => 'Manajemen Risiko Uji', 'aktif' => true]);

        Livewire::test(PengisianKegiatan::class)
            ->assertSet('iku_id', null)
            // Tombol navigasi tanpa x-show yang bergantung IKU, plus tanda kunci.
            ->assertSeeHtml('data-bagian="bagian-kustom-'.$bagian->id.'"')
            ->assertDontSeeHtml('x-show="$wire.iku_id"')
            // Judul bagian & pesan kunci berdiri sendiri (tidak di dalam pembungkus RTL).
            ->assertSeeHtml('id="bagian-kustom-'.$bagian->id.'"')
            ->assertSeeHtml('data-kunci-iku="bagian-kustom-'.$bagian->id.'"')
            ->assertSee('untuk mengisi Manajemen Risiko Uji');

        // Membuka menu tidak membuat data apa pun.
        $this->assertSame(0, BagianKustomPoin::count());
        $this->assertSame(0, Periode::count());
    }

    public function test_judul_bagian_kustom_tidak_di_dalam_pembungkus_rtl(): void
    {
        $bagian = BagianKustom::create(['nama' => 'Bagian Mandiri', 'aktif' => true]);

        $html = Livewire::test(PengisianKegiatan::class)->html();

        $posRtl = strpos($html, 'id="bagian-rtl"');
        $posBagian = strpos($html, 'id="bagian-kustom-'.$bagian->id.'"');
        $this->assertNotFalse($posRtl);
        $this->assertNotFalse($posBagian);

        // Hitung keseimbangan <div> dari judul Bagian 5 sampai judul bagian kustom: bila
        // bagian kustom masih di dalam pembungkus RTL, ada <div> yang belum ditutup.
        $potongan = substr($html, $posRtl, $posBagian - $posRtl);
        $buka = preg_match_all('/<div\b/i', $potongan);
        $tutup = preg_match_all('/<\/div>/i', $potongan);
        $this->assertSame($buka, $tutup, 'Bagian kustom masih tersarang di dalam pembungkus Bagian 5 (RTL).');
    }

    public function test_menu_tetap_ada_saat_periode_dan_iku_berubah(): void
    {
        $bagian = BagianKustom::create(['nama' => 'Tetap Ada', 'aktif' => true]);
        $tombol = 'data-bagian="bagian-kustom-'.$bagian->id.'"';

        Livewire::test(PengisianKegiatan::class)
            ->set('bulan', 3)->assertSeeHtml($tombol)
            ->set('bulan', 6)->assertSeeHtml($tombol)
            ->set('tahun', (int) now()->year - 1)->assertSeeHtml($tombol)
            ->set('iku_id', $this->iku->id)->assertSeeHtml($tombol)
            ->set('iku_id', null)->assertSeeHtml($tombol);

        // "Refresh" = komponen baru dari awal.
        Livewire::test(PengisianKegiatan::class)->assertSeeHtml($tombol);
    }

    public function test_bagian_nonaktif_dan_bagian_peran_lain_tidak_muncul(): void
    {
        $nonaktif = BagianKustom::create(['nama' => 'Nonaktif', 'aktif' => false]);
        $sakip = BagianKustom::create(['nama' => 'Khusus SAKIP', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom']]);

        Livewire::test(PengisianKegiatan::class)
            ->assertDontSeeHtml('data-bagian="bagian-kustom-'.$nonaktif->id.'"')
            ->assertDontSeeHtml('data-bagian="bagian-kustom-'.$sakip->id.'"');
    }

    public function test_bagian_baru_langsung_berlaku_tanpa_deploy(): void
    {
        Livewire::test(PengisianKegiatan::class)->assertDontSeeHtml('data-bagian="bagian-kustom-');

        // Pembuatan lewat Data Master melupakan cache daftar aktif (lihat BagianKustomManager).
        $bagian = BagianKustom::create(['nama' => 'Baru Dibuat', 'aktif' => true]);
        BagianKustom::lupakanCache();

        Livewire::test(PengisianKegiatan::class)->assertSeeHtml('data-bagian="bagian-kustom-'.$bagian->id.'"');
    }

    public function test_simpan_tanpa_iku_ditolak_dan_tidak_membuat_poin(): void
    {
        $bagian = BagianKustom::create(['nama' => 'Tanpa IKU', 'aktif' => true]);

        Livewire::test(PengisianKegiatan::class)
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Poin tanpa IKU')
            ->call('simpanDraft')
            ->assertHasErrors(['iku_id']);

        $this->assertSame(0, BagianKustomPoin::count());
    }

    public function test_formulir_tampil_setelah_iku_dipilih(): void
    {
        $bagian = BagianKustom::create(['nama' => 'Dengan IKU', 'aktif' => true]);

        Livewire::test(PengisianKegiatan::class)
            ->set('iku_id', $this->iku->id)
            ->assertSeeHtml('wire:model.live.blur="bagianKustomBlocks.'.$bagian->id.'.0.teks"')
            ->assertSee('Tambah Poin Dengan IKU');
    }

    public function test_peran_lain_tidak_bisa_membuka_isian_kegiatan_langsung(): void
    {
        BagianKustom::create(['nama' => 'Rahasia', 'aktif' => true]);

        $this->actingAs($this->pengguna('Kepala'))->get(route('pengisian.index'))->assertForbidden();
        $this->actingAs($this->pengguna('Tim SAKIP'))->get(route('pengisian.index'))->assertForbidden();
    }
}
