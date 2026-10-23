<?php

namespace Tests\Feature;

use App\Livewire\BagianKustomManager;
use App\Livewire\IsianBagianKustom;
use App\Livewire\PengisianKegiatan;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\RiwayatKonfigurasiBagianKustom;
use App\Models\Role;
use App\Models\User;
use App\Services\NotulaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pusat konfigurasi Bagian Kustom: satu mekanisme untuk membuat (kosong/salin), mengedit
 * bagian lama, format angka, periode mulai berlaku, nonaktif kolom, dan riwayat.
 */
class BagianKustomPusatKonfigurasiTest extends TestCase
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

    /** Kolom angka berformat: 2 desimal, koma, ribuan, satuan persen. */
    protected function kolomPersen(array $atur = []): array
    {
        return $atur + [
            'kunci' => 'tingkat', 'label' => 'Tingkat Penyelesaian', 'tipe' => 'angka', 'opsi' => [], 'wajib' => false,
            'desimal' => 2, 'pemisah' => 'koma', 'ribuan' => true, 'satuan' => 'persen',
        ];
    }

    /* ---------- Membuat: kosong & salin ---------- */

    public function test_bagian_baru_dari_kosong_tercatat_di_riwayat(): void
    {
        $this->actingAsPeran('Tim SAKIP');

        Livewire::test(BagianKustomManager::class)
            ->set('namaBaru', 'Inovasi Layanan')
            ->call('tambah')
            ->assertHasNoErrors();

        $bagian = BagianKustom::where('nama', 'Inovasi Layanan')->firstOrFail();
        $this->assertSame([], $bagian->kolomList());
        $this->assertDatabaseHas('riwayat_konfigurasi_bagian_kustom', ['bagian_kustom_id' => $bagian->id, 'aksi' => 'buat']);
    }

    public function test_salin_pengaturan_menyalin_definisi_tanpa_isian_bukti_maupun_riwayat(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $iku = $this->iku();
        $periode = $this->periode(2026, 4);

        $sumber = BagianKustom::create([
            'nama' => 'Manajemen Risiko', 'aktif' => true, 'peran' => ['ketua_tim'], 'penempatan' => ['pengisian'],
            'frekuensi_wajib' => 'akhir_triwulan', 'bukti_wajib' => true, 'masuk_notula' => true,
            'label_uraian' => 'Nama Risiko', 'judul_notula' => 'Daftar Risiko', 'format_notula' => '{kode_iku}: {teks} ({tingkat})',
            'kolom' => [
                $this->kolomPersen(['mulai_tahun' => 2026, 'mulai_triwulan' => 3]),
                ['kunci' => 'lama', 'label' => 'Kolom Lama', 'tipe' => 'teks', 'aktif' => false],
            ],
        ]);
        $poin = BagianKustomPoin::create([
            'bagian_kustom_id' => $sumber->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'teks' => 'Risiko A', 'nilai_kolom' => ['tingkat' => '80'], 'status_verifikasi' => 'terverifikasi',
        ]);
        Berkas::create([
            'ref_id' => $poin->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom',
            'nama_file' => 'bukti.pdf', 'path' => 'bukti/bukti.pdf', 'status_verifikasi' => 'terverifikasi',
        ]);
        RiwayatKonfigurasiBagianKustom::catat($sumber, $sumber->nama, 'ubah', null, null, 'Riwayat milik sumber');
        $kolomSumberSebelum = $sumber->fresh()->kolom;

        Livewire::test(BagianKustomManager::class)
            ->set('sumberSalinId', $sumber->id)
            ->assertSet('frekuensiWajibBaru', 'akhir_triwulan')
            ->assertSee('Yang akan disalin dari')
            ->assertSee('1 poin isian')
            ->set('namaBaru', 'Manajemen Risiko Operasional')
            ->call('tambah')
            ->assertHasNoErrors();

        $salinan = BagianKustom::where('nama', 'Manajemen Risiko Operasional')->firstOrFail();

        $this->assertNotSame($sumber->id, $salinan->id);
        $this->assertSame(['ketua_tim'], $salinan->peran);
        $this->assertSame(['pengisian'], $salinan->penempatan);
        $this->assertTrue($salinan->bukti_wajib);
        $this->assertSame('Nama Risiko', $salinan->label_uraian);
        $this->assertSame('{kode_iku}: {teks} ({tingkat})', $salinan->format_notula);
        $this->assertNull($salinan->judul_notula, 'Judul notula memakai nama bagian baru.');

        // Hanya kolom aktif, kunci & format sama, tanpa periode mulai berlaku.
        $kolom = $salinan->kolomList();
        $this->assertCount(1, $kolom);
        $this->assertSame('tingkat', $kolom[0]['kunci']);
        $this->assertSame(2, $kolom[0]['desimal']);
        $this->assertSame('persen', $kolom[0]['satuan']);
        $this->assertNull($kolom[0]['mulai_tahun']);

        // Tidak ada data transaksi yang ikut tersalin.
        $this->assertSame(0, BagianKustomPoin::where('bagian_kustom_id', $salinan->id)->count());
        $this->assertSame(1, BagianKustomPoin::where('bagian_kustom_id', $sumber->id)->count());
        $this->assertSame(1, Berkas::count());
        $this->assertSame(1, RiwayatKonfigurasiBagianKustom::where('bagian_kustom_id', $sumber->id)->count());
        $this->assertSame(['salin'], RiwayatKonfigurasiBagianKustom::where('bagian_kustom_id', $salinan->id)->pluck('aksi')->all());

        // Sumber tidak berubah.
        $this->assertEquals($kolomSumberSebelum, $sumber->fresh()->kolom);
    }

    public function test_mengubah_salinan_tidak_mengubah_bagian_sumber(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $sumber = BagianKustom::create(['nama' => 'Sumber', 'aktif' => true, 'kolom' => [$this->kolomPersen()]]);

        Livewire::test(BagianKustomManager::class)
            ->set('sumberSalinId', $sumber->id)
            ->set('namaBaru', 'Salinan')
            ->call('tambah');

        $salinan = BagianKustom::where('nama', 'Salinan')->firstOrFail();

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$salinan->id}.kolom.0.label", 'Label Baru')
            ->set("form.{$salinan->id}.kolom.0.desimal", '0')
            ->call('simpan', $salinan->id)
            ->assertHasNoErrors();

        $this->assertSame('Label Baru', $salinan->fresh()->kolomList()[0]['label']);
        $this->assertSame('Tingkat Penyelesaian', $sumber->fresh()->kolomList()[0]['label']);
        $this->assertSame(2, $sumber->fresh()->kolomList()[0]['desimal']);
    }

    public function test_salin_dari_bagian_yang_tidak_ada_ditolak(): void
    {
        $this->actingAsPeran('Tim SAKIP');

        Livewire::test(BagianKustomManager::class)
            ->set('namaBaru', 'Apa Saja')
            ->set('sumberSalinId', 99999)
            ->call('tambah')
            ->assertHasErrors(['sumberSalinId']);

        $this->assertDatabaseMissing('bagian_kustom', ['nama' => 'Apa Saja']);
    }

    public function test_nama_bagian_salinan_tidak_boleh_sama(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $sumber = BagianKustom::create(['nama' => 'Sumber', 'aktif' => true]);

        Livewire::test(BagianKustomManager::class)
            ->set('sumberSalinId', $sumber->id)
            ->set('namaBaru', 'sumber')
            ->call('tambah')
            ->assertHasErrors(['namaBaru']);

        $this->assertSame(1, BagianKustom::count());
    }

    /* ---------- Mengedit bagian lama ---------- */

    public function test_bagian_lama_bisa_diubah_tanpa_menyentuh_isian_dan_tercatat(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $iku = $this->iku();
        $periode = $this->periode(2026, 4);
        $bagian = BagianKustom::create([
            'nama' => 'Risiko', 'aktif' => true, 'kolom' => [['kunci' => 'skor', 'label' => 'Skor', 'tipe' => 'angka']],
        ]);
        $poin = BagianKustomPoin::create([
            'bagian_kustom_id' => $bagian->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'teks' => 'Risiko A', 'nilai_kolom' => ['skor' => '12,5'], 'status_verifikasi' => 'terverifikasi',
        ]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.nama", 'Risiko Strategis')
            ->set("form.{$bagian->id}.deskripsi", 'Petunjuk baru')
            ->set("form.{$bagian->id}.kolom.0.label", 'Skor Risiko')
            ->set("form.{$bagian->id}.kolom.0.desimal", '2')
            ->set("form.{$bagian->id}.kolom.0.satuan", 'poin')
            ->call('tambahKolom', $bagian->id)
            ->set("form.{$bagian->id}.kolom.1.label", 'Mitigasi')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $bagian->refresh();
        $kolom = $bagian->kolomList();

        $this->assertSame('Risiko Strategis', $bagian->nama);
        $this->assertSame('skor', $kolom[0]['kunci'], 'Kunci tetap walau label diganti.');
        $this->assertSame('Skor Risiko', $kolom[0]['label']);
        $this->assertSame('mitigasi', $kolom[1]['kunci']);

        // Isian lama tidak diubah — format hanya tampilan.
        $poin->refresh();
        $this->assertSame(['skor' => '12,5'], $poin->nilai_kolom);
        $this->assertSame('terverifikasi', $poin->status_verifikasi);
        $this->assertSame('12,50 poin', BagianKustom::nilaiTampil($kolom[0], $poin->nilai_kolom['skor']));

        $riwayat = RiwayatKonfigurasiBagianKustom::where('bagian_kustom_id', $bagian->id)->where('aksi', 'ubah')->firstOrFail();
        $this->assertStringContainsString('nama "Risiko" → "Risiko Strategis"', $riwayat->ringkasan);
        $this->assertStringContainsString('kolom "Mitigasi" ditambah', $riwayat->ringkasan);
        $this->assertStringContainsString('kolom "Skor Risiko" diubah', $riwayat->ringkasan);
        $this->assertSame('Risiko', $riwayat->sebelum['nama']);
        $this->assertNotNull($riwayat->user_id);
    }

    public function test_simpan_tanpa_perubahan_tidak_menambah_riwayat(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $bagian = BagianKustom::create(['nama' => 'Tetap', 'aktif' => true, 'kolom' => [$this->kolomPersen()]]);

        Livewire::test(BagianKustomManager::class)->call('simpan', $bagian->id)->assertHasNoErrors();

        $this->assertSame(0, RiwayatKonfigurasiBagianKustom::count());
    }

    public function test_kolom_baru_di_bagian_berisi_default_mulai_triwulan_berjalan(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $iku = $this->iku();
        $bagian = BagianKustom::create(['nama' => 'Berisi', 'aktif' => true]);
        BagianKustomPoin::create(['bagian_kustom_id' => $bagian->id, 'iku_id' => $iku->id, 'periode_id' => $this->periode(2026, 1)->id, 'teks' => 'Lama']);
        $kosong = BagianKustom::create(['nama' => 'Kosong', 'aktif' => true]);

        $komponen = Livewire::test(BagianKustomManager::class)
            ->call('tambahKolom', $bagian->id)
            ->call('tambahKolom', $kosong->id);

        $this->assertSame((string) now()->year, $komponen->get("form.{$bagian->id}.kolom.0.mulai_tahun"));
        $this->assertSame((string) BagianKustom::triwulanDariBulan((int) now()->month), $komponen->get("form.{$bagian->id}.kolom.0.mulai_triwulan"));
        $this->assertSame('', $komponen->get("form.{$kosong->id}.kolom.0.mulai_tahun"));
    }

    public function test_kolom_berisi_data_dinonaktifkan_bukan_dihapus_dan_datanya_tetap(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $iku = $this->iku();
        $bagian = BagianKustom::create([
            'nama' => 'Risiko', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom'],
            'kolom' => [['kunci' => 'level', 'label' => 'Level', 'tipe' => 'teks'], ['kunci' => 'catatan', 'label' => 'Catatan', 'tipe' => 'teks']],
        ]);
        $periode = $this->periode(2026, 4);
        $poin = BagianKustomPoin::create([
            'bagian_kustom_id' => $bagian->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'menu' => 'isian_bagian_kustom', 'peran_pengisi' => 'tim_sakip',
            'teks' => 'Risiko A', 'nilai_kolom' => ['level' => 'Tinggi', 'catatan' => 'awal'], 'status_verifikasi' => 'terverifikasi',
        ]);

        // Hapus kolom berisi data → ditolak, pesan mengarahkan ke penonaktifan.
        Livewire::test(BagianKustomManager::class)
            ->call('hapusKolom', $bagian->id, 0)
            ->call('simpan', $bagian->id)
            ->assertHasErrors(["form.{$bagian->id}.kolom"])
            ->assertSee('Aktif');

        // Nonaktifkan → tersimpan, data tetap.
        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.kolom.0.aktif", false)
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $bagian->refresh();
        $this->assertFalse($bagian->kolomList()[0]['aktif']);
        $this->assertCount(1, $bagian->kolomBerlaku(2026, 2));
        $this->assertStringContainsString('dinonaktifkan', RiwayatKonfigurasiBagianKustom::latest('id')->first()->ringkasan);

        // Formulir tidak lagi menampilkan kolom nonaktif, dan menyimpan ulang poin lama
        // TIDAK menghapus nilai kolom nonaktif tersebut.
        $this->actingAsPeran('Tim SAKIP');
        Livewire::test(IsianBagianKustom::class)
            ->set('tahun', 2026)
            ->set('bulan', 4)
            ->call('muat')
            ->assertDontSee('>Level', false)
            ->set("baris.{$bagian->id}.0.kolom.catatan", 'diperbarui')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $this->assertSame(['level' => 'Tinggi', 'catatan' => 'diperbarui'], $poin->fresh()->nilai_kolom);
    }

    public function test_ganti_jenis_kolom_hanya_bila_data_lama_cocok(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $iku = $this->iku();
        $periode = $this->periode(2026, 4);
        $bagian = BagianKustom::create([
            'nama' => 'Campur', 'aktif' => true,
            'kolom' => [['kunci' => 'nilai', 'label' => 'Nilai', 'tipe' => 'teks'], ['kunci' => 'jumlah', 'label' => 'Jumlah', 'tipe' => 'teks']],
        ]);
        BagianKustomPoin::create([
            'bagian_kustom_id' => $bagian->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'teks' => 'A', 'nilai_kolom' => ['nilai' => 'tinggi', 'jumlah' => '12,5'],
        ]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.kolom.0.tipe", 'angka')
            ->call('simpan', $bagian->id)
            ->assertHasErrors(["form.{$bagian->id}.kolom"]);

        $this->assertSame('teks', $bagian->fresh()->kolomList()[0]['tipe']);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.kolom.1.tipe", 'angka')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $this->assertSame('angka', $bagian->fresh()->kolomList()[1]['tipe']);
    }

    public function test_pengaturan_kolom_tidak_sah_ditolak_backend(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $bagian = BagianKustom::create(['nama' => 'Validasi', 'aktif' => true, 'kolom' => [$this->kolomPersen()]]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.kolom.0.desimal", '9')
            ->call('simpan', $bagian->id)
            ->assertHasErrors(["form.{$bagian->id}.kolom.0.desimal"]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.kolom.0.pemisah", 'spasi')
            ->call('simpan', $bagian->id)
            ->assertHasErrors(["form.{$bagian->id}.kolom.0.pemisah"]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.kolom.0.mulai_triwulan", '3')
            ->set("form.{$bagian->id}.kolom.0.mulai_tahun", '')
            ->call('simpan', $bagian->id)
            ->assertHasErrors(["form.{$bagian->id}.kolom.0.mulai_triwulan"]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.kolom.0.mulai_triwulan", '5')
            ->set("form.{$bagian->id}.kolom.0.mulai_tahun", '2026')
            ->call('simpan', $bagian->id)
            ->assertHasErrors(["form.{$bagian->id}.kolom.0.mulai_triwulan"]);

        $this->assertSame(2, $bagian->fresh()->kolomList()[0]['desimal']);
    }

    public function test_aktif_nonaktif_urutan_dan_hapus_bagian_tercatat(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $a = BagianKustom::create(['nama' => 'A', 'aktif' => true, 'urutan' => 1]);
        $b = BagianKustom::create(['nama' => 'B', 'aktif' => true, 'urutan' => 2]);

        Livewire::test(BagianKustomManager::class)
            ->call('toggleAktif', $a->id)
            ->call('naikkan', $b->id)
            ->call('confirmHapus', $b->id)
            ->call('hapus');

        $this->assertEqualsCanonicalizing(['nonaktifkan'], RiwayatKonfigurasiBagianKustom::where('bagian_kustom_id', $a->id)->pluck('aksi')->all());
        // Jejak bagian yang dihapus tetap ada (id menjadi null, nama tetap).
        $this->assertEqualsCanonicalizing(['urutan', 'hapus'], RiwayatKonfigurasiBagianKustom::where('nama_bagian', 'B')->pluck('aksi')->all());
        $this->assertSame(0, RiwayatKonfigurasiBagianKustom::where('nama_bagian', 'B')->whereNotNull('bagian_kustom_id')->count());
    }

    public function test_tab_riwayat_menampilkan_perubahan(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $bagian = BagianKustom::create(['nama' => 'Riwayat Uji', 'aktif' => true]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.deskripsi", 'Deskripsi baru')
            ->call('simpan', $bagian->id)
            ->call('buka', $bagian->id)
            ->call('setTab', 'riwayat')
            ->assertSee('Diubah')
            ->assertSee('deskripsi diubah')
            ->assertSee('Tim SAKIP Uji');
    }

    /* ---------- Periode mulai berlaku ---------- */

    public function test_kolom_baru_tw_tiga_tidak_tampil_maupun_wajib_sebelum_tw_tiga(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $iku = $this->iku();
        $this->periode(2026, 6);
        $this->periode(2026, 7);
        $bagian = BagianKustom::create([
            'nama' => 'Risiko', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom'],
            'kolom' => [
                ['kunci' => 'level', 'label' => 'Level', 'tipe' => 'teks'],
                ['kunci' => 'dampak', 'label' => 'Dampak Baru', 'tipe' => 'teks', 'wajib' => true, 'mulai_tahun' => 2026, 'mulai_triwulan' => 3],
            ],
        ]);

        // Juni (bulan terakhir TW II): kolom belum berlaku — tidak tampil, tidak wajib.
        Livewire::test(IsianBagianKustom::class)
            ->set('tahun', 2026)->set('bulan', 6)->call('muat')
            ->assertDontSee('Dampak Baru')
            ->set("baris.{$bagian->id}.0.iku_id", (string) $iku->id)
            ->set("baris.{$bagian->id}.0.teks", 'Poin Juni')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        // Juli (bulan pertama TW III): kolom berlaku dan wajib.
        Livewire::test(IsianBagianKustom::class)
            ->set('tahun', 2026)->set('bulan', 7)->call('muat')
            ->assertSee('Dampak Baru')
            ->set("baris.{$bagian->id}.0.iku_id", (string) $iku->id)
            ->set("baris.{$bagian->id}.0.teks", 'Poin Juli')
            ->call('simpan', $bagian->id)
            ->assertHasErrors(["baris.{$bagian->id}.0.kolom.dampak"]);

        // Tahun berikutnya TW I tetap berlaku.
        $this->assertCount(2, $bagian->kolomBerlaku(2027, 1));
        $this->assertCount(1, $bagian->kolomBerlaku(2026, 2));
        $this->assertCount(1, $bagian->kolomBerlaku(2025, 4));
    }

    public function test_isian_kegiatan_kolom_wajib_mengikuti_periode_mulai_berlaku(): void
    {
        $this->actingAsPeran('Ketua Tim');
        $iku = MasterIku::create(['kode' => 'UJI-111', 'indikator' => 'Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $bagian = BagianKustom::create([
            'nama' => 'Manajemen Risiko', 'aktif' => true, 'bukti_wajib' => false,
            'kolom' => [['kunci' => 'dampak', 'label' => 'Dampak', 'tipe' => 'teks', 'wajib' => true, 'mulai_tahun' => 2026, 'mulai_triwulan' => 3]],
        ]);

        $ajukan = fn (int $bulan) => Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', $bulan)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Risiko keterlambatan data')
            ->call('ajukanIsian');

        $ajukan(4)->assertHasNoErrors(["bagianKustomBlocks.{$bagian->id}.0.kolom.dampak"]);
        $ajukan(7)->assertHasErrors(["bagianKustomBlocks.{$bagian->id}.0.kolom.dampak"]);
    }

    /* ---------- Format angka: input, simpan, tampil ---------- */

    public function test_kolom_berformat_menerima_input_sesuai_pemisah_dan_menyimpan_nilai_asli(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $iku = $this->iku();
        $this->periode(2026, 4);
        $bagian = BagianKustom::create([
            'nama' => 'Kinerja Layanan', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom'],
            'kolom' => [$this->kolomPersen()],
        ]);

        $komponen = Livewire::test(IsianBagianKustom::class)
            ->set('tahun', 2026)->set('bulan', 4)->call('muat')
            ->assertSee('pakai koma untuk desimal')
            ->set("baris.{$bagian->id}.0.iku_id", (string) $iku->id)
            ->set("baris.{$bagian->id}.0.teks", 'Layanan A')
            ->set("baris.{$bagian->id}.0.kolom.tingkat", '12.5')
            ->call('simpan', $bagian->id)
            ->assertHasErrors(["baris.{$bagian->id}.0.kolom.tingkat"]);

        $komponen->set("baris.{$bagian->id}.0.kolom.tingkat", '1.234,567')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $poin = BagianKustomPoin::firstOrFail();
        // Disimpan utuh (tidak dibulatkan ke 2 desimal), titik desimal, tanpa ribuan.
        $this->assertSame(['tingkat' => '1234.567'], $poin->nilai_kolom);
        // Dimuat ulang ke formulir dengan pemisah yang diatur, tanpa pembulatan.
        $this->assertSame('1234,567', $komponen->get("baris.{$bagian->id}.0.kolom.tingkat"));
        // Tampilan mengikuti format.
        $this->assertSame(['tingkat' => '1.234,57 persen'], $bagian->nilaiKolomTampil($poin->nilai_kolom));

        // Mengubah jumlah desimal tampilan tidak mengubah nilai tersimpan.
        $this->actingAsPeran('Tim SAKIP');
        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.kolom.0.desimal", '1')
            ->set("form.{$bagian->id}.kolom.0.pemisah", 'titik')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $this->assertSame(['tingkat' => '1234.567'], $poin->fresh()->nilai_kolom);
        $this->assertSame(['tingkat' => '1,234.6 persen'], $bagian->fresh()->nilaiKolomTampil($poin->nilai_kolom));
    }

    public function test_kolom_angka_tanpa_format_tetap_berperilaku_lama(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $iku = $this->iku();
        $this->periode(2026, 4);
        $bagian = BagianKustom::create([
            'nama' => 'Lama', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom'],
            'kolom' => [['kunci' => 'skor', 'label' => 'Skor', 'tipe' => 'angka']],
        ]);

        Livewire::test(IsianBagianKustom::class)
            ->set('tahun', 2026)->set('bulan', 4)->call('muat')
            ->set("baris.{$bagian->id}.0.iku_id", (string) $iku->id)
            ->set("baris.{$bagian->id}.0.teks", 'A')
            ->set("baris.{$bagian->id}.0.kolom.skor", 'dua')
            ->call('simpan', $bagian->id)
            ->assertHasErrors(["baris.{$bagian->id}.0.kolom.skor"])
            ->set("baris.{$bagian->id}.0.kolom.skor", '12,5')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $poin = BagianKustomPoin::firstOrFail();
        $this->assertSame(['skor' => '12,5'], $poin->nilai_kolom);
        $this->assertSame(['skor' => '12,5'], $bagian->nilaiKolomTampil($poin->nilai_kolom));
    }

    /* ---------- Keamanan ---------- */

    public function test_selain_tim_sakip_tidak_bisa_mengelola_maupun_menyalin(): void
    {
        $sumber = BagianKustom::create(['nama' => 'Sumber', 'aktif' => true]);

        foreach (['Ketua Tim', 'Kepala'] as $peran) {
            $this->actingAsPeran($peran);

            Livewire::test(BagianKustomManager::class)->assertForbidden();
        }

        $this->assertSame(1, BagianKustom::count());
        $this->assertSame(0, RiwayatKonfigurasiBagianKustom::count());
        $this->assertNotNull($sumber->fresh());
    }

    public function test_nilai_kolom_nonaktif_tidak_bisa_diubah_lewat_request(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $iku = $this->iku();
        $periode = $this->periode(2026, 4);
        $bagian = BagianKustom::create([
            'nama' => 'Risiko', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom'],
            'kolom' => [['kunci' => 'level', 'label' => 'Level', 'tipe' => 'teks', 'aktif' => false]],
        ]);
        $poin = BagianKustomPoin::create([
            'bagian_kustom_id' => $bagian->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'menu' => 'isian_bagian_kustom', 'peran_pengisi' => 'tim_sakip',
            'teks' => 'A', 'nilai_kolom' => ['level' => 'Asli'], 'status_verifikasi' => 'terverifikasi',
        ]);

        // Payload dimanipulasi untuk menimpa kolom nonaktif → diabaikan.
        Livewire::test(IsianBagianKustom::class)
            ->set('tahun', 2026)->set('bulan', 4)->call('muat')
            ->set("baris.{$bagian->id}.0.kolom.level", 'Dipalsukan')
            ->set("baris.{$bagian->id}.0.kolom.kolom_liar", 'x')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $this->assertSame(['level' => 'Asli'], $poin->fresh()->nilai_kolom);
    }

    /* ---------- Notula ---------- */

    public function test_notula_memakai_format_angka_dan_tetap_berfungsi_setelah_label_diganti(): void
    {
        $this->fakeKonversiBagian1KeXmlMentah();
        $this->actingAsPeran('Tim SAKIP');

        $iku = $this->iku('UJI-120');
        $periode = $this->periode(2026, 4);
        $bagian = BagianKustom::create([
            'nama' => 'Kinerja Layanan', 'aktif' => true, 'masuk_notula' => true,
            'format_notula' => '{kode_iku}: {teks} ({tingkat}; {catatan})',
            'kolom' => [$this->kolomPersen(), ['kunci' => 'catatan', 'label' => 'Catatan', 'tipe' => 'teks']],
        ]);
        BagianKustomPoin::create([
            'bagian_kustom_id' => $bagian->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'teks' => 'Layanan A', 'nilai_kolom' => ['tingkat' => '1234.5', 'catatan' => 'stabil'],
        ]);

        // Label diganti, kolom catatan dinonaktifkan: data lama tetap tercetak.
        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.kolom.0.label", 'Persentase Selesai')
            ->set("form.{$bagian->id}.kolom.1.aktif", false)
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $notula = app(NotulaService::class)->untukTriwulan(2026, 2);
        $html = app(NotulaService::class)->susunBagianSatu($notula);

        $this->assertStringContainsString('UJI-120: Layanan A (1.234,50 persen; stabil)', $html);
    }

    public function test_pratinjau_memakai_format_kolom(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $bagian = BagianKustom::create([
            'nama' => 'Kinerja', 'aktif' => true, 'masuk_notula' => true,
            'format_notula' => '{teks} ({tingkat})', 'kolom' => [$this->kolomPersen()],
        ]);

        Livewire::test(BagianKustomManager::class)
            ->call('buka', $bagian->id)
            ->call('setTab', 'pratinjau')
            ->assertSee('(1.234,50 persen)', false);
    }
}
