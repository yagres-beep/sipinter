<?php

namespace Tests\Feature;

use App\Livewire\BagianKustomManager;
use App\Livewire\IsianBagianKustom;
use App\Livewire\VariabelKatalogManager;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\LogAktivitas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Models\VariabelKatalog;
use App\Services\FolderStructureService;
use App\Services\GoogleDriveService;
use App\Support\AturanBuktiBagianKustom;
use App\Support\FormatAngkaKolom;
use App\Support\LanjutanBagianKustom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\PenjagaDatabaseUji;
use Tests\TestCase;

/**
 * Bagian Kustom tahap lanjut: aturan bukti dukung, relasi antarperiode, peran pembaca,
 * hak akses bukti, katalog variabel, bentuk tampilan, dan builder untuk semuanya.
 */
class BagianKustomLanjutanTest extends TestCase
{
    use PenjagaDatabaseUji;
    use RefreshDatabase;

    protected MasterIku $iku;

    /** @var array<int, Periode> per bulan */
    protected array $periode = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pastikanDatabaseUji();
        Storage::fake('local');

        foreach (['Ketua Tim', 'Tim SAKIP', 'Kepala'] as $nama) {
            Role::firstOrCreate(['nama' => $nama]);
        }

        $this->iku = MasterIku::create(['kode' => 'LJT-1', 'indikator' => 'IKU lanjutan', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        foreach ([1, 2, 4, 7] as $bulan) {
            $this->periode[$bulan] = Periode::create(['tahun' => 2026, 'bulan' => $bulan, 'triwulan' => intdiv($bulan - 1, 3) + 1, 'bulan_ke' => ($bulan - 1) % 3 + 1, 'flag_bulan_terlewat' => true]);
        }
    }

    protected function sebagai(string $peran): User
    {
        $user = User::create([
            'nama' => $peran.' Uji', 'username' => 'u-'.uniqid(), 'email' => 'u-'.uniqid().'@example.test', 'password' => 'password',
            'role_id' => Role::where('nama', $peran)->value('id'), 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($user);

        return $user;
    }

    protected function bagian(array $extra = []): BagianKustom
    {
        $b = BagianKustom::create($extra + [
            'nama' => 'Register Risiko', 'aktif' => true, 'bukti_wajib' => false,
            'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom'],
            'kolom' => [['kunci' => 'nilai', 'label' => 'Nilai', 'tipe' => 'angka', 'desimal' => 2, 'ringkasan' => 'jumlah']],
        ]);
        BagianKustom::lupakanCache();

        return $b;
    }

    protected function poin(BagianKustom $b, int $bulan, string $teks, array $extra = []): BagianKustomPoin
    {
        return BagianKustomPoin::create($extra + [
            'bagian_kustom_id' => $b->id, 'iku_id' => $this->iku->id, 'periode_id' => $this->periode[$bulan]->id,
            'teks' => $teks, 'menu' => 'isian_bagian_kustom', 'peran_pengisi' => 'tim_sakip', 'status_verifikasi' => 'terverifikasi',
        ]);
    }

    protected function isian(int $bulan)
    {
        return Livewire::test(IsianBagianKustom::class, ['menu' => 'isian_bagian_kustom'])->set('tahun', 2026)->set('bulan', $bulan);
    }

    protected function pdf(string $nama = 'bukti.pdf', int $kb = 1): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nama, "%PDF-1.4\n".str_repeat('x', $kb * 1024)."\n%%EOF");
    }

    protected function driveTiruan(): void
    {
        $this->mock(FolderStructureService::class, fn ($m) => $m->shouldReceive('unggahBerkasBagianKustom')
            ->andReturnUsing(fn ($poin, $path, $nama, $kode, $namaFile) => ['drive_file_id' => 'drive-'.$kode, 'storage_account_id' => null, 'nama_file' => $namaFile]));
    }

    /* ---------- Migrasi ---------- */

    public function test_migrasi_menambah_kolom_tanpa_mengubah_data_lama(): void
    {
        $this->assertTrue(Schema::hasColumns('bagian_kustom', ['aturan_bukti', 'peran_pembaca', 'tampilan']));
        $this->assertTrue(Schema::hasColumns('bagian_kustom_poin', ['lanjutan_dari_id', 'akar_id']));
        $this->assertTrue(Schema::hasTable('variabel_katalog'));

        // Bagian lama (kolom baru NULL) berperilaku sama: bukti PDF hanya di Isian Kegiatan.
        $lama = $this->bagian(['bukti_wajib' => true]);
        $this->assertNull($lama->aturan_bukti);
        $this->assertSame('wajib', $lama->aturanBuktiDi('pengisian')['mode']);
        $this->assertSame(['pdf'], $lama->aturanBuktiDi('pengisian')['format']);
        $this->assertSame('tidak', $lama->aturanBuktiDi('isian_bagian_kustom')['mode']);
        $this->assertSame('kartu', $lama->tampilanIsian());
        $this->assertSame('daftar', $lama->tampilanBaca());
    }

    /* ---------- Aturan bukti dukung ---------- */

    public function test_bagian_lama_tanpa_aturan_tidak_menampilkan_unggah_di_menu_lain(): void
    {
        $this->bagian();
        $this->sebagai('Tim SAKIP');

        $this->isian(4)->assertDontSee('Seret bukti');
    }

    public function test_bukti_wajib_divalidasi_dan_tersimpan_ke_drive(): void
    {
        $b = $this->bagian(['aturan_bukti' => ['mode' => 'wajib', 'min' => 1, 'maks' => 2, 'format' => ['pdf', 'png'], 'ukuran_maks_mb' => 1]]);
        $this->sebagai('Tim SAKIP');
        $this->driveTiruan();

        // Tanpa bukti: ditolak.
        $k = $this->isian(4)
            ->set("baris.{$b->id}.0.iku_id", (string) $this->iku->id)
            ->set("baris.{$b->id}.0.teks", 'Risiko A')
            ->call('simpan', $b->id)
            ->assertHasErrors(["bukti.{$b->id}.0"]);
        $this->assertSame(0, BagianKustomPoin::count());

        // Format di luar daftar (isi bukan PDF walau namanya .pdf): ditolak.
        // (Di uji Livewire jenis isi dibaca dari MIME berkas palsu; di server sungguhan dari isi berkas.)
        $k->set("bukti.{$b->id}.0", [])->set("bukti.{$b->id}.0", [UploadedFile::fake()->create('palsu.pdf', 1, 'application/x-msdownload')])
            ->call('simpan', $b->id)
            ->assertHasErrors(["bukti.{$b->id}.0"]);

        // Melebihi ukuran: ditolak.
        $k->set("bukti.{$b->id}.0", [])->set("bukti.{$b->id}.0", [$this->pdf('besar.pdf', 1100)])
            ->call('simpan', $b->id)
            ->assertHasErrors(["bukti.{$b->id}.0"]);

        // Melebihi jumlah maksimal: ditolak.
        $k->set("bukti.{$b->id}.0", [])->set("bukti.{$b->id}.0", [$this->pdf('a.pdf'), $this->pdf('b.pdf'), $this->pdf('c.pdf')])
            ->call('simpan', $b->id)
            ->assertHasErrors(["bukti.{$b->id}.0"]);
        $this->assertSame(0, BagianKustomPoin::count());

        // Sah: tersimpan, ekstensi asli, tersalin ke Drive, tercatat di log.
        $k->set("bukti.{$b->id}.0", [])->set("bukti.{$b->id}.0", [$this->pdf('notulen rapat.pdf')])
            ->call('simpan', $b->id)
            ->assertHasNoErrors();

        $poin = BagianKustomPoin::sole();
        $berkas = Berkas::where('ref_type', BagianKustomPoin::class)->where('ref_id', $poin->id)->sole();
        $this->assertSame('notulen rapat.pdf', $berkas->nama_file);
        $this->assertSame('drive-'.$berkas->kode, $berkas->fresh()->drive_file_id);
        $this->assertTrue(LogAktivitas::where('aksi', 'bukti.diunggah')->where('objek_id', $berkas->id)->exists());
    }

    public function test_hapus_bukti_memindah_ke_sampah_dan_menjaga_minimal_wajib(): void
    {
        $b = $this->bagian(['aturan_bukti' => ['mode' => 'wajib', 'min' => 1, 'format' => ['pdf']]]);
        $poin = $this->poin($b, 4, 'Risiko A');
        $satu = Berkas::create(['ref_id' => $poin->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom', 'nama_file' => 'a.pdf', 'drive_file_id' => 'd-a']);
        $dua = Berkas::create(['ref_id' => $poin->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom', 'nama_file' => 'b.pdf', 'drive_file_id' => 'd-b']);
        $this->sebagai('Tim SAKIP');

        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('trashFile')->once()->with('d-a', null);
            $m->shouldNotReceive('deleteFile');
        });

        $k = $this->isian(4)->call('hapusBukti', $b->id, $poin->id, $satu->id)->assertHasNoErrors();
        $this->assertNull(Berkas::find($satu->id));
        $this->assertTrue(LogAktivitas::where('aksi', 'bukti.dihapus')->exists());

        // Bukti terakhir pada bagian wajib tidak bisa dihapus.
        $k->call('hapusBukti', $b->id, $poin->id, $dua->id)->assertHasErrors(["baris.{$b->id}"]);
        $this->assertNotNull(Berkas::find($dua->id));
    }

    public function test_hapus_bukti_poin_di_luar_layar_ditolak(): void
    {
        $b = $this->bagian(['aturan_bukti' => ['mode' => 'opsional', 'format' => ['pdf']]]);
        $milikKepala = $this->poin($b, 4, 'Poin Kepala', ['menu' => 'isian_bagian_kustom', 'peran_pengisi' => 'kepala']);
        $berkas = Berkas::create(['ref_id' => $milikKepala->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom', 'nama_file' => 'k.pdf']);
        $this->sebagai('Tim SAKIP');

        $this->isian(4)->call('hapusBukti', $b->id, $milikKepala->id, $berkas->id)->assertForbidden();
        $this->assertNotNull(Berkas::find($berkas->id));
    }

    public function test_berkas_lama_tidak_disentuh_saat_aturan_berubah(): void
    {
        $b = $this->bagian(['aturan_bukti' => ['mode' => 'opsional', 'format' => ['pdf', 'docx']]]);
        $poin = $this->poin($b, 4, 'Risiko A');
        $docx = Berkas::create(['ref_id' => $poin->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom', 'nama_file' => 'lama.docx']);
        $this->sebagai('Tim SAKIP');

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$b->id}.aturan_bukti.format", ['pdf'])
            ->call('simpan', $b->id)
            ->assertHasNoErrors()
            ->assertSee('1 bukti lama berformat di luar aturan baru');

        $this->assertSame('lama.docx', $docx->fresh()->nama_file);
        $this->assertSame(['pdf'], $b->fresh()->aturanBukti()['format']);
    }

    /* ---------- Hak akses bukti (URL langsung) ---------- */

    public function test_akses_langsung_bukti_mengikuti_pengisi_dan_pembaca(): void
    {
        Storage::disk('local')->put('bukti-bagian-kustom/a.pdf', '%PDF-1.4');
        $b = $this->bagian(['peran_pembaca' => ['kepala'], 'penempatan' => ['isian_bagian_kustom']]);
        $poin = $this->poin($b, 4, 'Risiko A');
        $berkas = Berkas::create(['ref_id' => $poin->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom', 'nama_file' => 'a.pdf', 'path' => 'bukti-bagian-kustom/a.pdf']);

        $this->sebagai('Tim SAKIP');
        $this->get(route('berkas.show', $berkas))->assertOk();

        $this->sebagai('Kepala'); // pembaca
        $this->get(route('berkas.show', $berkas))->assertOk();

        $this->sebagai('Ketua Tim'); // bukan pengisi maupun pembaca
        $this->get(route('berkas.show', $berkas))->assertForbidden();

        $b->update(['peran_pembaca' => null]);
        $this->sebagai('Kepala');
        $this->get(route('berkas.show', $berkas))->assertForbidden();
    }

    public function test_kepala_tetap_tidak_bisa_membuka_berkas_selain_bagian_kustom(): void
    {
        Storage::disk('local')->put('x/b.pdf', '%PDF-1.4');
        $berkas = Berkas::create(['ref_id' => 1, 'ref_type' => \App\Models\Kegiatan::class, 'kategori' => 'capaian', 'nama_file' => 'b.pdf', 'path' => 'x/b.pdf']);

        $this->sebagai('Kepala');
        $this->get(route('berkas.show', $berkas))->assertForbidden();
    }

    /* ---------- Relasi antarperiode ---------- */

    public function test_lanjutkan_membuat_poin_baru_terkait_tanpa_mengubah_asal(): void
    {
        $b = $this->bagian();
        $asal = $this->poin($b, 1, 'Risiko banjir data', ['nilai_kolom' => ['nilai' => '62.5']]);
        Berkas::create(['ref_id' => $asal->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom', 'nama_file' => 'asal.pdf']);
        $sidikAsal = $asal->fresh()->sidikJari();
        $this->sebagai('Tim SAKIP');

        $this->isian(4)
            ->assertSee('isian periode sebelumnya bisa dilanjutkan')
            ->call('lanjutkan', $b->id, $asal->id)
            ->assertSet("baris.{$b->id}.0.lanjutan_dari_id", $asal->id)
            ->set("baris.{$b->id}.0.kolom.nilai", '70,25')
            ->call('simpan', $b->id)
            ->assertHasNoErrors();

        $baru = BagianKustomPoin::where('periode_id', $this->periode[4]->id)->sole();
        $this->assertSame($asal->id, $baru->lanjutan_dari_id);
        $this->assertSame($asal->id, $baru->akar_id);
        $this->assertSame($asal->id, $baru->identitasObjek());
        $this->assertSame('70.25', $baru->nilai_kolom['nilai']);
        $this->assertSame(0, $baru->berkas()->count(), 'bukti tidak disalin');
        $this->assertSame($sidikAsal, $asal->fresh()->sidikJari(), 'poin asal tidak berubah');
        $this->assertTrue(LogAktivitas::where('aksi', 'bagian_kustom_poin.dilanjutkan')->exists());

        // Rangkaian berlanjut: Juli melanjutkan versi April, akar tetap poin Januari.
        $this->isian(7)->call('lanjutkan', $b->id, $baru->id)->call('simpan', $b->id)->assertHasNoErrors();
        $juli = BagianKustomPoin::where('periode_id', $this->periode[7]->id)->sole();
        $this->assertSame($baru->id, $juli->lanjutan_dari_id);
        $this->assertSame($asal->id, $juli->akar_id);
    }

    public function test_lanjutan_dengan_bukti_wajib_dan_tampilan_tabel(): void
    {
        $b = $this->bagian(['aturan_bukti' => ['mode' => 'wajib', 'min' => 1, 'maks' => 2, 'format' => ['pdf', 'png']], 'tampilan' => ['isian' => 'tabel', 'baca' => 'tabel']]);
        $asal = $this->poin($b, 1, 'Risiko mitra', ['nilai_kolom' => ['nilai' => '62.5']]);
        $this->sebagai('Tim SAKIP');
        $this->driveTiruan();

        $this->isian(4)
            ->call('lanjutkan', $b->id, $asal->id)
            ->set("bukti.{$b->id}.0", [$this->pdf('lanjut.pdf')])
            ->call('simpan', $b->id)
            ->assertHasNoErrors();

        $baru = BagianKustomPoin::where('periode_id', $this->periode[4]->id)->sole();
        $this->assertSame($asal->id, $baru->lanjutan_dari_id);
        $this->assertSame(1, $baru->berkas()->count());
    }

    public function test_tidak_bisa_melanjutkan_dua_kali_ke_periode_yang_sama(): void
    {
        $b = $this->bagian();
        $asal = $this->poin($b, 1, 'Risiko A');
        $this->sebagai('Tim SAKIP');

        $layarA = $this->isian(4)->call('lanjutkan', $b->id, $asal->id);
        $layarB = $this->isian(4)->call('lanjutkan', $b->id, $asal->id);

        $layarA->call('simpan', $b->id)->assertHasNoErrors();
        $layarB->call('simpan', $b->id)->assertHasErrors();

        $this->assertSame(1, BagianKustomPoin::where('periode_id', $this->periode[4]->id)->count());

        // Setelah dilanjutkan, objek tidak lagi ditawarkan.
        $this->assertTrue(LanjutanBagianKustom::objekTerbuka($b, 'isian_bagian_kustom', 'tim_sakip', $this->periode[4])->isEmpty());
    }

    public function test_id_asal_dimanipulasi_ditolak(): void
    {
        $b = $this->bagian();
        $milikKepala = $this->poin($b, 1, 'Poin Kepala', ['peran_pengisi' => 'kepala']);
        $milikJuli = $this->poin($b, 7, 'Poin Juli');
        $this->sebagai('Tim SAKIP');

        foreach ([$milikKepala, $milikJuli] as $bukanHak) {
            $this->isian(4)
                ->set("baris.{$b->id}.0.iku_id", (string) $this->iku->id)
                ->set("baris.{$b->id}.0.teks", 'Coba')
                ->set("baris.{$b->id}.0.lanjutan_dari_id", $bukanHak->id)
                ->call('simpan', $b->id)
                ->assertHasErrors(["baris.{$b->id}.0.teks"]);
        }

        $this->assertSame(0, BagianKustomPoin::where('periode_id', $this->periode[4]->id)->count());
    }

    public function test_isian_baru_triwulan_tiga_tidak_muncul_di_periode_lama(): void
    {
        $b = $this->bagian();
        $this->sebagai('Tim SAKIP');

        $this->isian(7)
            ->set("baris.{$b->id}.0.iku_id", (string) $this->iku->id)
            ->set("baris.{$b->id}.0.teks", 'Baru di TW III')
            ->call('simpan', $b->id)
            ->assertHasNoErrors();

        $this->isian(4)->assertDontSee('Baru di TW III')->assertSet("baris.{$b->id}.0.teks", '');
        $this->isian(1)->assertDontSee('Baru di TW III');
        $this->assertTrue(LanjutanBagianKustom::objekTerbuka($b, 'isian_bagian_kustom', 'tim_sakip', $this->periode[4])->isEmpty());
    }

    public function test_poin_yang_sudah_dilanjutkan_tidak_dihapus(): void
    {
        $b = $this->bagian();
        $asal = $this->poin($b, 1, 'Risiko A');
        $this->poin($b, 4, 'Risiko A', ['lanjutan_dari_id' => $asal->id, 'akar_id' => $asal->id]);
        $this->sebagai('Tim SAKIP');

        $this->isian(1)->call('hapusBaris', $b->id, 0)->call('simpan', $b->id)->assertHasNoErrors()
            ->assertSee('tidak dihapus karena sudah punya bukti dukung atau sudah dilanjutkan');

        $this->assertNotNull($asal->fresh());
    }

    /* ---------- Pembaca ---------- */

    public function test_pembaca_melihat_tanpa_bisa_mengubah(): void
    {
        $b = $this->bagian(['peran_pembaca' => ['kepala']]);
        $this->poin($b, 4, 'Isian Tim SAKIP', ['nilai_kolom' => ['nilai' => '62.5']]);
        $this->sebagai('Kepala');

        $k = $this->isian(4)->assertSee('Isian Tim SAKIP')->assertSee('baca-saja')->assertSee('62,50');
        $this->assertArrayNotHasKey($b->id, $k->get('baris'));

        $k->call('simpan', $b->id)->assertForbidden();
        $this->assertSame('Isian Tim SAKIP', BagianKustomPoin::sole()->teks);
    }

    /* ---------- Tampilan & format angka ---------- */

    public function test_format_angka_tidak_mengubah_nilai_tersimpan(): void
    {
        $k = ['tipe' => 'angka'];

        $this->assertSame('62,5', FormatAngkaKolom::tampil('62.5', $k + ['desimal' => 1, 'pemisah' => 'koma']));
        $this->assertSame('62,50000', FormatAngkaKolom::tampil('62.5', $k + ['desimal' => 5, 'pemisah' => 'koma']));
        $this->assertSame('62.50000', FormatAngkaKolom::tampil('62.5', $k + ['desimal' => 5, 'pemisah' => 'titik']));
        $this->assertSame('1.234,57 %', str_replace('%', ' %', FormatAngkaKolom::tampil('1234.567', $k + ['desimal' => 2, 'satuan' => '%'])));
    }

    public function test_tampilan_tabel_dan_ringkasan_nilai(): void
    {
        $b = $this->bagian(['tampilan' => ['isian' => 'tabel', 'baca' => 'tabel'], 'peran_pembaca' => ['kepala']]);
        $this->poin($b, 4, 'A', ['nilai_kolom' => ['nilai' => '10.25']]);
        $this->poin($b, 4, 'B', ['nilai_kolom' => ['nilai' => '2']]);

        $this->assertSame('12,25', $b->ringkasanNilai(BagianKustomPoin::all())[0]['nilai']);
        $this->assertSame('10.25', BagianKustomPoin::where('teks', 'A')->sole()->nilai_kolom['nilai']);

        $this->sebagai('Tim SAKIP');
        $this->isian(4)->assertSeeHtml('tabel-isian-bagian-kustom')->assertSee('Jumlah Nilai');

        $this->sebagai('Kepala');
        $this->isian(4)->assertSeeHtml('tabel-bagian-kustom')->assertSee('12,25');
    }

    /* ---------- Katalog variabel ---------- */

    public function test_katalog_hanya_tim_sakip(): void
    {
        foreach (['Ketua Tim', 'Kepala'] as $peran) {
            $this->sebagai($peran);
            Livewire::test(VariabelKatalogManager::class)->assertForbidden();
        }
    }

    public function test_katalog_dipakai_kolom_dan_perubahan_definisi_aman(): void
    {
        $this->sebagai('Tim SAKIP');

        Livewire::test(VariabelKatalogManager::class)
            ->call('baru')
            ->set('form.kode', 'persen_tl')
            ->set('form.nama', 'Persentase tindak lanjut')
            ->set('form.tipe', 'angka')
            ->set('form.desimal', '2')
            ->set('form.satuan', '%')
            ->set('form.maks', '100')
            ->call('simpan')
            ->assertHasNoErrors();

        $v = VariabelKatalog::where('kode', 'persen_tl')->sole();
        $this->assertTrue(LogAktivitas::where('aksi', 'variabel_katalog.dibuat')->exists());

        // Kolom bertaut: mengikuti definisi katalog (jenis, format, batas), label lokal.
        $b = $this->bagian(['kolom' => [['kunci' => 'tl', 'label' => 'TL (%)', 'tipe' => 'teks', 'variabel' => 'persen_tl']]]);
        $kolom = $b->kolomList()[0];
        $this->assertSame('angka', $kolom['tipe']);
        $this->assertSame('TL (%)', $kolom['label']);
        $this->assertSame('100', $kolom['maks']);
        $this->assertNotNull(BagianKustom::pesanGalatKolom($kolom, '150'));

        $this->poin($b, 4, 'A', ['nilai_kolom' => ['tl' => '87.5']]);

        // Ganti nama: tautan tetap.
        $k = Livewire::test(VariabelKatalogManager::class)->call('ubah', $v->id)->set('form.nama', 'TL persen')->call('simpan')->assertHasNoErrors();
        $this->assertSame('angka', $b->fresh()->kolomList()[0]['tipe']);

        // Ganti jenis ke pilihan: nilai "87.5" tidak cocok → ditolak, tidak ada konversi.
        $k->call('ubah', $v->id)->set('form.tipe', 'pilihan')->set('form.opsi_teks', 'Rendah, Tinggi')->call('simpan')->assertHasErrors(['form.tipe']);
        $this->assertSame('angka', $v->fresh()->tipe);
        $this->assertSame('87.5', BagianKustomPoin::sole()->nilai_kolom['tl']);

        // Nonaktif: kolom lama tetap mengikuti katalog.
        $k->call('toggleAktif', $v->id);
        $this->assertFalse($v->fresh()->aktif);
        $this->assertSame('angka', $b->fresh()->kolomList()[0]['tipe']);
    }

    public function test_builder_menautkan_kolom_ke_variabel_katalog(): void
    {
        $this->sebagai('Tim SAKIP');
        VariabelKatalog::create(['kode' => 'level', 'nama' => 'Level', 'tipe' => 'pilihan', 'opsi' => ['Rendah', 'Tinggi']]);
        VariabelKatalog::create(['kode' => 'mati', 'nama' => 'Mati', 'tipe' => 'teks', 'aktif' => false]);
        $b = $this->bagian(['kolom' => []]);

        $k = Livewire::test(BagianKustomManager::class)
            ->call('tambahKolom', $b->id)
            ->set("form.{$b->id}.kolom.0.label", 'Level risiko')
            ->set("form.{$b->id}.kolom.0.variabel", 'mati')
            ->call('simpan', $b->id)
            ->assertHasErrors(["form.{$b->id}.kolom.0.variabel"]);

        $k->set("form.{$b->id}.kolom.0.variabel", 'level')->call('simpan', $b->id)->assertHasNoErrors();

        $kolom = $b->fresh()->kolomList()[0];
        $this->assertSame('level', $kolom['variabel']);
        $this->assertSame('pilihan', $kolom['tipe']);
        $this->assertSame(['Rendah', 'Tinggi'], $kolom['opsi']);
    }

    /* ---------- Builder: aturan bukti, pembaca, tampilan ---------- */

    public function test_builder_menyimpan_pembaca_aturan_bukti_dan_tampilan_dengan_riwayat(): void
    {
        $this->sebagai('Tim SAKIP');
        $b = $this->bagian();

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$b->id}.peran_pembaca", ['kepala'])
            ->set("form.{$b->id}.aturan_bukti.mode", 'wajib')
            ->set("form.{$b->id}.aturan_bukti.min", '2')
            ->set("form.{$b->id}.aturan_bukti.maks", '3')
            ->set("form.{$b->id}.aturan_bukti.format", ['pdf', 'xlsx'])
            ->set("form.{$b->id}.aturan_bukti.ukuran_maks_mb", '5')
            ->set("form.{$b->id}.tampilan.isian", 'tabel')
            ->set("form.{$b->id}.tampilan.baca", 'paragraf')
            ->call('simpan', $b->id)
            ->assertHasNoErrors();

        $b->refresh();
        $this->assertSame(['kepala'], $b->peran_pembaca);
        $this->assertSame(['mode' => 'wajib', 'min' => 2, 'maks' => 3, 'format' => ['pdf', 'xlsx'], 'ukuran_maks_mb' => 5, 'petunjuk' => null], $b->aturan_bukti);
        $this->assertTrue($b->bukti_wajib, 'kolom lama bukti_wajib ikut selaras');
        $this->assertSame('tabel', $b->tampilanIsian());
        $this->assertSame('paragraf', $b->tampilanBaca());
        $this->assertStringContainsString('aturan bukti dukung', (string) \App\Models\RiwayatKonfigurasiBagianKustom::where('bagian_kustom_id', $b->id)->latest('id')->value('ringkasan'));
    }

    public function test_simpan_tanpa_mengubah_bukti_mempertahankan_perilaku_lama(): void
    {
        $this->sebagai('Tim SAKIP');
        $b = $this->bagian(['bukti_wajib' => true, 'peran' => ['ketua_tim'], 'penempatan' => ['pengisian']]);

        Livewire::test(BagianKustomManager::class)->set("form.{$b->id}.nama", 'Register Risiko 2')->call('simpan', $b->id)->assertHasNoErrors();

        $this->assertNull($b->fresh()->aturan_bukti);
        $this->assertNull($b->fresh()->tampilan);
        $this->assertTrue($b->fresh()->bukti_wajib);
    }

    public function test_pembaca_yang_tidak_bisa_membuka_menu_ditolak_backend(): void
    {
        $this->sebagai('Tim SAKIP');
        $b = $this->bagian(['penempatan' => ['verifikasi']]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$b->id}.peran_pembaca", ['ketua_tim'])
            ->call('simpan', $b->id)
            ->assertHasErrors(["form.{$b->id}.peran_pembaca"]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$b->id}.aturan_bukti.format", ['exe'])
            ->call('simpan', $b->id)
            ->assertHasErrors(["form.{$b->id}.aturan_bukti.format.0"]);

        $this->assertNull($b->fresh()->peran_pembaca);
    }

    public function test_duplikasi_menyalin_pengaturan_baru_tanpa_data(): void
    {
        $this->sebagai('Tim SAKIP');
        $sumber = $this->bagian(['aturan_bukti' => ['mode' => 'opsional', 'format' => ['png']], 'tampilan' => ['isian' => 'tabel', 'baca' => 'tabel'], 'peran_pembaca' => ['kepala']]);
        $this->poin($sumber, 4, 'Data sumber');

        // Duplikasi langsung membuat bagian baru (tanpa harus menekan "Tambah Bagian").
        Livewire::test(BagianKustomManager::class)->call('duplikasi', $sumber->id)->assertHasNoErrors();

        $salinan = BagianKustom::where('id', '!=', $sumber->id)->sole();
        $this->assertNotSame($sumber->kode, $salinan->kode);
        $this->assertSame(['png'], $salinan->aturanBukti()['format']);
        $this->assertSame('tabel', $salinan->tampilanIsian());
        $this->assertSame(['kepala'], $salinan->peran_pembaca);
        $this->assertSame(0, $salinan->poin()->count());
    }

    public function test_aturan_bukti_normalisasi(): void
    {
        $a = AturanBuktiBagianKustom::normalkan(['mode' => 'wajib', 'min' => 0, 'maks' => 0, 'format' => ['exe']], false);
        $this->assertSame(1, $a['min']);
        $this->assertSame(1, $a['maks']);
        $this->assertSame(['pdf'], $a['format']);
        $this->assertNull(AturanBuktiBagianKustom::galatJumlah($a + ['maks' => 1], 3, 0), 'berkas lama melebihi batas baru tetap boleh disimpan');
        $this->assertNotNull(AturanBuktiBagianKustom::galatJumlah($a, 1, 1));
    }
}
