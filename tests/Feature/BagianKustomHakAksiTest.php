<?php

namespace Tests\Feature;

use App\Livewire\BagianKustomManager;
use App\Livewire\DaftarBagianSipinter;
use App\Livewire\IsianBagianKustom;
use App\Livewire\PengisianKegiatan;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\MasterIku;
use App\Models\PengaturanBagianBawaan;
use App\Models\Periode;
use App\Models\RiwayatKonfigurasiBagianKustom;
use App\Models\Role;
use App\Models\User;
use App\Support\RegistriBagian;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\PenjagaDatabaseUji;
use Tests\TestCase;

/**
 * Tahap C & D: matriks hak aksi per peran untuk Bagian Kustom (diperiksa di server
 * pada setiap aksi) dan pusat pengelolaan seluruh bagian (label bagian bawaan).
 */
class BagianKustomHakAksiTest extends TestCase
{
    use PenjagaDatabaseUji;
    use RefreshDatabase;

    protected MasterIku $iku;

    protected Periode $periode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pastikanDatabaseUji();

        foreach (['Ketua Tim', 'Tim SAKIP', 'Kepala'] as $nama) {
            Role::firstOrCreate(['nama' => $nama]);
        }

        $this->iku = MasterIku::create(['kode' => 'HAK-1', 'indikator' => 'IKU hak', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $this->periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
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

    /** Bagian Tim SAKIP di menu Isian Bagian Kustom dengan hak aksi tertentu (null = penuh). */
    protected function bagianSakip(?array $aksi, array $extra = []): BagianKustom
    {
        BagianKustom::lupakanCache();

        return BagianKustom::create([
            'nama' => 'Risiko '.uniqid(), 'aktif' => true, 'bukti_wajib' => false,
            'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom'],
            'hak_aksi' => $aksi === null ? null : ['tim_sakip' => $aksi],
        ] + $extra);
    }

    protected function poinSakip(BagianKustom $bagian, string $teks = 'Poin lama'): BagianKustomPoin
    {
        return BagianKustomPoin::create([
            'bagian_kustom_id' => $bagian->id, 'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id,
            'teks' => $teks, 'menu' => 'isian_bagian_kustom', 'peran_pengisi' => 'tim_sakip', 'status_verifikasi' => 'terverifikasi',
        ]);
    }

    protected function isian()
    {
        return Livewire::test(IsianBagianKustom::class, ['menu' => 'isian_bagian_kustom'])->set('tahun', 2026)->set('bulan', 4);
    }

    /* ---------- Model ---------- */

    public function test_bagian_lama_tanpa_matriks_tetap_berhak_penuh_dan_pembaca_tanpa_aksi(): void
    {
        $bagian = BagianKustom::create(['nama' => 'Lama', 'aktif' => true, 'peran' => ['ketua_tim'], 'peran_pembaca' => ['kepala']]);

        $this->assertSame(array_keys(BagianKustom::AKSI), $bagian->aksiPeran('ketua_tim'));
        $this->assertSame([], $bagian->aksiPeran('kepala'), 'Read tidak memberi aksi ubah apa pun.');
        $this->assertSame([], $bagian->aksiPeran('tim_sakip'));
        $this->assertSame([], $bagian->aksiPeran(null));
    }

    public function test_matriks_membatasi_aksi_satu_per_satu(): void
    {
        $bagian = $this->bagianSakip(['ubah']);

        $this->assertTrue($bagian->bolehAksi('tim_sakip', 'ubah'));
        $this->assertFalse($bagian->bolehAksi('tim_sakip', 'hapus'), 'Update tidak memberi Delete.');
        $this->assertFalse($bagian->bolehAksi('tim_sakip', 'buat'));
        $this->assertFalse($bagian->bolehAksi('tim_sakip', 'hapus_bukti'));
    }

    /* ---------- Isian Bagian Kustom (Tim SAKIP / Kepala) ---------- */

    public function test_tanpa_hak_buat_poin_baru_ditolak_server(): void
    {
        $this->sebagai('Tim SAKIP');
        $bagian = $this->bagianSakip(['ubah', 'hapus']);

        $this->isian()
            ->assertDontSee('Tambah Poin')
            ->set("baris.{$bagian->id}.0.iku_id", (string) $this->iku->id)
            ->set("baris.{$bagian->id}.0.teks", 'Poin baru tanpa hak')
            ->call('simpan', $bagian->id)
            ->assertForbidden();

        $this->assertDatabaseCount('bagian_kustom_poin', 0);
        $this->isian()->call('tambahBaris', $bagian->id)->assertForbidden();
    }

    public function test_tanpa_hak_ubah_poin_lama_tidak_berubah_tetapi_simpan_tanpa_perubahan_boleh(): void
    {
        $this->sebagai('Tim SAKIP');
        $bagian = $this->bagianSakip(['buat']);
        $poin = $this->poinSakip($bagian);

        // Tanpa perubahan pada poin lama: tidak ditolak.
        $this->isian()->call('simpan', $bagian->id)->assertHasNoErrors()->assertOk();

        $this->isian()
            ->set("baris.{$bagian->id}.0.teks", 'Diubah diam-diam')
            ->call('simpan', $bagian->id)
            ->assertForbidden();

        $this->assertSame('Poin lama', $poin->fresh()->teks);
    }

    public function test_tanpa_hak_hapus_poin_lama_tetap_ada(): void
    {
        $this->sebagai('Tim SAKIP');
        $bagian = $this->bagianSakip(['buat', 'ubah']);
        $poin = $this->poinSakip($bagian);

        $this->isian()->call('hapusBaris', $bagian->id, 0)->assertForbidden();

        // Manipulasi state: baris poin lama dikosongkan lalu disimpan = penghapusan.
        $this->isian()
            ->set("baris.{$bagian->id}.0.teks", '')
            ->call('simpan', $bagian->id)
            ->assertForbidden();

        $this->assertNotNull($poin->fresh());
    }

    public function test_tanpa_hak_hapus_bukti_ditolak_sebelum_apa_pun(): void
    {
        $this->sebagai('Tim SAKIP');
        $bagian = $this->bagianSakip(['buat', 'ubah', 'hapus', 'unggah_bukti']);
        $poin = $this->poinSakip($bagian);

        $this->isian()->call('hapusBukti', $bagian->id, $poin->id, 999)->assertForbidden();
    }

    public function test_hak_penuh_tetap_berjalan_seperti_sebelumnya(): void
    {
        $this->sebagai('Tim SAKIP');
        $bagian = $this->bagianSakip(null);
        $poin = $this->poinSakip($bagian);

        $this->isian()
            ->set("baris.{$bagian->id}.0.teks", 'Diubah sah')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $this->assertSame('Diubah sah', $poin->fresh()->teks);
    }

    public function test_pembaca_tidak_bisa_menyimpan_lewat_request_langsung(): void
    {
        $this->sebagai('Kepala');
        $bagian = $this->bagianSakip(null, ['peran_pembaca' => ['kepala']]);

        $this->isian()->call('simpan', $bagian->id)->assertForbidden();
        $this->isian()->call('tambahBaris', $bagian->id)->assertForbidden();
    }

    /* ---------- Isian Kegiatan (Ketua Tim) ---------- */

    protected function bagianKetua(array $aksi): BagianKustom
    {
        BagianKustom::lupakanCache();

        return BagianKustom::create([
            'nama' => 'Risiko Ketua', 'aktif' => true, 'bukti_wajib' => false,
            'peran' => ['ketua_tim'], 'penempatan' => ['pengisian'],
            'hak_aksi' => ['ketua_tim' => $aksi],
        ]);
    }

    protected function pengisian()
    {
        return Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)->set('bulan', 4)->set('iku_id', $this->iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji')
            ->set('blocks.0.jenis', 'bukan_survei_sensus');
    }

    public function test_ketua_tanpa_hak_buat_tidak_bisa_menambah_poin_di_isian_kegiatan(): void
    {
        $this->sebagai('Ketua Tim');
        $bagian = $this->bagianKetua(['ubah']);

        $this->pengisian()
            ->assertDontSee('Tambah Poin Risiko Ketua')
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Poin tanpa hak')
            ->call('simpanDraft')
            ->assertForbidden();

        $this->assertDatabaseCount('bagian_kustom_poin', 0);
        $this->pengisian()->call('addBagianKustomBlock', $bagian->id)->assertForbidden();
    }

    public function test_ketua_tanpa_hak_ubah_tidak_bisa_mengubah_poin_lama(): void
    {
        $this->sebagai('Ketua Tim');
        $bagian = $this->bagianKetua(['buat']);
        $poin = BagianKustomPoin::create([
            'bagian_kustom_id' => $bagian->id, 'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id,
            'teks' => 'Asli', 'menu' => 'pengisian', 'peran_pengisi' => 'ketua_tim',
        ]);

        $this->pengisian()
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Diubah tanpa hak')
            ->call('simpanDraft')
            ->assertForbidden();

        $this->assertSame('Asli', $poin->fresh()->teks);
    }

    public function test_ketua_tanpa_hak_hapus_bukti_ditolak(): void
    {
        $this->sebagai('Ketua Tim');
        $bagian = $this->bagianKetua(['buat', 'ubah']);
        $poin = BagianKustomPoin::create([
            'bagian_kustom_id' => $bagian->id, 'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id,
            'teks' => 'Asli', 'menu' => 'pengisian', 'peran_pengisi' => 'ketua_tim',
        ]);

        $this->pengisian()->call('hapusBuktiLamaBagianKustom', $poin->id, 999)->assertForbidden();
    }

    /* ---------- Pengaturan matriks (Data Master) ---------- */

    public function test_tim_sakip_mengatur_matriks_dan_tercatat_di_riwayat(): void
    {
        $this->sebagai('Tim SAKIP');
        $bagian = BagianKustom::create(['nama' => 'Diatur', 'aktif' => true, 'peran' => ['ketua_tim'], 'penempatan' => ['pengisian']]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.hak_aksi.ketua_tim.hapus", false)
            ->set("form.{$bagian->id}.hak_aksi.ketua_tim.hapus_bukti", false)
            // Peran bukan pengisi: diabaikan, tidak tersimpan.
            ->set("form.{$bagian->id}.hak_aksi.kepala.buat", true)
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $bagian->refresh();
        $this->assertSame(['ketua_tim' => ['buat', 'ubah', 'unggah_bukti']], $bagian->hak_aksi);

        $riwayat = RiwayatKonfigurasiBagianKustom::where('bagian_kustom_id', $bagian->id)->latest('id')->first();
        $this->assertStringContainsString('hak aksi', $riwayat->ringkasan);
        $this->assertSame(['buat', 'ubah', 'hapus', 'unggah_bukti', 'hapus_bukti'], $riwayat->sebelum['hak_aksi']['ketua_tim']);
    }

    public function test_simpan_tanpa_mengubah_matriks_mempertahankan_null_bagian_lama(): void
    {
        $this->sebagai('Tim SAKIP');
        $bagian = BagianKustom::create(['nama' => 'Lama', 'aktif' => true, 'peran' => ['ketua_tim'], 'penempatan' => ['pengisian']]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.deskripsi", 'Deskripsi baru')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $this->assertNull($bagian->fresh()->hak_aksi);
    }

    public function test_peran_selain_tim_sakip_tidak_bisa_mengubah_hak_atau_label(): void
    {
        $bagian = BagianKustom::create(['nama' => 'Dijaga', 'aktif' => true, 'peran' => ['ketua_tim'], 'penempatan' => ['pengisian']]);

        foreach (['Ketua Tim', 'Kepala'] as $peran) {
            $this->sebagai($peran);
            Livewire::test(BagianKustomManager::class)->assertForbidden();
            Livewire::test(DaftarBagianSipinter::class)->assertForbidden();
        }

        $this->assertNull($bagian->fresh()->hak_aksi);
        $this->assertDatabaseCount('pengaturan_bagian_bawaan', 0);
    }

    /* ---------- Pusat pengelolaan: seluruh bagian & label bawaan ---------- */

    public function test_daftar_memuat_bagian_bawaan_dan_kustom(): void
    {
        $this->sebagai('Tim SAKIP');
        $kustom = BagianKustom::create(['nama' => 'Kustom Terdaftar', 'aktif' => false]);

        $komponen = Livewire::test(DaftarBagianSipinter::class);

        foreach (array_keys(RegistriBagian::bawaan()) as $kode) {
            $komponen->assertSeeHtml('data-bagian-bawaan="'.$kode.'"');
        }

        $komponen->assertSeeHtml('data-bagian-kustom-daftar="'.$kustom->id.'"')->assertSee('Nonaktif');
    }

    public function test_label_bawaan_bisa_diganti_langsung_berlaku_dan_dikembalikan(): void
    {
        $this->sebagai('Tim SAKIP');

        Livewire::test(DaftarBagianSipinter::class)
            ->call('buka', 'kendala_solusi')
            ->set('formLabel.label_menu', 'Hambatan')
            ->set('formLabel.judul', 'Hambatan & Jalan Keluar')
            ->set('formLabel.petunjuk', 'Tulis satu hambatan per poin.')
            ->call('simpan')
            ->assertHasNoErrors();

        $riwayat = RiwayatKonfigurasiBagianKustom::whereNull('bagian_kustom_id')->latest('id')->first();
        $this->assertStringContainsString('Hambatan', $riwayat->ringkasan);
        $this->assertSame('kendala_solusi', $riwayat->sesudah['kode_bawaan']);

        $this->sebagai('Ketua Tim');
        Livewire::test(PengisianKegiatan::class)
            ->assertSee('Hambatan & Jalan Keluar')
            ->assertSeeHtml('data-petunjuk-bawaan="kendala_solusi"')
            // Anchor & identitas bagian tidak berubah.
            ->assertSeeHtml('id="bagian-kendala"');

        $this->sebagai('Tim SAKIP');
        Livewire::test(DaftarBagianSipinter::class)->call('buka', 'kendala_solusi')->call('kosongkan')->call('simpan');

        // Baris tidak dihapus, hanya dikosongkan = label bawaan.
        $this->assertDatabaseHas('pengaturan_bagian_bawaan', ['kode' => 'kendala_solusi', 'label_menu' => null, 'judul' => null]);

        $this->sebagai('Ketua Tim');
        Livewire::test(PengisianKegiatan::class)->assertDontSee('Hambatan & Jalan Keluar')->assertSee('Kendala & Solusi');
    }

    public function test_bagian_logika_khusus_tidak_bisa_diberi_label_dan_validasi_panjang(): void
    {
        $this->sebagai('Tim SAKIP');

        Livewire::test(DaftarBagianSipinter::class)->call('buka', 'verifikasi')->assertForbidden();

        Livewire::test(DaftarBagianSipinter::class)
            ->call('buka', 'kegiatan')
            ->set('formLabel.label_menu', str_repeat('x', 41))
            ->call('simpan')
            ->assertHasErrors(['formLabel.label_menu']);

        $this->assertSame([], PengaturanBagianBawaan::peta());
    }

    public function test_penyimpanan_label_bentrok_ditolak(): void
    {
        $this->sebagai('Tim SAKIP');

        $komponen = Livewire::test(DaftarBagianSipinter::class)->call('buka', 'kegiatan')->set('formLabel.judul', 'Versi A');

        PengaturanBagianBawaan::create(['kode' => 'kegiatan', 'judul' => 'Versi orang lain']);

        $komponen->call('simpan')->assertHasErrors(['formLabel']);
        $this->assertSame('Versi orang lain', PengaturanBagianBawaan::where('kode', 'kegiatan')->value('judul'));
    }
}
