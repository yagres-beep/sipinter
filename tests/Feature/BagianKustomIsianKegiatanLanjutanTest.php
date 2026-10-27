<?php

namespace Tests\Feature;

use App\Livewire\PengisianKegiatan;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\PenjagaDatabaseUji;
use Tests\TestCase;

/**
 * Aturan bukti dukung dan relasi antarperiode Bagian Kustom di menu Isian Kegiatan
 * (Ketua Tim), termasuk regresi perilaku lama.
 */
class BagianKustomIsianKegiatanLanjutanTest extends TestCase
{
    use PenjagaDatabaseUji;
    use RefreshDatabase;

    protected MasterIku $iku;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pastikanDatabaseUji();

        $peran = Role::firstOrCreate(['nama' => 'Ketua Tim']);
        $this->actingAs(User::create([
            'nama' => 'Ketua Uji', 'username' => 'ketua-'.uniqid(), 'email' => 'ketua-'.uniqid().'@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $this->iku = MasterIku::create(['kode' => 'IKL-1', 'indikator' => 'Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
    }

    protected function form(int $bulan = 4)
    {
        return Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', $bulan)
            ->set('iku_id', $this->iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')]);
    }

    public function test_format_bukti_mengikuti_aturan_bagian(): void
    {
        $bagian = BagianKustom::create(['nama' => 'Dokumentasi', 'aktif' => true, 'aturan_bukti' => ['mode' => 'wajib', 'min' => 1, 'format' => ['png']]]);

        $this->form()
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Foto kegiatan')
            // Berkas yang tidak sesuai ditolak saat dipilih (unggah langsung) dan dibuang dari form.
            ->set("bagianKustomBlocks.{$bagian->id}.0.bukti", [UploadedFile::fake()->create('dok.pdf', 10, 'application/pdf')])
            ->assertHasErrors(["bagianKustomBlocks.{$bagian->id}.0.bukti"])
            ->assertSet("bagianKustomBlocks.{$bagian->id}.0.bukti", [])
            // Tanpa bukti sah, pengajuan ditolak (wajib).
            ->call('ajukanIsian')
            ->assertHasErrors(["bagianKustomBlocks.{$bagian->id}.0.bukti"]);

        $this->assertSame(0, Berkas::where('ref_type', BagianKustomPoin::class)->count());

        $this->form()
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Foto kegiatan')
            ->set("bagianKustomBlocks.{$bagian->id}.0.bukti", [UploadedFile::fake()->image('foto.png')])
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $berkas = Berkas::where('ref_type', BagianKustomPoin::class)->sole();
        $this->assertStringEndsWith('.png', $berkas->nama_file);
    }

    public function test_bagian_tanpa_bukti_menolak_unggahan(): void
    {
        $bagian = BagianKustom::create(['nama' => 'Catatan', 'aktif' => true, 'aturan_bukti' => ['mode' => 'tidak']]);

        $this->form()
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Catatan saja')
            ->set("bagianKustomBlocks.{$bagian->id}.0.bukti", [UploadedFile::fake()->create('dok.pdf', 10, 'application/pdf')])
            ->assertHasErrors(["bagianKustomBlocks.{$bagian->id}.0.bukti"])
            ->assertSet("bagianKustomBlocks.{$bagian->id}.0.bukti", []);

        $this->assertSame(0, Berkas::where('ref_type', BagianKustomPoin::class)->count());

        $this->form()
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Catatan saja')
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $this->assertSame(1, BagianKustomPoin::where('teks', 'Catatan saja')->count());
    }

    public function test_lanjutkan_poin_triwulan_sebelumnya(): void
    {
        $bagian = BagianKustom::create(['nama' => 'Manajemen Risiko', 'aktif' => true, 'bukti_wajib' => false]);
        $januari = Periode::create(['tahun' => 2026, 'bulan' => 1, 'triwulan' => 1, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
        $asal = BagianKustomPoin::create(['bagian_kustom_id' => $bagian->id, 'iku_id' => $this->iku->id, 'periode_id' => $januari->id, 'teks' => 'Risiko data mitra', 'menu' => 'pengisian', 'peran_pengisi' => 'ketua_tim']);

        $this->form()
            ->assertSee('Lanjutkan ke periode ini')
            ->call('lanjutkanPoinBagianKustom', $bagian->id, $asal->id)
            ->assertSet("bagianKustomBlocks.{$bagian->id}.0.teks", 'Risiko data mitra')
            ->assertSet("bagianKustomBlocks.{$bagian->id}.0.lanjutan_dari_id", $asal->id)
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $baru = BagianKustomPoin::where('id', '!=', $asal->id)->sole();
        $this->assertSame($asal->id, $baru->lanjutan_dari_id);
        $this->assertSame($asal->id, $baru->akar_id);
        $this->assertSame('Risiko data mitra', $asal->fresh()->teks);

        // Sudah dilanjutkan ke April: tombol tidak lagi tersedia, panggilan ulang tidak menambah.
        $this->form()->assertDontSee('Lanjutkan ke periode ini');
    }

    public function test_lanjutan_dari_iku_lain_ditolak(): void
    {
        $bagian = BagianKustom::create(['nama' => 'Manajemen Risiko', 'aktif' => true, 'bukti_wajib' => false]);
        $ikuLain = MasterIku::create(['kode' => 'IKL-2', 'indikator' => 'Lain', 'tim' => 'Uji', 'penanggung_jawab' => 'X']);
        $januari = Periode::create(['tahun' => 2026, 'bulan' => 1, 'triwulan' => 1, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
        $asing = BagianKustomPoin::create(['bagian_kustom_id' => $bagian->id, 'iku_id' => $ikuLain->id, 'periode_id' => $januari->id, 'teks' => 'Milik IKU lain', 'menu' => 'pengisian', 'peran_pengisi' => 'ketua_tim']);

        $this->form()->call('lanjutkanPoinBagianKustom', $bagian->id, $asing->id)
            ->assertHasErrors(["bagianKustomBlocks.{$bagian->id}"]);

        // Manipulasi langsung state lanjutan_dari_id juga ditolak saat simpan: seluruh
        // penyimpanan batal dan penolakan tercatat.
        $this->form()
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Coba')
            ->set("bagianKustomBlocks.{$bagian->id}.0.lanjutan_dari_id", $asing->id)
            ->call('ajukanIsian')
            ->assertHasErrors(['bentrok']);

        $this->assertSame(0, BagianKustomPoin::whereNotNull('lanjutan_dari_id')->count());
        $this->assertSame(0, BagianKustomPoin::where('teks', 'Coba')->count());
        $this->assertTrue(\App\Models\LogAktivitas::where('aksi', 'isian_kegiatan.simpan')->where('status', 'ditolak')->exists());
    }
}
