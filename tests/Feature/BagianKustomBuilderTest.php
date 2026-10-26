<?php

namespace Tests\Feature;

use App\Livewire\BagianKustomManager;
use App\Livewire\IsianBagianKustom;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Support\JenisKolomBagianKustom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tahap 2 & 3 Bagian Kustom dinamis: kode tetap, folder Drive terkunci, daftar dengan
 * cari/filter/urut, detail, duplikasi, subbagian, jenis kolom baru, dan validasi.
 */
class BagianKustomBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsPeran(string $nama): User
    {
        $peran = Role::firstOrCreate(['nama' => $nama]);
        $user = User::create([
            'nama' => $nama.' Uji', 'username' => 'u-'.uniqid(), 'email' => 'u-'.uniqid().'@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($user);

        return $user;
    }

    protected function iku(string $kode = 'UJI-101'): MasterIku
    {
        return MasterIku::create(['kode' => $kode, 'indikator' => 'Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
    }

    protected function periode(int $tahun, int $bulan): Periode
    {
        return Periode::firstOrCreate(['tahun' => $tahun, 'bulan' => $bulan], [
            'triwulan' => BagianKustom::triwulanDariBulan($bulan), 'bulan_ke' => (($bulan - 1) % 3) + 1, 'flag_bulan_terlewat' => true,
        ]);
    }

    protected function bagianSakip(array $atur = []): BagianKustom
    {
        return BagianKustom::create($atur + [
            'nama' => 'Manajemen Risiko', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom'],
            'frekuensi_wajib' => 'opsional', 'bukti_wajib' => false, 'masuk_notula' => true, 'urutan' => 1,
        ]);
    }

    /* ---------- Identitas: kode & folder ---------- */

    public function test_kode_dibuat_dari_nama_unik_dan_tetap_saat_nama_diganti(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $a = $this->bagianSakip();
        $b = $this->bagianSakip(['nama' => 'Manajemen-Risiko!']);

        $this->assertSame('manajemen_risiko', $a->kode);
        $this->assertSame('manajemen_risiko_2', $b->kode);
        $this->assertSame('Manajemen Risiko', $a->nama_folder);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$a->id}.nama", 'Risiko Organisasi')
            ->call('simpan', $a->id)
            ->assertHasNoErrors();

        $a->refresh();
        $this->assertSame('Risiko Organisasi', $a->nama);
        $this->assertSame('manajemen_risiko', $a->kode);
        $this->assertSame('Manajemen Risiko', $a->namaFolderEfektif(), 'folder Drive tetap folder lama');
    }

    public function test_kode_manual_divalidasi_pola_dan_unik(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $this->bagianSakip();

        Livewire::test(BagianKustomManager::class)
            ->set('namaBaru', 'Bagian Lain')
            ->set('kodeBaru', 'Kode Salah')
            ->call('tambah')
            ->assertHasErrors('kodeBaru')
            ->set('kodeBaru', 'manajemen_risiko')
            ->call('tambah')
            ->assertHasErrors('kodeBaru')
            ->set('kodeBaru', 'bagian_lain_x')
            ->call('tambah')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('bagian_kustom', ['nama' => 'Bagian Lain', 'kode' => 'bagian_lain_x']);
    }

    /* ---------- Daftar: cari, filter, urut, detail, duplikasi ---------- */

    public function test_daftar_bisa_dicari_difilter_dan_diurutkan(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $this->bagianSakip(['nama' => 'Zeta Risiko', 'urutan' => 1]);
        $this->bagianSakip(['nama' => 'Alfa Kepatuhan', 'urutan' => 2, 'aktif' => false, 'peran' => ['ketua_tim'], 'penempatan' => ['pengisian']]);

        $lw = Livewire::test(BagianKustomManager::class);
        $nama = fn () => $lw->instance()->daftarTampil()->pluck('nama')->all();

        $this->assertSame(['Zeta Risiko', 'Alfa Kepatuhan'], $nama());
        $lw->set('urut', 'nama');
        $this->assertSame(['Alfa Kepatuhan', 'Zeta Risiko'], $nama());
        $lw->set('urut', 'urutan')->set('filterStatus', 'nonaktif');
        $this->assertSame(['Alfa Kepatuhan'], $nama());
        $lw->set('filterStatus', 'semua')->set('filterPeran', 'tim_sakip');
        $this->assertSame(['Zeta Risiko'], $nama());
        $lw->set('filterPeran', '')->set('filterMenu', 'pengisian');
        $this->assertSame(['Alfa Kepatuhan'], $nama());
        $lw->set('filterMenu', '')->set('cari', 'zeta');
        $this->assertSame(['Zeta Risiko'], $nama());
        $lw->assertSee('Hapus filter')->call('resetFilter')->assertSet('cari', '');
        $this->assertCount(2, $nama());
    }

    public function test_detail_menampilkan_struktur_tanpa_mengubah_data(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $b = $this->bagianSakip([
            'subbagian' => [['kunci' => 'sub_1', 'nama' => 'Identifikasi', 'aktif' => true]],
            'kolom' => [['kunci' => 'level', 'label' => 'Level Risiko', 'tipe' => 'pilihan', 'opsi' => ['Rendah', 'Tinggi'], 'subbagian' => 'sub_1']],
        ]);
        $sebelum = $b->fresh()->updated_at;

        Livewire::test(BagianKustomManager::class)
            ->call('lihatDetail', $b->id)
            ->assertSee('Subbagian: Identifikasi')
            ->assertSee('Level Risiko')
            ->assertSee('manajemen_risiko');

        $this->assertEquals($sebelum, $b->fresh()->updated_at);
    }

    public function test_duplikasi_mengisi_form_tambah_dan_tidak_menyalin_isian(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $iku = $this->iku();
        $sumber = $this->bagianSakip([
            'subbagian' => [['kunci' => 'sub_1', 'nama' => 'Identifikasi', 'aktif' => true], ['kunci' => 'sub_2', 'nama' => 'Lama', 'aktif' => false]],
            'kolom' => [
                ['kunci' => 'level', 'label' => 'Level', 'tipe' => 'pilihan', 'opsi' => ['A', 'B'], 'subbagian' => 'sub_1'],
                ['kunci' => 'arsip', 'label' => 'Arsip', 'tipe' => 'teks', 'subbagian' => 'sub_2'],
            ],
        ]);
        BagianKustomPoin::create(['bagian_kustom_id' => $sumber->id, 'iku_id' => $iku->id, 'periode_id' => $this->periode(2026, 4)->id, 'teks' => 'Risiko', 'status_verifikasi' => 'terverifikasi']);

        Livewire::test(BagianKustomManager::class)
            ->call('duplikasi', $sumber->id)
            ->assertSet('namaBaru', 'Manajemen Risiko (salinan)')
            ->assertSet('sumberSalinId', $sumber->id)
            ->call('tambah')
            ->assertHasNoErrors();

        $baru = BagianKustom::where('nama', 'Manajemen Risiko (salinan)')->firstOrFail();
        $this->assertNotSame($sumber->id, $baru->id);
        $this->assertSame('manajemen_risiko_salinan', $baru->kode);
        $this->assertSame(['sub_1'], array_column($baru->subbagianList(), 'kunci'), 'subbagian nonaktif tidak disalin');
        $this->assertSame(['level'], array_column($baru->kolomList(), 'kunci'), 'kolom di subbagian nonaktif tidak disalin');
        $this->assertSame(0, $baru->poin()->count());
        $this->assertSame(1, $sumber->poin()->count());
    }

    public function test_nonaktifkan_dan_aktifkan_kembali_tidak_menghapus_isian(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $b = $this->bagianSakip();
        BagianKustomPoin::create(['bagian_kustom_id' => $b->id, 'iku_id' => $this->iku()->id, 'periode_id' => $this->periode(2026, 4)->id, 'teks' => 'X', 'status_verifikasi' => 'terverifikasi']);

        Livewire::test(BagianKustomManager::class)->call('toggleAktif', $b->id, false);
        $this->assertFalse($b->fresh()->aktif);
        $this->assertSame(1, BagianKustomPoin::where('bagian_kustom_id', $b->id)->count());

        Livewire::test(BagianKustomManager::class)->call('toggleAktif', $b->id, true);
        $this->assertTrue($b->fresh()->aktif);
        $this->assertSame(1, BagianKustomPoin::where('bagian_kustom_id', $b->id)->count());
    }

    /* ---------- Subbagian ---------- */

    public function test_tambah_urutkan_dan_simpan_subbagian_dengan_kolom(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $b = $this->bagianSakip();

        $lw = Livewire::test(BagianKustomManager::class)
            ->call('tambahSubbagian', $b->id)
            ->call('tambahSubbagian', $b->id)
            ->set("form.{$b->id}.subbagian.0.nama", 'Identifikasi')
            ->set("form.{$b->id}.subbagian.1.nama", 'Mitigasi')
            ->call('turunkanSubbagian', $b->id, 0)
            ->call('tambahKolom', $b->id, 'sub_1')
            ->set("form.{$b->id}.kolom.0.label", 'Nama Risiko')
            ->call('tambahKolom', $b->id)
            ->set("form.{$b->id}.kolom.1.label", 'Catatan Umum')
            ->call('simpan', $b->id)
            ->assertHasNoErrors();

        $b->refresh();
        $this->assertSame(['Mitigasi', 'Identifikasi'], array_column($b->subbagianList(), 'nama'));
        $this->assertSame('sub_1', $b->kolomList()[0]['subbagian']);
        $this->assertNull($b->kolomList()[1]['subbagian']);

        $kelompok = $b->kelompokKolomBerlaku(2026, 2);
        $this->assertNull($kelompok[0]['subbagian'], 'kolom langsung tampil lebih dulu');
        $this->assertSame('Identifikasi', $kelompok[1]['subbagian']['nama']);
        $this->assertDatabaseHas('riwayat_konfigurasi_bagian_kustom', ['bagian_kustom_id' => $b->id, 'aksi' => 'ubah']);
        $this->assertStringContainsString('subbagian "Mitigasi" ditambah', (string) \App\Models\RiwayatKonfigurasiBagianKustom::where('aksi', 'ubah')->latest('id')->value('ringkasan'));
    }

    public function test_subbagian_berisi_kolom_tidak_bisa_dihapus(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $b = $this->bagianSakip([
            'subbagian' => [['kunci' => 'sub_1', 'nama' => 'Identifikasi', 'aktif' => true]],
            'kolom' => [['kunci' => 'nama_risiko', 'label' => 'Nama Risiko', 'tipe' => 'teks', 'subbagian' => 'sub_1']],
        ]);

        Livewire::test(BagianKustomManager::class)
            ->call('hapusSubbagian', $b->id, 0)
            ->assertHasErrors("form.{$b->id}.subbagian.0.nama")
            ->assertCount("form.{$b->id}.subbagian", 1);
    }

    public function test_kolom_di_subbagian_nonaktif_tidak_tampil_tetapi_nilainya_dipertahankan(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $iku = $this->iku();
        $periode = $this->periode((int) now()->year, (int) now()->month);
        $b = $this->bagianSakip([
            'subbagian' => [['kunci' => 'sub_1', 'nama' => 'Lama', 'aktif' => false]],
            'kolom' => [
                ['kunci' => 'arsip', 'label' => 'Arsip', 'tipe' => 'teks', 'wajib' => true, 'subbagian' => 'sub_1'],
                ['kunci' => 'catatan', 'label' => 'Catatan', 'tipe' => 'teks'],
            ],
        ]);
        $poin = BagianKustomPoin::create([
            'bagian_kustom_id' => $b->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id, 'menu' => 'isian_bagian_kustom',
            'peran_pengisi' => 'tim_sakip', 'teks' => 'Poin', 'nilai_kolom' => ['arsip' => 'Data lama'], 'status_verifikasi' => 'terverifikasi',
        ]);

        $this->assertSame(['catatan'], array_column($b->kolomBerlaku(null, null), 'kunci'));

        Livewire::test(IsianBagianKustom::class)
            ->assertDontSee('Arsip')
            ->set("baris.{$b->id}.0.kolom.catatan", 'Baru')
            ->call('simpan', $b->id)
            ->assertHasNoErrors();

        $this->assertSame(['arsip' => 'Data lama', 'catatan' => 'Baru'], $poin->fresh()->nilai_kolom);
    }

    /* ---------- Jenis kolom baru & validasi ---------- */

    public function test_jenis_kolom_baru_disimpan_divalidasi_dan_ditampilkan(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $iku = $this->iku();
        $this->periode((int) now()->year, (int) now()->month);
        $b = $this->bagianSakip(['kolom' => [
            ['kunci' => 'uraian_panjang', 'label' => 'Uraian Panjang', 'tipe' => 'teks_panjang', 'panjang_min' => 5],
            ['kunci' => 'tenggat', 'label' => 'Tenggat', 'tipe' => 'tanggal', 'format_tanggal' => 'panjang'],
            ['kunci' => 'dampak', 'label' => 'Dampak', 'tipe' => 'pilihan_ganda', 'opsi' => ['Biaya', 'Waktu', 'Mutu'], 'wajib' => true],
            ['kunci' => 'skor', 'label' => 'Skor', 'tipe' => 'angka', 'min' => '1', 'maks' => '25', 'pesan' => 'Skor harus 1 sampai 25.'],
        ]]);

        $lw = Livewire::test(IsianBagianKustom::class)
            ->set("baris.{$b->id}.0.iku_id", (string) $iku->id)
            ->set("baris.{$b->id}.0.teks", 'Risiko keterlambatan')
            ->set("baris.{$b->id}.0.kolom.uraian_panjang", 'abc')
            ->set("baris.{$b->id}.0.kolom.tenggat", '2026-02-30')
            ->set("baris.{$b->id}.0.kolom.dampak", '')
            ->set("baris.{$b->id}.0.kolom.skor", '30')
            ->call('simpan', $b->id)
            ->assertHasErrors([
                "baris.{$b->id}.0.kolom.uraian_panjang",
                "baris.{$b->id}.0.kolom.tenggat",
                "baris.{$b->id}.0.kolom.dampak",
                "baris.{$b->id}.0.kolom.skor",
            ]);
        $this->assertSame('Skor harus 1 sampai 25.', $lw->errors()->first("baris.{$b->id}.0.kolom.skor"));
        $this->assertSame(0, BagianKustomPoin::count());

        $lw->set("baris.{$b->id}.0.kolom.uraian_panjang", "Baris satu\r\nBaris dua")
            ->set("baris.{$b->id}.0.kolom.tenggat", '2026-03-31')
            ->set("baris.{$b->id}.0.kolom.dampak", '["Mutu","Biaya"]')
            ->set("baris.{$b->id}.0.kolom.skor", '12')
            ->call('simpan', $b->id)
            ->assertHasNoErrors();

        $poin = BagianKustomPoin::firstOrFail();
        $this->assertSame("Baris satu\nBaris dua", $poin->nilai_kolom['uraian_panjang']);
        $this->assertSame('2026-03-31', $poin->nilai_kolom['tenggat']);
        $this->assertSame('["Biaya","Mutu"]', $poin->nilai_kolom['dampak'], 'urut mengikuti opsi');

        $tampil = $b->fresh()->nilaiKolomTampil($poin->nilai_kolom);
        $this->assertSame('31 Maret 2026', $tampil['tenggat']);
        $this->assertSame('Biaya, Mutu', $tampil['dampak']);

        // Dimuat ulang ke formulir dalam bentuk yang sama.
        Livewire::test(IsianBagianKustom::class)->assertSet("baris.{$b->id}.0.kolom.dampak", '["Biaya","Mutu"]');
    }

    public function test_pilihan_ganda_menolak_opsi_di_luar_daftar_dan_kosong_dianggap_tidak_terisi(): void
    {
        $kolom = BagianKustom::normalkanKolom(['kunci' => 'd', 'label' => 'Dampak', 'tipe' => 'pilihan_ganda', 'opsi' => ['A', 'B']]);

        $this->assertNotNull(JenisKolomBagianKustom::pesanGalat($kolom, '["A","Z"]'));
        $this->assertNull(JenisKolomBagianKustom::pesanGalat($kolom, '["B"]'));
        $this->assertFalse(JenisKolomBagianKustom::terisi('[]'));
        $this->assertSame('', JenisKolomBagianKustom::untukDisimpan('[]', $kolom));
        // Data lama dari kolom pilihan tunggal dibaca sebagai satu pilihan.
        $this->assertSame(['A'], JenisKolomBagianKustom::daftarPilihan('A'));
    }

    public function test_ganti_jenis_kolom_hanya_bila_data_lama_cocok(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $iku = $this->iku();
        $b = $this->bagianSakip(['kolom' => [
            ['kunci' => 'tenggat', 'label' => 'Tenggat', 'tipe' => 'teks'],
            ['kunci' => 'level', 'label' => 'Level', 'tipe' => 'pilihan', 'opsi' => ['A', 'B']],
        ]]);
        BagianKustomPoin::create([
            'bagian_kustom_id' => $b->id, 'iku_id' => $iku->id, 'periode_id' => $this->periode(2026, 4)->id,
            'teks' => 'P', 'nilai_kolom' => ['tenggat' => 'akhir Maret', 'level' => 'A'], 'status_verifikasi' => 'terverifikasi',
        ]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$b->id}.kolom.0.tipe", 'tanggal')
            ->call('simpan', $b->id)
            ->assertHasErrors("form.{$b->id}.kolom");
        $this->assertSame('teks', $b->fresh()->kolomList()[0]['tipe']);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$b->id}.kolom.1.tipe", 'pilihan_ganda')
            ->call('simpan', $b->id)
            ->assertHasNoErrors();
        $this->assertSame('pilihan_ganda', $b->fresh()->kolomList()[1]['tipe']);
        $this->assertSame('A', $b->fresh()->nilaiKolomTampil(BagianKustomPoin::first()->nilai_kolom)['level'], 'nilai lama tidak dikonversi tetapi tetap terbaca');
    }

    public function test_aturan_batas_kolom_divalidasi_di_builder(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $b = $this->bagianSakip(['kolom' => [['kunci' => 'skor', 'label' => 'Skor', 'tipe' => 'angka']]]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$b->id}.kolom.0.min", '10')
            ->set("form.{$b->id}.kolom.0.maks", '5')
            ->call('simpan', $b->id)
            ->assertHasErrors("form.{$b->id}.kolom.0.maks")
            ->set("form.{$b->id}.kolom.0.maks", 'abc')
            ->call('simpan', $b->id)
            ->assertHasErrors("form.{$b->id}.kolom.0.maks")
            ->set("form.{$b->id}.kolom.0.maks", '50')
            ->call('simpan', $b->id)
            ->assertHasNoErrors();

        $this->assertSame(['10', '50'], [$b->fresh()->kolomList()[0]['min'], $b->fresh()->kolomList()[0]['maks']]);
    }

    public function test_bagian_lama_tanpa_pengaturan_baru_berperilaku_sama(): void
    {
        $b = $this->bagianSakip(['kolom' => [['kunci' => 'level', 'label' => 'Level', 'tipe' => 'teks']]]);
        $k = $b->kolomList()[0];

        $this->assertNull($k['subbagian']);
        $this->assertSame([], $b->subbagianList());
        $this->assertSame([['subbagian' => null, 'kolom' => [$k]]], $b->kelompokKolomBerlaku(2026, 1));
        $this->assertNull(BagianKustom::pesanGalatKolom($k, 'Tinggi'));
    }

    public function test_hanya_tim_sakip_boleh_membuka_builder(): void
    {
        $this->actingAsPeran('Ketua Tim');
        Livewire::test(BagianKustomManager::class)->assertForbidden();
    }
}
