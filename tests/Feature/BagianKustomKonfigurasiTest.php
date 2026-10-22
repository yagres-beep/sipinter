<?php

namespace Tests\Feature;

use App\Livewire\BagianKustomManager;
use App\Livewire\IsianBagianKustom;
use App\Livewire\PengisianKegiatan;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Services\NotulaService;
use App\Support\TemplateBagianKustom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class BagianKustomKonfigurasiTest extends TestCase
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

    /** @return array<int, array<string, mixed>> */
    protected function kolomRisiko(): array
    {
        return [
            ['kunci' => 'level_risiko', 'label' => 'Level Risiko', 'tipe' => 'pilihan', 'opsi' => ['Rendah', 'Sedang', 'Tinggi'], 'wajib' => true],
            ['kunci' => 'skor', 'label' => 'Skor', 'tipe' => 'angka', 'opsi' => [], 'wajib' => false],
        ];
    }

    public function test_render_mengganti_variabel_dan_membiarkan_yang_tidak_dikenal(): void
    {
        $hasil = TemplateBagianKustom::render('{kode_iku}: {teks} ({level_risiko}) {tidak_ada}', [
            'kode_iku' => '1131', 'teks' => 'Keterlambatan data', 'level_risiko' => 'Tinggi',
        ]);

        $this->assertSame('1131: Keterlambatan data (Tinggi) {tidak_ada}', $hasil);
    }

    public function test_variabel_kosong_tidak_menyisakan_tanda_kurung_kosong(): void
    {
        $hasil = TemplateBagianKustom::render('{kode_iku}: {teks} ({level_risiko})', ['kode_iku' => '1131', 'teks' => 'Uji', 'level_risiko' => '']);

        $this->assertSame('1131: Uji', $hasil);
    }

    public function test_kunci_kolom_unik_dan_tidak_bentrok_dengan_variabel_bawaan(): void
    {
        $this->assertSame('teks_2', TemplateBagianKustom::kunciBaru('Teks', []));
        $this->assertSame('level_risiko_2', TemplateBagianKustom::kunciBaru('Level Risiko', ['level_risiko']));
        $this->assertSame('mitigasi', TemplateBagianKustom::kunciBaru('Mitigasi', []));
    }

    public function test_semua_tab_pengaturan_bisa_dirender_dan_pratinjau_mengikuti_form(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $bagian = BagianKustom::create(['nama' => 'Manajemen Risiko', 'aktif' => true, 'kolom' => $this->kolomRisiko()]);

        Livewire::test(BagianKustomManager::class)
            ->call('buka', $bagian->id)
            ->call('setTab', 'isian')
            ->assertSee('Level Risiko')
            ->call('setTab', 'notula')
            ->assertSee('{level_risiko}')
            ->set("form.{$bagian->id}.format_notula", '{kode_iku}: {teks} ({skor})')
            ->call('setTab', 'pratinjau')
            ->assertSee('Manajemen Risiko')
            ->assertSee('Pilih: Rendah / Sedang / Tinggi', false)
            ->call('setTab', 'dasar')
            ->assertOk();
    }

    public function test_halaman_isian_bagian_kustom_bisa_dibuka_tim_sakip(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        BagianKustom::create(['nama' => 'Risiko Strategis', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom']]);

        $this->get(route('bagian-kustom.index'))->assertOk()->assertSee('Risiko Strategis');
    }

    public function test_halaman_isian_bagian_kustom_punya_navigasi_bagian_lengket(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $risiko = BagianKustom::create(['nama' => 'Risiko Strategis', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom']]);
        $inovasi = BagianKustom::create(['nama' => 'Inovasi Layanan', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom']]);

        $html = $this->get(route('bagian-kustom.index'))->assertOk()->assertSee('class="bilah-lengket"', false)->getContent();

        preg_match_all('/data-bagian="([^"]+)"/', $html, $cocok);
        $this->assertEqualsCanonicalizing(['bagian-kustom-'.$risiko->id, 'bagian-kustom-'.$inovasi->id], $cocok[1]);
        foreach ($cocok[1] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
    }

    public function test_bagian_kustom_di_menu_lain_tidak_memasang_bilah_lengket(): void
    {
        $this->actingAsPeran('Kepala');
        BagianKustom::create(['nama' => 'Catatan Pimpinan', 'aktif' => true, 'peran' => ['kepala'], 'penempatan' => ['persetujuan']]);

        Livewire::test(IsianBagianKustom::class, ['menu' => 'persetujuan'])
            ->assertSee('Catatan Pimpinan')
            ->assertDontSee('bilah-lengket')
            ->assertDontSee('data-bagian=', false);
    }

    public function test_halaman_isian_bagian_kustom_ditolak_untuk_ketua_tim(): void
    {
        $this->actingAsPeran('Ketua Tim');

        $this->get(route('bagian-kustom.index'))->assertForbidden();
    }

    public function test_menu_yang_tidak_bisa_dibuka_peran_terpilih_ditolak(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $bagian = BagianKustom::create(['nama' => 'Campuran', 'aktif' => true]);

        // Menu Isian Kegiatan hanya untuk Ketua Tim, jadi tidak boleh dipilih untuk Tim SAKIP saja.
        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.peran", ['tim_sakip'])
            ->set("form.{$bagian->id}.penempatan", ['pengisian'])
            ->call('simpan', $bagian->id)
            ->assertHasErrors(["form.{$bagian->id}.penempatan"]);
    }

    public function test_bagian_bisa_dipasang_di_menu_selain_isian_kegiatan_dan_diisi_perannya(): void
    {
        $this->actingAsPeran('Kepala');
        $iku = MasterIku::create(['kode' => 'UJI-014', 'indikator' => 'Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
        $bagian = BagianKustom::create([
            'nama' => 'Catatan Kepala', 'aktif' => true, 'peran' => ['kepala', 'ketua_tim'], 'penempatan' => ['persetujuan', 'rtl_evaluasi'],
        ]);

        // Kepala melihat bagian di menu Persetujuan, tetapi tidak di menu RTL & Evaluasi (khusus Ketua Tim).
        Livewire::test(IsianBagianKustom::class, ['menu' => 'persetujuan'])
            ->set('tahun', 2026)
            ->set('bulan', 4)
            ->assertSee('Catatan Kepala')
            ->set("baris.{$bagian->id}.0.iku_id", (string) $iku->id)
            ->set("baris.{$bagian->id}.0.teks", 'Catatan persetujuan')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('bagian_kustom_poin', ['bagian_kustom_id' => $bagian->id, 'teks' => 'Catatan persetujuan']);

        Livewire::test(IsianBagianKustom::class, ['menu' => 'rtl_evaluasi'])
            ->assertDontSee('Catatan Kepala');
    }

    public function test_bagian_kustom_tampil_otomatis_di_bawah_halaman_menu_yang_dipilih(): void
    {
        $this->actingAsPeran('Kepala');
        BagianKustom::create(['nama' => 'Catatan Pimpinan', 'aktif' => true, 'peran' => ['kepala'], 'penempatan' => ['persetujuan']]);
        BagianKustom::create(['nama' => 'Hanya Lakin', 'aktif' => true, 'peran' => ['kepala'], 'penempatan' => ['lakin']]);

        $this->get(route('persetujuan.index'))->assertOk()->assertSee('Catatan Pimpinan')->assertDontSee('Hanya Lakin');
    }

    public function test_variabel_tidak_dikenal_terdeteksi(): void
    {
        $this->assertSame(['{salah}'], TemplateBagianKustom::variabelTidakDikenal('{kode_iku} {salah}', ['level']));
    }

    public function test_tim_sakip_bisa_menyimpan_kolom_format_dan_judul_notula(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $bagian = BagianKustom::create(['nama' => 'Manajemen Risiko', 'aktif' => true, 'kolom' => []]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.judul_notula", 'Risiko Utama')
            ->set("form.{$bagian->id}.format_notula", '{kode_iku}: {teks} ({level_risiko})')
            ->set("form.{$bagian->id}.masuk_notula", true)
            ->set("form.{$bagian->id}.kolom", [
                ['kunci' => '', 'label' => 'Level Risiko', 'tipe' => 'pilihan', 'opsi_teks' => 'Rendah, Sedang, Tinggi', 'wajib' => true],
            ])
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $bagian->refresh();

        $this->assertSame('Risiko Utama', $bagian->judulNotulaEfektif());
        $this->assertSame('level_risiko', $bagian->kolomList()[0]['kunci']);
        $this->assertSame(['Rendah', 'Sedang', 'Tinggi'], $bagian->kolomList()[0]['opsi']);
        $this->assertSame('{kode_iku}: {teks} ({level_risiko})', $bagian->formatNotulaEfektif());
    }

    public function test_format_dengan_variabel_tidak_dikenal_ditolak(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $bagian = BagianKustom::create(['nama' => 'Manajemen Risiko', 'aktif' => true, 'kolom' => []]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.format_notula", '{kode_iku}: {teks} {salah_ketik}')
            ->call('simpan', $bagian->id)
            ->assertHasErrors(["form.{$bagian->id}.format_notula"]);
    }

    public function test_kolom_pilihan_butuh_minimal_dua_opsi(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $bagian = BagianKustom::create(['nama' => 'Manajemen Risiko', 'aktif' => true, 'kolom' => []]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.kolom", [
                ['kunci' => '', 'label' => 'Level', 'tipe' => 'pilihan', 'opsi_teks' => 'Satu saja', 'wajib' => false],
            ])
            ->call('simpan', $bagian->id)
            ->assertHasErrors(["form.{$bagian->id}.kolom.0.opsi_teks"]);
    }

    public function test_kolom_yang_sudah_berisi_data_tidak_bisa_dihapus(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $iku = MasterIku::create(['kode' => 'UJI-010', 'indikator' => 'Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
        $bagian = BagianKustom::create(['nama' => 'Manajemen Risiko', 'aktif' => true, 'kolom' => $this->kolomRisiko()]);
        BagianKustomPoin::create([
            'bagian_kustom_id' => $bagian->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'teks' => 'Risiko A', 'nilai_kolom' => ['level_risiko' => 'Tinggi'],
        ]);

        Livewire::test(BagianKustomManager::class)
            ->set("form.{$bagian->id}.kolom", [
                ['kunci' => 'skor', 'label' => 'Skor', 'tipe' => 'angka', 'opsi_teks' => '', 'wajib' => false],
            ])
            ->call('simpan', $bagian->id)
            ->assertHasErrors(["form.{$bagian->id}.kolom"]);

        $this->assertCount(2, $bagian->fresh()->kolomList());
    }

    public function test_isian_ketua_tim_menyimpan_nilai_kolom_dan_menolak_kolom_wajib_kosong(): void
    {
        $this->actingAsPeran('Ketua Tim');
        $iku = MasterIku::create(['kode' => 'UJI-011', 'indikator' => 'Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $bagian = BagianKustom::create([
            'nama' => 'Manajemen Risiko', 'aktif' => true, 'bukti_wajib' => false,
            'label_uraian' => 'Nama Risiko', 'kolom' => $this->kolomRisiko(),
        ]);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 4)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Risiko keterlambatan data')
            ->call('ajukanIsian')
            ->assertHasErrors(["bagianKustomBlocks.{$bagian->id}.0.kolom.level_risiko"]);
    }

    public function test_bagian_tim_sakip_tidak_muncul_di_isian_kegiatan_ketua_tim(): void
    {
        $this->actingAsPeran('Ketua Tim');
        BagianKustom::create(['nama' => 'Khusus SAKIP', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom']]);

        Livewire::test(PengisianKegiatan::class)
            ->assertSet('bagianKustomBlocks', []);
    }

    public function test_tim_sakip_mengisi_poin_dengan_kolom_lalu_baris_dihapus(): void
    {
        $this->actingAsPeran('Tim SAKIP');
        $iku = MasterIku::create(['kode' => 'UJI-012', 'indikator' => 'Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
        $bagian = BagianKustom::create([
            'nama' => 'Risiko Strategis', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom'],
            'kolom' => $this->kolomRisiko(),
        ]);

        $component = Livewire::test(IsianBagianKustom::class)
            ->set('tahun', 2026)
            ->set('bulan', 4)
            ->set("baris.{$bagian->id}.0.iku_id", (string) $iku->id)
            ->set("baris.{$bagian->id}.0.teks", 'Ancaman anggaran')
            ->set("baris.{$bagian->id}.0.kolom.level_risiko", 'Sedang')
            ->call('tambahBaris', $bagian->id)
            ->set("baris.{$bagian->id}.1.iku_id", (string) $iku->id)
            ->set("baris.{$bagian->id}.1.teks", 'Sementara')
            ->set("baris.{$bagian->id}.1.kolom.level_risiko", 'Rendah')
            ->call('simpan', $bagian->id)
            ->assertHasNoErrors();

        $this->assertDatabaseCount('bagian_kustom_poin', 2);
        $this->assertDatabaseHas('bagian_kustom_poin', ['teks' => 'Ancaman anggaran', 'status_verifikasi' => 'terverifikasi']);

        // Hapus baris kedua lalu simpan: poin kedua ikut terhapus dari database.
        $component->call('hapusBaris', $bagian->id, 1)->call('simpan', $bagian->id)->assertHasNoErrors();

        $this->assertDatabaseCount('bagian_kustom_poin', 1);
    }

    public function test_notula_memakai_format_judul_dan_melewati_bagian_yang_tidak_masuk_notula(): void
    {
        $this->fakeKonversiBagian1KeXmlMentah();

        $iku = MasterIku::create(['kode' => 'UJI-013', 'indikator' => 'Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);

        $tampil = BagianKustom::create([
            'nama' => 'Manajemen Risiko', 'aktif' => true, 'masuk_notula' => true,
            'judul_notula' => 'Daftar Risiko Utama', 'format_notula' => '{kode_iku} | {teks} | {level_risiko}',
            'kolom' => $this->kolomRisiko(),
        ]);
        BagianKustomPoin::create([
            'bagian_kustom_id' => $tampil->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'teks' => 'Keterlambatan data', 'nilai_kolom' => ['level_risiko' => 'Tinggi'],
        ]);

        $sembunyi = BagianKustom::create(['nama' => 'Rahasia Internal', 'aktif' => true, 'masuk_notula' => false]);
        BagianKustomPoin::create([
            'bagian_kustom_id' => $sembunyi->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id, 'teks' => 'Tidak untuk notula',
        ]);

        $notula = app(NotulaService::class)->untukTriwulan(2026, 2);
        $html = app(NotulaService::class)->susunBagianSatu($notula);

        $this->assertStringContainsString('Daftar Risiko Utama', $html);
        $this->assertStringContainsString('UJI-013 | Keterlambatan data | Tinggi', $html);
        $this->assertStringNotContainsString('Rahasia Internal', $html);
        $this->assertStringNotContainsString('Tidak untuk notula', $html);
    }
}
