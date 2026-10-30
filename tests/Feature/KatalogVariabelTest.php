<?php

namespace Tests\Feature;

use App\Livewire\BagianKustomManager;
use App\Livewire\TemplateNotula;
use App\Livewire\VariabelKatalogManager;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\FolderConfig;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Models\VariabelKatalog;
use App\Services\NotulaBagian1DocxService;
use App\Services\NotulaService;
use App\Support\KatalogVariabel;
use App\Support\RegistriVariabel;
use App\Support\TemplateBagianKustom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\PenjagaDatabaseUji;
use Tests\TestCase;
use ZipArchive;

/**
 * Katalog Variabel sebagai kamus pusat: registri variabel sistem, definisi bersama,
 * variabel kolom Bagian Kustom, dan integrasinya dengan template notula (.docx) serta
 * format baris notula Bagian Kustom.
 */
class KatalogVariabelTest extends TestCase
{
    use PenjagaDatabaseUji;
    use RefreshDatabase;

    /** Daftar placeholder .docx yang dikenali SEBELUM registri dibuat (dibekukan). */
    private const MACRO_DIKENAL_LAMA = [
        'tahun', 'triwulan_angka', 'triwulan_sebelumnya', 'nama_satker', 'agenda_pembahasan', 'hari_tanggal', 'waktu', 'tempat',
        'pimpinan_rapat', 'capaian_triwulanan_persen', 'capaian_pk_persen', 'lampiran_basis_data',
        'kotaTtd', 'tanggal', 'namaKepala', 'namaNotulis', 'bagian_2', 'bagian_3',
        'iku_blok', '/iku_blok', 'blok_proksi', '/blok_proksi', 'blok_berakhlak', '/blok_berakhlak',
        'blok_ro', '/blok_ro', 'blok_formula', '/blok_formula', 'ro_row', '/ro_row',
        'proksi_uraian', 'proksi_target', 'proksi_realisasi',
        'kode', 'indikator', 'sasaran', 'target_pk', 'target_tw', 'realisasi_tw', 'capaian_tw', 'capaian_pk',
        'analisis_capaian', 'kendala', 'solusi', 'rtl', 'pic_rtl',
        'batas_waktu_rtl', 'formula_capaian', 'dasar_hitung', 'bukti_realisasi', 'bukti_rtl_sebelumnya',
        'rtl_evaluasi', 'bukti_rtl_evaluasi', 'rtl_berikutnya', 'batas_waktu_rtl_berikutnya', 'penjelasan_lainnya', 'ro_uraian', 'ro_vol', 'ro_progres',
    ];

    private const TEMPLATE_BAWAAN = 'template_notula/SIPINTER_Template_Bagian_I_Mesin.docx';

    protected MasterIku $iku;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pastikanDatabaseUji();

        foreach (['Ketua Tim', 'Tim SAKIP', 'Kepala'] as $nama) {
            Role::firstOrCreate(['nama' => $nama]);
        }

        $this->iku = MasterIku::create([
            'kode' => 'KV-1', 'indikator' => 'Persentase uji katalog', 'sasaran' => 'Sasaran uji katalog',
            'tim' => 'Uji', 'penanggung_jawab' => 'A', 'metode_capaian' => MasterIku::METODE_RASIO,
        ]);
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

    protected function periode(int $bulan, int $tahun = 2026): Periode
    {
        return Periode::firstOrCreate(
            ['tahun' => $tahun, 'bulan' => $bulan],
            ['triwulan' => intdiv($bulan - 1, 3) + 1, 'bulan_ke' => ($bulan - 1) % 3 + 1, 'flag_bulan_terlewat' => true],
        );
    }

    protected function bagian(array $extra = []): BagianKustom
    {
        $b = BagianKustom::create($extra + [
            'nama' => 'Register Risiko', 'aktif' => true, 'bukti_wajib' => false, 'masuk_notula' => true,
            'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom'],
            'kolom' => [['kunci' => 'nilai', 'label' => 'Nilai', 'tipe' => 'angka']],
        ]);
        BagianKustom::lupakanCache();

        return $b;
    }

    protected function poin(BagianKustom $b, int $bulan, string $teks, array $nilai = []): BagianKustomPoin
    {
        return BagianKustomPoin::create([
            'bagian_kustom_id' => $b->id, 'iku_id' => $this->iku->id, 'periode_id' => $this->periode($bulan)->id,
            'teks' => $teks, 'nilai_kolom' => $nilai, 'menu' => 'isian_bagian_kustom', 'peran_pengisi' => 'tim_sakip',
            'status_verifikasi' => 'terverifikasi',
        ]);
    }

    /** Template bawaan dengan word/document.xml diubah lewat $ubah; dikembalikan path sementara. */
    protected function templateDiubah(callable $ubah): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'tplkv').'.docx';
        copy(base_path(self::TEMPLATE_BAWAAN), $temp);
        $zip = new ZipArchive;
        $zip->open($temp);
        $zip->addFromString('word/document.xml', $ubah($zip->getFromName('word/document.xml')));
        $zip->close();

        return $temp;
    }

    /* ---------- 1, 2, 4, 15: registri & dokumentasi ---------- */

    public function test_registri_mendokumentasikan_setiap_variabel_sistem_dengan_lengkap(): void
    {
        $semua = RegistriVariabel::semua();
        $this->assertGreaterThan(50, count($semua));

        foreach ($semua as $kode => $v) {
            $this->assertSame($kode, $v['kode']);
            foreach (['nama', 'deskripsi', 'sumber', 'aturan', 'contoh'] as $wajib) {
                $this->assertNotSame('', trim((string) $v[$wajib]), "{$kode}: {$wajib} kosong");
            }
            $this->assertArrayHasKey($v['kategori'], RegistriVariabel::KATEGORI, $kode);
            $this->assertArrayHasKey($v['jenis'], RegistriVariabel::JENIS, $kode);
            $this->assertArrayHasKey($v['tipe'], RegistriVariabel::TIPE, $kode);
            $this->assertArrayHasKey($v['status'], RegistriVariabel::STATUS, $kode);
            $this->assertNotEmpty($v['konteks'], $kode);

            foreach ($v['konteks'] as $konteks) {
                $this->assertArrayHasKey($konteks, RegistriVariabel::KONTEKS, $kode);
            }

            if ($v['lingkup'] !== null) {
                $this->assertArrayHasKey($v['lingkup'], RegistriVariabel::LINGKUP, $kode);
            }

            if ($v['status'] === 'usang') {
                $this->assertNotNull(RegistriVariabel::cari((string) $v['pengganti']), "{$kode}: pengganti harus variabel terdaftar");
            }
        }
    }

    public function test_registri_idempoten_dan_tidak_menulis_database(): void
    {
        $this->assertSame(RegistriVariabel::semua(), RegistriVariabel::semua());

        $this->sebagai('Tim SAKIP');
        Livewire::test(VariabelKatalogManager::class)->assertOk();
        Livewire::test(VariabelKatalogManager::class)->assertOk();

        $this->assertDatabaseCount('variabel_katalog', 0);

        $id = KatalogVariabel::semua()->pluck('id');
        $this->assertSame($id->count(), $id->unique()->count(), 'Identitas entri katalog harus unik');
    }

    public function test_placeholder_docx_yang_dikenali_tetap_sama_dengan_sebelum_registri(): void
    {
        $this->assertEqualsCanonicalizing(self::MACRO_DIKENAL_LAMA, NotulaBagian1DocxService::macroDikenal());
    }

    public function test_variabel_bawaan_format_bagian_kustom_sama_dengan_registri(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys(TemplateBagianKustom::VARIABEL_BAWAAN),
            RegistriVariabel::kodeKonteks(RegistriVariabel::KONTEKS_FORMAT_BAGIAN_KUSTOM),
        );
    }

    public function test_halaman_katalog_menampilkan_variabel_sistem_dengan_jumlah_akurat(): void
    {
        $this->sebagai('Tim SAKIP');
        VariabelKatalog::create(['kode' => 'level_risiko', 'nama' => 'Level risiko', 'tipe' => 'pilihan', 'opsi' => ['Rendah', 'Tinggi']]);
        $this->bagian(['kolom' => [['kunci' => 'dampak', 'label' => 'Dampak', 'tipe' => 'teks'], ['kunci' => 'level', 'label' => 'Level', 'tipe' => 'pilihan', 'variabel' => 'level_risiko']]]);

        $total = count(RegistriVariabel::semua()) + 1 + 2;

        Livewire::test(VariabelKatalogManager::class)
            ->assertSee($total.' variabel')
            ->assertSee('{{target_pk}}')
            ->assertSee('{kode_iku}')
            ->assertSee('{dampak}')
            ->assertSee('Level risiko')
            ->assertSee('Master IKU')
            ->assertSee('Target, Realisasi &amp; Capaian (termasuk n/N)', false);
    }

    /* ---------- 3: pencarian & filter ---------- */

    public function test_pencarian_dan_filter_katalog(): void
    {
        $this->sebagai('Tim SAKIP');
        VariabelKatalog::create(['kode' => 'skor_mitra', 'nama' => 'Skor mitra', 'tipe' => 'angka', 'kategori' => 'kegiatan']);
        $total = count(RegistriVariabel::semua()) + 1;

        $k = Livewire::test(VariabelKatalogManager::class)
            ->set('cari', 'target_pk')
            ->assertSee('{{target_pk}}')
            ->assertDontSee('{{nama_satker}}')
            ->assertSee('dari '.$total.' variabel');

        $k->set('cari', '')->set('filterJenis', 'definisi')->assertSee('Skor mitra')->assertDontSee('{{target_pk}}');
        $k->set('filterJenis', '')->set('filterKategori', 'rtl')->assertSee('{{rtl_berikutnya}}')->assertDontSee('{{indikator}}');
        $k->set('filterKategori', '')->set('filterKonteks', RegistriVariabel::KONTEKS_FORMAT_BAGIAN_KUSTOM)->assertSee('{sasaran_iku}')->assertDontSee('{{target_pk}}');
        $k->set('filterKonteks', '')->set('filterTipe', 'tautan')->assertSee('{{bukti_realisasi}}')->assertDontSee('{{kendala}}');
        $k->set('filterTipe', '')->set('filterStatus', 'usang')->assertSee('{{blok_sakip}}')->assertDontSee('{{target_pk}}');

        $hasil = KatalogVariabel::saring(KatalogVariabel::semua(), ['cari' => 'n/N']);
        $this->assertContains('formula_capaian', $hasil->pluck('kode')->all());
    }

    public function test_detail_variabel_menampilkan_sumber_aturan_dan_konteks(): void
    {
        $this->sebagai('Tim SAKIP');

        Livewire::test(VariabelKatalogManager::class)
            ->call('bukaDetail', 'sistem:target_pk')
            ->assertSee('CapaianTahunan::targetTahunan()')
            ->assertSee('Template Bagian I Notula (.docx)')
            ->assertSee('Hanya di dalam {{iku_blok}}');
    }

    /* ---------- 5, 6, 7: resolver ---------- */

    public function test_resolver_poin_mengambil_nilai_dari_sumber_dan_konteks_periode(): void
    {
        $b = $this->bagian(['nama' => 'Risiko', 'kolom' => [['kunci' => 'skor', 'label' => 'Skor', 'tipe' => 'angka'], ['kunci' => 'ket', 'label' => 'Ket', 'tipe' => 'teks']]]);
        $poin = $this->poin($b, 7, 'Uraian A', ['skor' => '0', 'ket' => '']);

        $k = TemplateBagianKustom::konteksPoin($poin->fresh(['masterIku', 'periode']), $b);

        $this->assertSame('KV-1', $k['kode_iku']);
        $this->assertSame('Persentase uji katalog', $k['indikator_iku']);
        $this->assertSame('Sasaran uji katalog', $k['sasaran_iku']);
        $this->assertSame('Persen', $k['satuan_iku']);
        $this->assertSame('Uraian A', $k['teks']);
        $this->assertSame('Risiko', $k['nama_bagian']);
        $this->assertSame('2026', $k['tahun']);
        $this->assertSame('III', $k['triwulan']);
        $this->assertSame('Juli', $k['bulan']);

        // Nol tetap "0", kosong tetap kosong (bukan "0"), tanda kurung kosong dibuang.
        $this->assertSame('0', $k['skor']);
        $this->assertSame('', $k['ket']);
        $this->assertSame('Uraian A skor 0', TemplateBagianKustom::render('{teks} skor {skor} ({ket})', $k));

        // Variabel tidak dikenal TIDAK diganti kosong â€” tercetak apa adanya.
        $this->assertSame('Uraian A {tidak_ada}', TemplateBagianKustom::render('{teks} {tidak_ada}', $k));
    }

    public function test_kolom_lama_bernama_sama_dengan_variabel_bawaan_baru_tetap_menang(): void
    {
        $b = $this->bagian(['kolom' => [['kunci' => 'sasaran_iku', 'label' => 'Sasaran lokal', 'tipe' => 'teks']]]);
        $poin = $this->poin($b, 1, 'X', ['sasaran_iku' => 'Isian lama']);

        $this->assertSame('Isian lama', TemplateBagianKustom::konteksPoin($poin->fresh(['masterIku', 'periode']), $b)['sasaran_iku']);
    }

    /* ---------- 16: keluaran notula ---------- */

    public function test_notula_mencetak_variabel_sistem_baru_dan_hanya_triwulan_notula(): void
    {
        $this->fakeKonversiBagian1KeXmlMentah();
        $b = $this->bagian(['format_notula' => '{kode_iku} | {sasaran_iku} | {satuan_iku} | {teks} | {nilai}']);
        $this->poin($b, 4, 'Poin TW2', ['nilai' => '0']);
        $this->poin($b, 7, 'Poin TW3', ['nilai' => '5']);

        $html = app(NotulaService::class)->susunBagianSatu(app(NotulaService::class)->untukTriwulan(2026, 2));

        $this->assertStringContainsString('KV-1 | Sasaran uji katalog | Persen | Poin TW2 | 0', $html);
        $this->assertStringNotContainsString('Poin TW3', $html);
    }

    /* ---------- 8: hak akses ---------- */

    public function test_katalog_dan_template_hanya_untuk_tim_sakip(): void
    {
        foreach (['Ketua Tim', 'Kepala'] as $peran) {
            $this->sebagai($peran);
            Livewire::test(VariabelKatalogManager::class)->assertForbidden();
            $this->get(route('template-notula.index'))->assertForbidden();
            $this->get(route('master-iku.index'))->assertForbidden();
        }
    }

    /* ---------- 9, 10, 13: kolom Bagian Kustom ---------- */

    public function test_builder_menampilkan_info_variabel_katalog_dan_menautkannya(): void
    {
        $this->sebagai('Tim SAKIP');
        VariabelKatalog::create([
            'kode' => 'level_risiko', 'nama' => 'Level risiko', 'tipe' => 'pilihan', 'opsi' => ['Rendah', 'Tinggi'],
            'kategori' => 'rtl', 'keterangan' => 'Tingkat risiko hasil penilaian', 'contoh' => 'Tinggi', 'catatan' => 'Pakai skala organisasi',
        ]);
        $b = $this->bagian(['kolom' => []]);

        Livewire::test(BagianKustomManager::class)
            ->call('buka', $b->id)
            ->call('setTab', 'isian')
            ->call('tambahKolom', $b->id)
            ->set("form.{$b->id}.kolom.0.label", 'Level')
            ->set("form.{$b->id}.kolom.0.variabel", 'level_risiko')
            ->assertSee('Tingkat risiko hasil penilaian')
            ->assertSee('Pakai skala organisasi')
            ->assertSee('Diisi pengguna')
            ->call('simpan', $b->id)
            ->assertHasNoErrors();

        $kolom = $b->fresh()->kolomList()[0];
        $this->assertSame('level_risiko', $kolom['variabel']);
        $this->assertSame(['Rendah', 'Tinggi'], $kolom['opsi']);
    }

    public function test_variabel_kolom_tetap_terhubung_saat_label_dan_definisi_diubah(): void
    {
        $this->sebagai('Tim SAKIP');
        $v = VariabelKatalog::create(['kode' => 'level_risiko', 'nama' => 'Level risiko', 'tipe' => 'pilihan', 'opsi' => ['Rendah', 'Tinggi']]);
        $b = $this->bagian(['format_notula' => '{teks} ({level})', 'kolom' => [['kunci' => 'level', 'label' => 'Level', 'tipe' => 'pilihan', 'variabel' => 'level_risiko']]]);
        $poin = $this->poin($b, 1, 'Risiko A', ['level' => 'Tinggi']);

        // Label kolom diganti: kode variabel tetap "level".
        $kolom = $b->kolom;
        $kolom[0]['label'] = 'Tingkat Risiko';
        $b->update(['kolom' => $kolom]);

        $entri = KatalogVariabel::semua()->firstWhere('id', 'kolom:'.$b->id.':level');
        $this->assertSame('Tingkat Risiko', $entri['nama']);

        // Nama/kategori/deskripsi definisi diubah: tautan & keluaran tidak berubah.
        Livewire::test(VariabelKatalogManager::class)
            ->call('ubah', $v->id)
            ->set('form.nama', 'Tingkat risiko (baru)')
            ->set('form.kategori', 'rtl')
            ->set('form.keterangan', 'Deskripsi baru')
            ->call('simpan')
            ->assertHasNoErrors();

        $b = $b->fresh();
        $this->assertSame('pilihan', $b->kolomList()[0]['tipe']);
        $this->assertSame('Risiko A (Tinggi)', TemplateBagianKustom::render($b->formatNotulaEfektif(), TemplateBagianKustom::konteksPoin($poin->fresh(['masterIku', 'periode']), $b)));
        $this->assertSame('rtl', KatalogVariabel::semua()->firstWhere('kode', 'level_risiko')['kategori']);
    }

    public function test_kode_definisi_baru_tidak_boleh_sama_dengan_variabel_sistem_dan_pengganti_harus_ada(): void
    {
        $this->sebagai('Tim SAKIP');

        Livewire::test(VariabelKatalogManager::class)
            ->call('baru')->set('form.kode', 'target_pk')->set('form.nama', 'Target')->call('simpan')
            ->assertHasErrors(['form.kode']);

        Livewire::test(VariabelKatalogManager::class)
            ->call('baru')->set('form.kode', 'skor_baru')->set('form.nama', 'Skor')->set('form.pengganti_kode', 'tidak_ada')->call('simpan')
            ->assertHasErrors(['form.pengganti_kode']);

        $this->assertDatabaseCount('variabel_katalog', 0);
    }

    /* ---------- 14: nonaktif / historis ---------- */

    public function test_definisi_nonaktif_yang_masih_dipakai_menjadi_historis_dan_tetap_terbaca(): void
    {
        $this->sebagai('Tim SAKIP');
        $lama = VariabelKatalog::create(['kode' => 'skor_lama', 'nama' => 'Skor lama', 'tipe' => 'angka']);
        VariabelKatalog::create(['kode' => 'skor_baru', 'nama' => 'Skor baru', 'tipe' => 'angka']);
        $tidakDipakai = VariabelKatalog::create(['kode' => 'cadangan', 'nama' => 'Cadangan', 'tipe' => 'teks', 'aktif' => false]);
        $b = $this->bagian(['kolom' => [['kunci' => 'skor', 'label' => 'Skor', 'tipe' => 'teks', 'variabel' => 'skor_lama']]]);
        $this->poin($b, 1, 'A', ['skor' => '12']);

        Livewire::test(VariabelKatalogManager::class)->call('toggleAktif', $lama->id);
        Livewire::test(VariabelKatalogManager::class)->call('ubah', $lama->id)->set('form.pengganti_kode', 'skor_baru')->call('simpan')->assertHasNoErrors();

        $katalog = KatalogVariabel::semua();
        $this->assertSame('usang', $katalog->firstWhere('kode', 'skor_lama')['status']);
        $this->assertSame('skor_baru', $katalog->firstWhere('kode', 'skor_lama')['pengganti']);
        $this->assertSame('nonaktif', $katalog->firstWhere('kode', $tidakDipakai->kode)['status']);
        $this->assertSame('angka', $b->fresh()->kolomList()[0]['tipe'], 'kolom lama tetap mengikuti definisi');
        $this->assertSame(['skor' => '12'], $b->fresh()->nilaiKolomTampil(BagianKustomPoin::sole()->nilai_kolom));

        Livewire::test(VariabelKatalogManager::class)->set('filterStatus', 'usang')->assertSee('Skor lama')->assertDontSee('Cadangan');
    }

    /* ---------- 11, 12, 14: template .docx ---------- */

    public function test_template_bawaan_lolos_pemeriksaan_lengkap(): void
    {
        $hasil = app(NotulaBagian1DocxService::class)->analisisTemplate(base_path(self::TEMPLATE_BAWAAN));

        $this->assertNotEmpty($hasil['dipakai']);
        $this->assertSame([], $hasil['tidak_dikenal']);
        $this->assertSame([], $hasil['usang']);
        $this->assertSame([], $hasil['salah_tempat']);
    }

    public function test_analisis_template_menemukan_tidak_dikenal_usang_dan_salah_tempat(): void
    {
        $path = $this->templateDiubah(fn (string $xml) => str_replace(
            '{{tempat}}',
            '{{tempat}} {{ruang_rapat}} {{kode}} {{target_proksi}}',
            $xml
        ));

        try {
            $hasil = app(NotulaBagian1DocxService::class)->analisisTemplate($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(['ruang_rapat'], $hasil['tidak_dikenal']);
        $this->assertSame(['target_proksi' => 'proksi_target'], $hasil['usang']);
        $this->assertArrayHasKey('kode', $hasil['salah_tempat']);
        $this->assertStringContainsString('iku_blok', $hasil['salah_tempat']['kode']);
        // Delimiter statis PhpWord dikembalikan setelah analisis.
        $this->assertSame([], app(NotulaBagian1DocxService::class)->macroTidakDikenal(base_path(self::TEMPLATE_BAWAAN)));
    }

    public function test_halaman_template_memilih_variabel_dan_memeriksa_template_aktif(): void
    {
        Storage::fake('local');
        $this->sebagai('Tim SAKIP');

        $k = Livewire::test(TemplateNotula::class)
            ->assertSee('{{analisis_capaian}}')
            ->set('cariVariabel', 'batas waktu')
            ->assertSee('{{batas_waktu_rtl}}')
            ->assertDontSee('{{nama_satker}}')
            ->set('cariVariabel', '')
            ->set('kategoriVariabel', 'bukti')
            ->assertSee('{{lampiran_basis_data}}')
            ->assertDontSee('{{kendala}}');

        // Template bawaan: semua dikenali.
        $k->call('periksaTemplate')->assertSee('Template bawaan')->assertSee('Semua placeholder dikenali');

        // Template unggahan bermasalah: peringatan jelas, bukan diganti kosong diam-diam.
        $path = $this->templateDiubah(fn (string $xml) => str_replace('{{tempat}}', '{{tempat}} {{ruang_rapat}} {{indikator}}', $xml));
        Storage::disk('local')->put('template-notula/uji.docx', file_get_contents($path));
        @unlink($path);
        FolderConfig::current()->update(['template_notula_path' => 'template-notula/uji.docx', 'template_notula_nama_asli' => 'uji.docx']);

        $k->call('periksaTemplate')
            ->assertSee('Template unggahan')
            ->assertSee('{{ruang_rapat}}')
            ->assertSee('{{indikator}}')
            ->assertSee('di luar lingkupnya');
    }

    public function test_unggah_template_memperingatkan_placeholder_tidak_dikenal(): void
    {
        Storage::fake('local');
        $this->sebagai('Tim SAKIP');
        $path = $this->templateDiubah(fn (string $xml) => str_replace('{{tempat}}', '{{tempat}} {{ruang_rapat}}', $xml));

        $this->instance(\App\Services\FolderStructureService::class, \Mockery::mock(\App\Services\FolderStructureService::class, function ($mock) {
            $mock->shouldReceive('unggahTemplateNotula')->andThrow(new \RuntimeException('Drive tidak tersedia di tes'));
        }));

        Livewire::test(TemplateNotula::class)
            ->set('templateFile', \Illuminate\Http\UploadedFile::fake()->createWithContent('t.docx', file_get_contents($path)))
            ->call('unggah')
            ->assertHasNoErrors()
            ->assertSee('tidak dikenali sistem')
            ->assertSee('{{ruang_rapat}}');

        @unlink($path);
    }

    public function test_tab_notula_bagian_kustom_menawarkan_variabel_sistem_dan_kolom(): void
    {
        $this->sebagai('Tim SAKIP');
        $b = $this->bagian(['kolom' => [['kunci' => 'dampak', 'label' => 'Dampak', 'tipe' => 'teks']]]);

        $k = Livewire::test(BagianKustomManager::class)
            ->call('buka', $b->id)
            ->call('setTab', 'notula')
            ->assertSee('{sasaran_iku}')
            ->assertSee('{satuan_iku}')
            ->assertSee('{dampak}')
            ->assertSee('Kolom bagian ini')
            ->assertSee('master_iku.sasaran');

        // Format memakai variabel sistem baru: tersimpan tanpa galat, pratinjau terisi.
        $k->set("form.{$b->id}.format_notula", '{kode_iku} {sasaran_iku}: {teks}')
            ->call('simpan', $b->id)
            ->assertHasNoErrors();
        $this->assertSame('{kode_iku} {sasaran_iku}: {teks}', $b->fresh()->format_notula);

        // Variabel tidak dikenal ditolak dengan pesan jelas.
        $k->set("form.{$b->id}.format_notula", '{teks} {tidak_ada}')
            ->call('simpan', $b->id)
            ->assertHasErrors(["form.{$b->id}.format_notula"]);
        $this->assertSame('{kode_iku} {sasaran_iku}: {teks}', $b->fresh()->format_notula);
    }
}
