<?php

namespace Tests\Feature;

use App\Livewire\BagianKustomManager;
use App\Livewire\DaftarBagianSipinter;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\FolderConfig;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\RiwayatKonfigurasiBagianKustom;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Perbaikan "Mulai dari: Salin pengaturan" dan tombol Duplikasi (28 Okt 2026).
 *
 * Akar masalah: (1) memilih "Pengaturan kosong" setelah memilih sumber tidak
 * membersihkan peran/menu/frekuensi/deskripsi yang sudah dimuat; (2) Duplikasi tidak
 * membuat bagian apa pun, hanya mengisi form Tambah di atas halaman; (3) daftar
 * "Seluruh Bagian" tidak diperbarui setelah bagian baru dibuat. Kedua jalur kini
 * memakai satu mekanisme penyalinan (konfigurasiSalinan) di server.
 */
class BagianKustomSalinDuplikasiTest extends TestCase
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

    /** Persis seperti baris Manajemen Resiko di produksi (cek baca-saja 28 Okt). */
    protected function manajemenResikoProduksi(): BagianKustom
    {
        return BagianKustom::create([
            'kode' => 'manajemen_resiko', 'nama' => 'Manajemen Resiko', 'aktif' => true, 'urutan' => 1,
            'peran' => ['ketua_tim', 'tim_sakip'], 'penempatan' => ['pengisian', 'verifikasi'],
            'frekuensi_wajib' => 'akhir_triwulan', 'bukti_wajib' => true, 'masuk_notula' => true,
            'kolom' => [], 'subbagian' => [],
        ]);
    }

    /** Sumber dengan konfigurasi lengkap + data operasional. */
    protected function sumberLengkap(): BagianKustom
    {
        $sumber = BagianKustom::create([
            'nama' => 'Risiko Lengkap', 'deskripsi' => 'Petunjuk sumber', 'aktif' => true, 'urutan' => 1,
            // "isian_bagian_kustom" supaya Kepala (pembaca) bisa membuka salah satu menu.
            'peran' => ['ketua_tim', 'tim_sakip'], 'penempatan' => ['pengisian', 'verifikasi', 'isian_bagian_kustom'],
            'peran_pembaca' => ['kepala'],
            'hak_aksi' => ['ketua_tim' => ['buat', 'ubah'], 'tim_sakip' => ['buat', 'ubah', 'hapus', 'unggah_bukti', 'hapus_bukti']],
            'frekuensi_wajib' => 'setiap_bulan', 'bukti_wajib' => true, 'masuk_notula' => true,
            'aturan_bukti' => ['mode' => 'wajib', 'min' => 1, 'maks' => 3, 'format' => ['pdf'], 'ukuran_maks_mb' => 5, 'petunjuk' => 'Unggah notulen'],
            'tampilan' => ['isian' => 'tabel', 'baca' => 'tabel'],
            'label_uraian' => 'Nama Risiko', 'judul_notula' => 'Daftar Risiko', 'format_notula' => '{kode_iku}: {teks} ({level})',
            'subbagian' => [
                ['kunci' => 'sub_1', 'nama' => 'Identifikasi', 'deskripsi' => 'Kenali risiko', 'aktif' => true],
                ['kunci' => 'sub_2', 'nama' => 'Arsip', 'aktif' => false],
            ],
            'kolom' => [
                ['kunci' => 'level', 'label' => 'Level', 'tipe' => 'pilihan', 'opsi' => ['Rendah', 'Tinggi'], 'wajib' => true, 'subbagian' => 'sub_1', 'petunjuk' => 'Pilih level'],
                ['kunci' => 'skor', 'label' => 'Skor', 'tipe' => 'angka', 'desimal' => 1, 'min' => '0', 'maks' => '25'],
                ['kunci' => 'lama', 'label' => 'Lama', 'tipe' => 'teks', 'subbagian' => 'sub_2'],
            ],
        ]);

        $iku = MasterIku::create(['kode' => 'UJI-1', 'indikator' => 'Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $periode = Periode::firstOrCreate(['tahun' => 2026, 'bulan' => 4], ['triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
        $poin = BagianKustomPoin::create([
            'bagian_kustom_id' => $sumber->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'teks' => 'Risiko A', 'nilai_kolom' => ['level' => 'Tinggi'], 'status_verifikasi' => 'terverifikasi',
        ]);
        Berkas::create([
            'ref_id' => $poin->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom',
            'nama_file' => 'bukti.pdf', 'path' => 'bukti/bukti.pdf', 'drive_file_id' => 'drive-sumber-1', 'status_verifikasi' => 'terverifikasi',
        ]);

        return $sumber->fresh();
    }

    /** Konfigurasi yang harus identik antara sumber dan salinan. */
    protected function assertKonfigurasiTersalin(BagianKustom $sumber, BagianKustom $salinan): void
    {
        $this->assertNotSame($sumber->id, $salinan->id);
        $this->assertNotSame($sumber->kode, $salinan->kode);
        $this->assertSame($salinan->nama, $salinan->nama_folder, 'folder Drive milik salinan sendiri');
        $this->assertSame(['ketua_tim', 'tim_sakip'], $salinan->peran);
        $this->assertSame(['pengisian', 'verifikasi', 'isian_bagian_kustom'], $salinan->penempatan);
        $this->assertSame(['kepala'], $salinan->peran_pembaca);
        $this->assertSame($sumber->matriksAksi(), $salinan->matriksAksi());
        $this->assertSame($sumber->aturan_bukti, $salinan->aturan_bukti);
        $this->assertTrue($salinan->bukti_wajib);
        $this->assertSame(['isian' => 'tabel', 'baca' => 'tabel'], $salinan->tampilan);
        $this->assertSame('Nama Risiko', $salinan->label_uraian);
        $this->assertSame('{kode_iku}: {teks} ({level})', $salinan->format_notula);
        $this->assertNull($salinan->judul_notula, 'judul notula = nama bagian baru');
        $this->assertTrue($salinan->masuk_notula);
        $this->assertSame(['sub_1'], array_column($salinan->subbagianList(), 'kunci'));
        $this->assertSame('Kenali risiko', $salinan->subbagianList()[0]['deskripsi']);
        $this->assertSame(['level', 'skor'], array_column($salinan->kolomList(), 'kunci'));
        $level = $salinan->kolomList()[0];
        $this->assertSame(['Rendah', 'Tinggi'], $level['opsi']);
        $this->assertTrue($level['wajib']);
        $this->assertSame('sub_1', $level['subbagian']);
        $this->assertSame('Pilih level', $level['petunjuk']);
        $this->assertSame(1, $salinan->kolomList()[1]['desimal']);

        // Data operasional tidak ikut.
        $this->assertSame(0, BagianKustomPoin::where('bagian_kustom_id', $salinan->id)->count());
        $this->assertSame(1, BagianKustomPoin::where('bagian_kustom_id', $sumber->id)->count());
        $this->assertSame(1, Berkas::count());
        $this->assertSame(['salin'], RiwayatKonfigurasiBagianKustom::where('bagian_kustom_id', $salinan->id)->pluck('aksi')->all());
    }

    /* ---------- Salin pengaturan ---------- */

    public function test_salin_pengaturan_dengan_nilai_teks_dari_browser_menyalin_semua_konfigurasi(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $sumber = $this->sumberLengkap();
        $sebelum = $sumber->potretDefinisi();

        Livewire::test(BagianKustomManager::class)
            ->set('namaBaru', 'Risiko Baru')
            ->set('sumberSalinId', (string) $sumber->id) // <select> mengirim teks
            ->assertSet('sumberSalinId', $sumber->id)
            ->assertSet('peranBaru', ['ketua_tim', 'tim_sakip'])
            ->assertSet('penempatanBaru', ['pengisian', 'verifikasi', 'isian_bagian_kustom'])
            ->assertSet('frekuensiWajibBaru', 'setiap_bulan')
            ->assertSet('deskripsiBaru', 'Petunjuk sumber')
            ->assertSee('Yang akan disalin dari')
            ->assertSee('Hak aksi:')
            ->call('tambah')
            ->assertHasNoErrors()
            ->assertDispatched('bagian-kustom-berubah');

        $salinan = BagianKustom::where('nama', 'Risiko Baru')->firstOrFail();
        $this->assertTrue($salinan->aktif);
        $this->assertSame('Petunjuk sumber', $salinan->deskripsi);
        $this->assertKonfigurasiTersalin($sumber, $salinan);
        $this->assertEquals($sebelum, $sumber->fresh()->potretDefinisi(), 'sumber tidak berubah');
    }

    public function test_kembali_ke_pengaturan_kosong_membersihkan_konfigurasi_sumber(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $sumber = $this->sumberLengkap();

        Livewire::test(BagianKustomManager::class)
            ->set('namaBaru', 'Bagian Kosong')
            ->set('sumberSalinId', (string) $sumber->id)
            ->assertSet('deskripsiBaru', 'Petunjuk sumber')
            ->set('sumberSalinId', '')
            ->assertSet('sumberSalinId', null)
            ->assertSet('peranBaru', ['ketua_tim'])
            ->assertSet('penempatanBaru', ['pengisian'])
            ->assertSet('frekuensiWajibBaru', 'opsional')
            ->assertSet('deskripsiBaru', '')
            ->assertDontSee('Yang akan disalin dari')
            ->call('tambah')
            ->assertHasNoErrors();

        $baru = BagianKustom::where('nama', 'Bagian Kosong')->firstOrFail();
        $this->assertSame([], $baru->kolomList());
        $this->assertSame([], $baru->subbagianList());
        $this->assertNull($baru->hak_aksi);
        $this->assertNull($baru->peran_pembaca);
        $this->assertNull($baru->aturan_bukti);
        $this->assertNull($baru->tampilan);
        $this->assertNull($baru->label_uraian);
        $this->assertNull($baru->format_notula);
        $this->assertNull($baru->deskripsi);
        $this->assertSame(['buat'], RiwayatKonfigurasiBagianKustom::where('bagian_kustom_id', $baru->id)->pluck('aksi')->all());
    }

    public function test_ganti_sumber_mengganti_deskripsi_otomatis_tetapi_ketikan_pengguna_dipertahankan(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $a = BagianKustom::create(['nama' => 'A', 'deskripsi' => 'Deskripsi A', 'aktif' => true, 'peran' => ['ketua_tim'], 'penempatan' => ['pengisian']]);
        $b = BagianKustom::create(['nama' => 'B', 'deskripsi' => 'Deskripsi B', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom']]);

        $lw = Livewire::test(BagianKustomManager::class)
            ->set('sumberSalinId', (string) $a->id)
            ->assertSet('deskripsiBaru', 'Deskripsi A')
            ->set('sumberSalinId', (string) $b->id)
            ->assertSet('deskripsiBaru', 'Deskripsi B')
            ->assertSet('peranBaru', ['tim_sakip']);

        $lw->set('deskripsiBaru', 'Ketikan sendiri')
            ->set('sumberSalinId', (string) $a->id)
            ->assertSet('deskripsiBaru', 'Ketikan sendiri')
            ->set('sumberSalinId', '')
            ->assertSet('deskripsiBaru', 'Ketikan sendiri');
    }

    public function test_salin_manajemen_resiko_produksi_yang_kosong_berhasil(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $mr = $this->manajemenResikoProduksi();

        Livewire::test(BagianKustomManager::class)
            ->set('namaBaru', 'Uji Salin MR')
            ->set('sumberSalinId', (string) $mr->id)
            ->assertSee('belum punya kolom isian maupun subbagian')
            ->call('tambah')
            ->assertHasNoErrors()
            ->assertSee('dibuat dari salinan pengaturan');

        $baru = BagianKustom::where('nama', 'Uji Salin MR')->firstOrFail();
        $this->assertSame(['ketua_tim', 'tim_sakip'], $baru->peran);
        $this->assertSame(['pengisian', 'verifikasi'], $baru->penempatan);
        $this->assertSame('akhir_triwulan', $baru->frekuensi_wajib);
        $this->assertTrue($baru->bukti_wajib);
        $this->assertSame('uji_salin_mr', $baru->kode);
        $this->assertSame('manajemen_resiko', $mr->fresh()->kode);
    }

    public function test_format_notula_yang_merujuk_kolom_tak_tersalin_diganti_standar_dan_salinan_bisa_disimpan(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $sumber = BagianKustom::create([
            'nama' => 'Sumber', 'aktif' => true, 'peran' => ['ketua_tim'], 'penempatan' => ['pengisian'],
            'format_notula' => '{teks} ({lama})',
            'kolom' => [
                ['kunci' => 'lama', 'label' => 'Lama', 'tipe' => 'teks', 'aktif' => false],
                // Menunjuk subbagian yang tidak ada → menjadi kolom langsung di salinan.
                ['kunci' => 'yatim', 'label' => 'Yatim', 'tipe' => 'teks', 'subbagian' => 'tidak_ada'],
            ],
        ]);

        Livewire::test(BagianKustomManager::class)
            ->call('duplikasi', $sumber->id)
            ->assertHasNoErrors();

        $salinan = BagianKustom::where('nama', 'Sumber (salinan)')->firstOrFail();
        $this->assertNull($salinan->format_notula);
        $this->assertSame(['yatim'], array_column($salinan->kolomList(), 'kunci'));
        $this->assertNull($salinan->kolomList()[0]['subbagian']);

        Livewire::test(BagianKustomManager::class)
            ->call('simpan', $salinan->id)
            ->assertHasNoErrors();
    }

    /* ---------- Duplikasi ---------- */

    public function test_duplikasi_langsung_membuat_bagian_nonaktif_dengan_konfigurasi_lengkap(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $sumber = $this->sumberLengkap();
        $sebelum = $sumber->potretDefinisi();
        $kategoriSebelum = count(FolderConfig::current()->pola_json['kategori'] ?? []);

        Livewire::test(BagianKustomManager::class)
            ->call('duplikasi', $sumber->id)
            ->assertHasNoErrors()
            ->assertDispatched('bagian-kustom-berubah')
            ->assertSee('dibuat dari duplikasi')
            ->assertSee('Risiko Lengkap (salinan)');

        $salinan = BagianKustom::where('nama', 'Risiko Lengkap (salinan)')->firstOrFail();
        $this->assertFalse($salinan->aktif);
        $this->assertSame('Petunjuk sumber', $salinan->deskripsi);
        $this->assertSame('setiap_bulan', $salinan->frekuensi_wajib);
        $this->assertKonfigurasiTersalin($sumber, $salinan);
        $this->assertEquals($sebelum, $sumber->fresh()->potretDefinisi());
        $this->assertSame($kategoriSebelum + 1, count(FolderConfig::current()->pola_json['kategori']));
    }

    public function test_duplikasi_berulang_menghasilkan_nama_dan_kode_unik(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $mr = $this->manajemenResikoProduksi();

        $lw = Livewire::test(BagianKustomManager::class);
        $lw->call('duplikasi', $mr->id)->call('duplikasi', $mr->id)->assertHasNoErrors();

        $this->assertSame(
            ['Manajemen Resiko', 'Manajemen Resiko (salinan)', 'Manajemen Resiko (salinan 2)'],
            BagianKustom::orderBy('id')->pluck('nama')->all()
        );
        $this->assertSame(3, BagianKustom::distinct()->count('kode'));
    }

    public function test_hasil_duplikasi_berdiri_sendiri(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $sumber = $this->sumberLengkap();
        $sebelum = $sumber->potretDefinisi();

        Livewire::test(BagianKustomManager::class)->call('duplikasi', $sumber->id);
        $salinan = BagianKustom::where('nama', 'Risiko Lengkap (salinan)')->firstOrFail();

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$salinan->id}.nama", 'Risiko Cabang')
            ->set("form.{$salinan->id}.kolom.0.label", 'Level Baru')
            ->set("form.{$salinan->id}.hak_aksi.ketua_tim.hapus", true)
            ->set("form.{$salinan->id}.aturan_bukti.maks", '9')
            ->set("form.{$salinan->id}.masuk_notula", false)
            ->call('simpan', $salinan->id)
            ->assertHasNoErrors()
            ->call('toggleAktif', $salinan->id, true);

        $salinan->refresh();
        $this->assertSame('Risiko Cabang', $salinan->nama);
        $this->assertTrue($salinan->aktif);
        $this->assertContains('hapus', $salinan->hak_aksi['ketua_tim']);
        $this->assertEquals($sebelum, $sumber->fresh()->potretDefinisi(), 'sumber tidak ikut berubah');

        // Hapus salinan (belum punya isian) tidak menyentuh sumber.
        Livewire::test(BagianKustomManager::class)->call('confirmHapus', $salinan->id)->call('hapus');
        $this->assertNull(BagianKustom::find($salinan->id));
        $this->assertEquals($sebelum, $sumber->fresh()->potretDefinisi());
        $this->assertSame(1, BagianKustomPoin::where('bagian_kustom_id', $sumber->id)->count());

        // Perubahan sumber kemudian tidak menjalar ke salinan lain.
        Livewire::test(BagianKustomManager::class)->call('duplikasi', $sumber->id);
        $salinan2 = BagianKustom::where('nama', 'Risiko Lengkap (salinan)')->firstOrFail();
        Livewire::test(BagianKustomManager::class)
            ->set("form.{$sumber->id}.label_uraian", 'Diubah di sumber')
            ->call('simpan', $sumber->id)
            ->assertHasNoErrors();
        $this->assertSame('Nama Risiko', $salinan2->fresh()->label_uraian);
    }

    public function test_duplikasi_gagal_tidak_meninggalkan_data_parsial_dan_tanpa_pesan_berhasil(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $sumber = $this->sumberLengkap();
        $sebelum = $sumber->potretDefinisi();
        $jumlahRiwayat = RiwayatKonfigurasiBagianKustom::count();
        $polaSebelum = FolderConfig::current()->pola_json;

        // Gagal SETELAH baris bagian & riwayat ditulis (saat menambah kategori folder).
        FolderConfig::saving(function () {
            throw new \RuntimeException('Simulasi kegagalan simpan folder');
        });

        Livewire::test(BagianKustomManager::class)
            ->call('duplikasi', $sumber->id)
            ->assertHasErrors('duplikasi')
            ->assertDontSee('dibuat dari duplikasi')
            ->assertNotDispatched('bagian-kustom-berubah');

        FolderConfig::flushEventListeners();

        $this->assertSame(1, BagianKustom::count());
        $this->assertSame($jumlahRiwayat, RiwayatKonfigurasiBagianKustom::count());
        $this->assertEquals($polaSebelum, FolderConfig::current()->pola_json);
        $this->assertEquals($sebelum, $sumber->fresh()->potretDefinisi());
    }

    public function test_tambah_gagal_tidak_meninggalkan_data_parsial(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $sumber = $this->sumberLengkap();

        FolderConfig::saving(function () {
            throw new \RuntimeException('Simulasi kegagalan');
        });

        Livewire::test(BagianKustomManager::class)
            ->set('namaBaru', 'Tidak Jadi')
            ->set('sumberSalinId', (string) $sumber->id)
            ->call('tambah')
            ->assertHasErrors('tambah')
            ->assertDontSee('dibuat dari salinan pengaturan');

        FolderConfig::flushEventListeners();
        $this->assertDatabaseMissing('bagian_kustom', ['nama' => 'Tidak Jadi']);
    }

    /* ---------- Hak akses di server ---------- */

    public function test_peran_lain_tidak_bisa_menduplikasi_atau_menyalin_lewat_permintaan_langsung(): void
    {
        $sakip = $this->actingAsPeran('Tim SAKIP');
        $mr = $this->manajemenResikoProduksi();

        foreach (['Ketua Tim', 'Kepala'] as $peran) {
            // Komponen dibuka saat masih Tim SAKIP, lalu permintaan berikutnya datang
            // dari peran lain (mis. peran dicabut / permintaan dirakit manual).
            $this->actingAs($sakip);
            $duplikasi = Livewire::test(BagianKustomManager::class);
            $salin = Livewire::test(BagianKustomManager::class);

            $this->actingAsPeran($peran);
            $duplikasi->call('duplikasi', $mr->id)->assertForbidden();
            $salin->set('sumberSalinId', (string) $mr->id)->assertForbidden();

            $this->get(route('master-iku.index'))->assertForbidden();
        }

        $this->assertSame(1, BagianKustom::count());
    }

    /* ---------- Daftar seluruh bagian ikut diperbarui ---------- */

    public function test_daftar_seluruh_bagian_menampilkan_bagian_baru_setelah_event(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $mr = $this->manajemenResikoProduksi();

        $daftar = Livewire::test(DaftarBagianSipinter::class)->assertDontSee('Manajemen Resiko (salinan)');

        Livewire::test(BagianKustomManager::class)->call('duplikasi', $mr->id);

        $daftar->dispatch('bagian-kustom-berubah')->assertSee('Manajemen Resiko (salinan)');
    }
}
