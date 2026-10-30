<?php

namespace Tests\Feature;

use App\Livewire\BagianKustomManager;
use App\Livewire\IsianBagianKustom;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\LogAktivitas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Services\NotulaService;
use App\Support\AlurBagianKustom;
use App\Support\JenisKolomBagianKustom;
use App\Support\RumusBagianKustom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\PenjagaDatabaseUji;
use Tests\TestCase;

/**
 * Bagian Kustom sebagai form builder dinamis: struktur (subbagian, jenis kolom baru,
 * tabel, rumus, syarat tampil) disusun lewat layar Data Master, lalu benar-benar
 * dipakai formulir, validasi, penyimpanan, alur kerja, bukti dukung, dan notula.
 */
class BagianKustomFormBuilderDinamisTest extends TestCase
{
    use PenjagaDatabaseUji;
    use RefreshDatabase;

    protected MasterIku $iku;

    protected Periode $periode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pastikanDatabaseUji();
        Storage::fake('local');
        BagianKustom::lupakanCache();

        foreach (['Ketua Tim', 'Tim SAKIP', 'Kepala'] as $nama) {
            Role::firstOrCreate(['nama' => $nama]);
        }

        $this->iku = MasterIku::create(['kode' => 'FB-1', 'indikator' => 'IKU form builder', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
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

    protected function isian()
    {
        return Livewire::test(IsianBagianKustom::class, ['menu' => 'isian_bagian_kustom'])->set('tahun', 2026)->set('bulan', 4);
    }

    protected function pdf(string $nama = 'bukti.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nama, "%PDF-1.4\n".str_repeat('x', 1024)."\n%%EOF");
    }

    /** Buat bagian lewat form "Tambah bagian" di layar Data Master. */
    protected function buatLewatUi($m, string $nama, array $peran = ['tim_sakip'], array $menu = ['isian_bagian_kustom']): BagianKustom
    {
        $m->set('namaBaru', $nama)->set('peranBaru', $peran)->set('penempatanBaru', $menu)->call('tambah')->assertHasNoErrors();

        $bagian = BagianKustom::where('nama', $nama)->firstOrFail();

        // "Tambah" langsung membuka editor bagian baru; buka() bersifat buka/tutup.
        if ($m->get('bukaId') !== $bagian->id) {
            $m->call('buka', $bagian->id);
        }

        return $bagian;
    }

    /** Tambah satu kolom lewat builder, kembalikan indeksnya di form. */
    protected function kolomUi($m, int $id, array $isi, string $sub = ''): int
    {
        $m->call('tambahKolom', $id, $sub);
        $j = count($m->get("form.{$id}.kolom")) - 1;

        foreach ($isi as $k => $v) {
            $m->set("form.{$id}.kolom.{$j}.{$k}", $v);
        }

        return $j;
    }

    /**
     * Bagian A — "Capaian Layanan": angka, persentase, rumus, pilihan + kolom bersyarat.
     */
    protected function bagianA($m): BagianKustom
    {
        $a = $this->buatLewatUi($m, 'Capaian Layanan');
        $this->kolomUi($m, $a->id, ['label' => 'Target', 'tipe' => 'angka', 'wajib' => true]);
        $this->kolomUi($m, $a->id, ['label' => 'Realisasi', 'tipe' => 'angka', 'wajib' => true]);
        $this->kolomUi($m, $a->id, ['label' => 'Capaian', 'tipe' => 'hitung', 'rumus' => '{realisasi} / {target} * 100', 'desimal' => '1', 'satuan' => '%']);
        $this->kolomUi($m, $a->id, ['label' => 'Kepuasan', 'tipe' => 'persentase', 'min' => '0', 'maks' => '100']);
        $this->kolomUi($m, $a->id, ['label' => 'Tercapai', 'tipe' => 'pilihan', 'opsi_teks' => 'Ya, Tidak', 'wajib' => true]);
        $this->kolomUi($m, $a->id, ['label' => 'Tindak Lanjut', 'tipe' => 'teks', 'wajib' => true, 'syarat_kunci' => 'tercapai', 'syarat_operator' => 'sama', 'syarat_nilai' => 'Tidak']);
        $m->call('simpan', $a->id)->assertHasNoErrors();

        return $a->fresh();
    }

    /**
     * Bagian B — "Sosialisasi": dua subbagian (satu wajib), tabel dengan kolom berbeda, tautan.
     */
    protected function bagianB($m): BagianKustom
    {
        $b = $this->buatLewatUi($m, 'Sosialisasi Statistik');
        $m->call('tambahSubbagian', $b->id)->set("form.{$b->id}.subbagian.0.nama", 'Pelaksanaan')->set("form.{$b->id}.subbagian.0.wajib", true);
        $m->call('tambahSubbagian', $b->id)->set("form.{$b->id}.subbagian.1.nama", 'Evaluasi');

        $j = $this->kolomUi($m, $b->id, ['label' => 'Rincian Kegiatan', 'tipe' => 'tabel', 'baris_maks' => '3'], 'sub_1');
        $m->call('tambahKolomTabel', $b->id, $j)->set("form.{$b->id}.kolom.{$j}.tabel_kolom.0.label", 'Nama')->set("form.{$b->id}.kolom.{$j}.tabel_kolom.0.wajib", true);
        $m->call('tambahKolomTabel', $b->id, $j)->set("form.{$b->id}.kolom.{$j}.tabel_kolom.1.label", 'Peserta')->set("form.{$b->id}.kolom.{$j}.tabel_kolom.1.tipe", 'angka');
        $m->call('tambahKolomTabel', $b->id, $j)->set("form.{$b->id}.kolom.{$j}.tabel_kolom.2.label", 'Moda')->set("form.{$b->id}.kolom.{$j}.tabel_kolom.2.tipe", 'pilihan')->set("form.{$b->id}.kolom.{$j}.tabel_kolom.2.opsi_teks", 'Daring, Luring');
        $this->kolomUi($m, $b->id, ['label' => 'Dokumentasi', 'tipe' => 'tautan'], 'sub_1');
        $this->kolomUi($m, $b->id, ['label' => 'Catatan Evaluasi', 'tipe' => 'teks_panjang'], 'sub_2');
        $m->call('simpan', $b->id)->assertHasNoErrors();

        return $b->fresh();
    }

    /* ---------- 1–4: builder lewat UI, dua bagian berbeda ---------- */

    public function test_bagian_baru_subbagian_dan_struktur_berbeda_dibuat_lewat_ui(): void
    {
        $this->sebagai('Tim SAKIP');
        $m = Livewire::test(BagianKustomManager::class);
        $a = $this->bagianA($m);
        $b = $this->bagianB($m);

        $kolomA = collect($a->kolomList())->keyBy('kunci');
        $kolomB = collect($b->kolomList())->keyBy('kunci');

        $this->assertSame(['target', 'realisasi', 'capaian', 'kepuasan', 'tercapai', 'tindak_lanjut'], $kolomA->keys()->all());
        $this->assertSame('hitung', $kolomA['capaian']['tipe']);
        $this->assertSame('{realisasi} / {target} * 100', $kolomA['capaian']['rumus']);
        $this->assertSame('%', $kolomA['kepuasan']['satuan']);
        $this->assertSame(['kunci' => 'tercapai', 'operator' => 'sama', 'nilai' => 'Tidak'], $kolomA['tindak_lanjut']['tampil_jika']);

        $this->assertSame(['sub_1', 'sub_2'], array_column($b->subbagianList(), 'kunci'));
        $this->assertTrue($b->subbagianList()[0]['wajib']);
        $this->assertSame('tabel', $kolomB['rincian_kegiatan']['tipe']);
        $this->assertSame(['nama', 'peserta', 'moda'], array_column($kolomB['rincian_kegiatan']['tabel_kolom'], 'kunci'));
        $this->assertSame(['Daring', 'Luring'], $kolomB['rincian_kegiatan']['tabel_kolom'][2]['opsi']);
        $this->assertSame(3, $kolomB['rincian_kegiatan']['baris_maks']);
        $this->assertSame('tautan', $kolomB['dokumentasi']['tipe']);

        // Dua bagian tidak saling memengaruhi.
        $this->assertEmpty(array_intersect($kolomA->keys()->all(), $kolomB->keys()->all()));
    }

    public function test_builder_menolak_konfigurasi_tidak_sah(): void
    {
        $this->sebagai('Tim SAKIP');
        $m = Livewire::test(BagianKustomManager::class);
        $a = $this->buatLewatUi($m, 'Uji Galat');

        $j = $this->kolomUi($m, $a->id, ['label' => 'Hasil', 'tipe' => 'hitung', 'rumus' => '{tidak_ada} * 2']);
        $m->call('simpan', $a->id)->assertHasErrors(["form.{$a->id}.kolom.{$j}.rumus"]);

        $m->set("form.{$a->id}.kolom.{$j}.tipe", 'tabel')->call('simpan', $a->id)->assertHasErrors(["form.{$a->id}.kolom.{$j}.tabel_kolom"]);

        $m->set("form.{$a->id}.kolom.{$j}.tipe", 'teks')->set("form.{$a->id}.kolom.{$j}.syarat_kunci", 'hantu')
            ->call('simpan', $a->id)->assertHasErrors(["form.{$a->id}.kolom.{$j}.syarat_kunci"]);

        // Alur pemeriksaan tanpa menu selain Isian Kegiatan ditolak.
        $k = $this->buatLewatUi($m, 'Hanya Isian Kegiatan', ['ketua_tim'], ['pengisian']);
        $m->set("form.{$k->id}.alur.mode", 'verifikasi')->call('simpan', $k->id)->assertHasErrors(["form.{$k->id}.alur"]);

        // Bukti "sebelum diajukan" tanpa alur pemeriksaan ditolak.
        $m->set("form.{$a->id}.kolom", [])->set("form.{$a->id}.aturan_bukti.mode", 'wajib')->set("form.{$a->id}.aturan_bukti.saat", 'ajukan')
            ->call('simpan', $a->id)->assertHasErrors(["form.{$a->id}.aturan_bukti.saat"]);

        $this->assertNull($a->fresh()->alur);
        $this->assertSame([], $a->fresh()->kolomList());
    }

    /* ---------- 5–7: formulir, simpan & baca ulang, validasi ---------- */

    public function test_formulir_mengikuti_konfigurasi_dan_data_tersimpan_terbaca_ulang(): void
    {
        $this->sebagai('Tim SAKIP');
        $m = Livewire::test(BagianKustomManager::class);
        $a = $this->bagianA($m);
        $b = $this->bagianB($m);

        $this->sebagai('Tim SAKIP');
        $k = $this->isian()
            // Formulir A: input persentase, nilai rumus otomatis, kolom bersyarat.
            ->assertSeeHtml('wire:model.blur="baris.'.$a->id.'.0.kolom.kepuasan"')
            ->assertSeeHtml('data-hitung="capaian"')
            ->assertSeeHtml('data-sj="baris.'.$a->id.'.0.kolom.tercapai"')
            // Formulir B: tabel dengan kolom berbeda + tautan.
            ->assertSeeHtml('data-tabel="rincian_kegiatan"')
            ->assertSeeHtml('type="url"')
            ->assertSee('Peserta')
            ->assertSee('Pelaksanaan');

        $rincian = json_encode([['nama' => 'Sosialisasi desa', 'peserta' => '25', 'moda' => 'Luring'], ['nama' => '', 'peserta' => '', 'moda' => '']]);

        $k->set("baris.{$a->id}.0.iku_id", (string) $this->iku->id)->set("baris.{$a->id}.0.teks", 'Layanan konsultasi')
            ->set("baris.{$a->id}.0.kolom.target", '80')->set("baris.{$a->id}.0.kolom.realisasi", '60')
            ->set("baris.{$a->id}.0.kolom.kepuasan", '92,5')->set("baris.{$a->id}.0.kolom.tercapai", 'Tidak')
            ->set("baris.{$a->id}.0.kolom.tindak_lanjut", 'Tambah jadwal')
            ->call('simpan', $a->id)->assertHasNoErrors()
            ->set("baris.{$b->id}.0.iku_id", (string) $this->iku->id)->set("baris.{$b->id}.0.teks", 'Sosialisasi SE2026')
            ->set("baris.{$b->id}.0.kolom.rincian_kegiatan", $rincian)
            ->set("baris.{$b->id}.0.kolom.dokumentasi", 'https://bps.go.id/foto')
            ->call('simpan', $b->id)->assertHasNoErrors();

        $poinA = BagianKustomPoin::where('bagian_kustom_id', $a->id)->sole();
        $poinB = BagianKustomPoin::where('bagian_kustom_id', $b->id)->sole();

        // Rumus dihitung server: 60 / 80 * 100 = 75.
        $this->assertSame('75', $poinA->nilai_kolom['capaian']);
        $this->assertSame('Tidak', $poinA->nilai_kolom['tercapai']);
        $this->assertSame('Tambah jadwal', $poinA->nilai_kolom['tindak_lanjut']);
        $this->assertSame('75,0%', JenisKolomBagianKustom::tampil($poinA->nilai_kolom['capaian'], collect($a->kolomList())->firstWhere('kunci', 'capaian')));
        // Baris tabel kosong dibuang, angka disimpan kanonik.
        $this->assertSame([['nama' => 'Sosialisasi desa', 'peserta' => '25', 'moda' => 'Luring']], json_decode($poinB->nilai_kolom['rincian_kegiatan'], true));
        $this->assertSame('terverifikasi', $poinA->status_verifikasi, 'Alur bawaan: langsung sah.');

        // Dibaca ulang ke formulir.
        $baru = $this->isian();
        $this->assertSame('Tidak', $baru->get("baris.{$a->id}.0.kolom.tercapai"));
        $this->assertSame([['nama' => 'Sosialisasi desa', 'peserta' => '25', 'moda' => 'Luring']], json_decode($baru->get("baris.{$b->id}.0.kolom.rincian_kegiatan"), true));
        $this->assertStringContainsString('1) Nama: Sosialisasi desa; Peserta: 25; Moda: Luring', BagianKustom::nilaiTampil(collect($b->kolomList())->firstWhere('kunci', 'rincian_kegiatan'), $poinB->nilai_kolom['rincian_kegiatan']));
    }

    public function test_validasi_wajib_opsional_jenis_tabel_syarat_dan_subbagian_wajib(): void
    {
        $this->sebagai('Tim SAKIP');
        $m = Livewire::test(BagianKustomManager::class);
        $a = $this->bagianA($m);
        $b = $this->bagianB($m);

        $k = $this->isian()
            ->set("baris.{$a->id}.0.iku_id", (string) $this->iku->id)->set("baris.{$a->id}.0.teks", 'Uji')
            ->set("baris.{$a->id}.0.kolom.kepuasan", '120')->set("baris.{$a->id}.0.kolom.tercapai", 'Tidak')
            ->call('simpan', $a->id)
            ->assertHasErrors([
                "baris.{$a->id}.0.kolom.target", "baris.{$a->id}.0.kolom.realisasi",   // wajib
                "baris.{$a->id}.0.kolom.kepuasan",                                     // di luar 0–100
                "baris.{$a->id}.0.kolom.tindak_lanjut",                                 // bersyarat & wajib
            ]);
        $this->assertSame(0, BagianKustomPoin::count());

        // Kolom bersyarat yang tersembunyi tidak wajib dan isiannya tidak disimpan.
        $k->set("baris.{$a->id}.0.kolom.target", '10')->set("baris.{$a->id}.0.kolom.realisasi", '10')->set("baris.{$a->id}.0.kolom.kepuasan", '')
            ->set("baris.{$a->id}.0.kolom.tercapai", 'Ya')->set("baris.{$a->id}.0.kolom.tindak_lanjut", 'sisa ketikan')
            ->call('simpan', $a->id)->assertHasNoErrors();
        $this->assertArrayNotHasKey('tindak_lanjut', BagianKustomPoin::where('bagian_kustom_id', $a->id)->sole()->nilai_kolom);
        $this->assertArrayNotHasKey('kepuasan', BagianKustomPoin::where('bagian_kustom_id', $a->id)->sole()->nilai_kolom, 'Opsional boleh kosong.');

        // Subbagian wajib, sel tabel wajib, tautan, jumlah baris maksimal.
        $k->set("baris.{$b->id}.0.iku_id", (string) $this->iku->id)->set("baris.{$b->id}.0.teks", 'Uji B')
            ->set("baris.{$b->id}.0.kolom.catatan_evaluasi", 'Hanya evaluasi')
            ->call('simpan', $b->id)->assertHasErrors(["baris.{$b->id}.0.kolom.rincian_kegiatan"]);

        $k->set("baris.{$b->id}.0.kolom.rincian_kegiatan", json_encode([['nama' => '', 'peserta' => '3']]))
            ->set("baris.{$b->id}.0.kolom.dokumentasi", 'bukan tautan')
            ->call('simpan', $b->id)->assertHasErrors(["baris.{$b->id}.0.kolom.rincian_kegiatan", "baris.{$b->id}.0.kolom.dokumentasi"]);

        $empat = array_fill(0, 4, ['nama' => 'X']);
        $k->set("baris.{$b->id}.0.kolom.rincian_kegiatan", json_encode($empat))->set("baris.{$b->id}.0.kolom.dokumentasi", '')
            ->call('simpan', $b->id)->assertHasErrors(["baris.{$b->id}.0.kolom.rincian_kegiatan"]);

        $k->set("baris.{$b->id}.0.kolom.rincian_kegiatan", json_encode([['nama' => 'X', 'moda' => 'Daring']]))
            ->call('simpan', $b->id)->assertHasNoErrors();
        $this->assertSame(1, BagianKustomPoin::where('bagian_kustom_id', $b->id)->count());
    }

    public function test_pratinjau_menguji_validasi_dari_pengaturan_yang_belum_disimpan(): void
    {
        $this->sebagai('Tim SAKIP');
        $m = Livewire::test(BagianKustomManager::class);
        $a = $this->bagianA($m);

        // Ubah tanpa menyimpan: Kepuasan menjadi wajib. Pratinjau langsung memakainya.
        $m->set("form.{$a->id}.kolom.3.wajib", true)->call('setTab', 'pratinjau')
            ->assertSeeHtml('wire:model.blur="pratinjauNilai.'.$a->id.'.kepuasan"')
            ->set("pratinjauNilai.{$a->id}.target", '50')->set("pratinjauNilai.{$a->id}.realisasi", '25')->set("pratinjauNilai.{$a->id}.tercapai", 'Ya')
            ->call('ujiPratinjau', $a->id)
            ->assertHasErrors(["pratinjauNilai.{$a->id}.kepuasan"])
            ->set("pratinjauNilai.{$a->id}.kepuasan", '80')
            ->call('ujiPratinjau', $a->id)
            ->assertHasNoErrors()
            ->assertSee('50,0%');

        $this->assertFalse(collect($a->fresh()->kolomList())->firstWhere('kunci', 'kepuasan')['wajib'], 'Pratinjau tidak menyimpan pengaturan.');
        $this->assertSame(0, BagianKustomPoin::count(), 'Pratinjau tidak menyimpan isian.');
    }

    /* ---------- 8–10: hak akses, alur kerja, bukti dukung ---------- */

    protected function bagianBeralur(array $aturanBukti = ['mode' => 'opsional']): BagianKustom
    {
        BagianKustom::lupakanCache();

        return BagianKustom::create([
            'nama' => 'Laporan Kepala', 'aktif' => true, 'peran' => ['kepala'], 'penempatan' => ['isian_bagian_kustom'],
            'aturan_bukti' => $aturanBukti + ['format' => ['pdf']],
            'alur' => ['mode' => 'verifikasi', 'pengaju' => ['kepala'], 'pemeriksa' => ['tim_sakip'], 'ubah_setelah_disetujui' => false, 'notula' => 'disetujui'],
            'masuk_notula' => true,
            'kolom' => [['kunci' => 'skor', 'label' => 'Skor', 'tipe' => 'angka', 'wajib' => true]],
        ]);
    }

    public function test_alur_draf_ajukan_kunci_setujui_kembalikan_dan_transisi_tidak_sah_ditolak(): void
    {
        $bagian = $this->bagianBeralur();
        $this->sebagai('Kepala');

        $k = $this->isian()
            ->assertSee('Ajukan')->assertDontSee('Tarik pengajuan')
            ->set("baris.{$bagian->id}.0.iku_id", (string) $this->iku->id)->set("baris.{$bagian->id}.0.teks", 'Draf kepala')
            ->set("baris.{$bagian->id}.0.kolom.skor", '7')
            ->call('simpan', $bagian->id)->assertHasNoErrors();

        $poin = BagianKustomPoin::sole();
        $this->assertSame(AlurBagianKustom::DRAF, AlurBagianKustom::statusPoin($poin));

        // Draf tidak tampil ke pemeriksa & tidak bisa langsung disetujui.
        $this->sebagai('Tim SAKIP');
        $this->isian()->assertSee('Belum ada poin yang diajukan')->call('setujui', $poin->id)->assertHasErrors(["periksa.{$poin->id}"]);
        $this->assertSame(AlurBagianKustom::DRAF, AlurBagianKustom::statusPoin($poin->fresh()));

        // Ajukan.
        $this->sebagai('Kepala');
        $k = $this->isian()->call('ajukan', $bagian->id)->assertHasNoErrors();
        $poin->refresh();
        $this->assertSame(AlurBagianKustom::DIAJUKAN, AlurBagianKustom::statusPoin($poin));
        $this->assertNotNull($poin->diajukan_oleh);
        $this->assertTrue(LogAktivitas::where('aksi', 'bagian_kustom_poin.diajukan')->exists());

        // Poin diajukan terkunci: ubah isi ditolak server, data tidak berubah.
        $k->set("baris.{$bagian->id}.0.teks", 'Diubah diam-diam')->call('simpan', $bagian->id)->assertHasErrors(["baris.{$bagian->id}"]);
        $this->assertSame('Draf kepala', $poin->fresh()->teks);

        // Kembalikan tanpa catatan ditolak; dengan catatan berhasil.
        $this->sebagai('Tim SAKIP');
        $p = $this->isian()->assertSee('Draf kepala')
            ->call('kembalikan', $poin->id)->assertHasErrors(["periksa.{$poin->id}"])
            ->set("catatanPeriksa.{$poin->id}", 'Skor belum sesuai')->call('kembalikan', $poin->id)->assertHasNoErrors();
        $this->assertSame(AlurBagianKustom::DIKEMBALIKAN, AlurBagianKustom::statusPoin($poin->fresh()));
        $this->assertSame('Skor belum sesuai', $poin->fresh()->catatan);

        // Dikembalikan tidak bisa langsung disetujui (harus diajukan ulang).
        $p->call('setujui', $poin->id)->assertHasErrors(["periksa.{$poin->id}"]);

        // Pengisi memperbaiki, mengajukan ulang, pemeriksa menyetujui.
        $this->sebagai('Kepala');
        $this->isian()->assertSee('Skor belum sesuai')
            ->set("baris.{$bagian->id}.0.kolom.skor", '9')->call('simpan', $bagian->id)->assertHasNoErrors()
            ->call('ajukan', $bagian->id)->assertHasNoErrors();

        $this->sebagai('Tim SAKIP');
        $this->isian()->call('setujui', $poin->id)->assertHasNoErrors();
        $poin->refresh();
        $this->assertSame(AlurBagianKustom::DISETUJUI, AlurBagianKustom::statusPoin($poin));
        $this->assertSame('9', $poin->nilai_kolom['skor']);
        $this->assertNotNull($poin->diperiksa_oleh);

        // Disetujui tanpa izin ubah: tetap terkunci.
        $this->sebagai('Kepala');
        $this->isian()->set("baris.{$bagian->id}.0.teks", 'Ubah setelah disetujui')->call('simpan', $bagian->id)->assertHasErrors(["baris.{$bagian->id}"]);
        $this->assertSame(AlurBagianKustom::DISETUJUI, AlurBagianKustom::statusPoin($poin->fresh()));
    }

    public function test_hak_akses_alur_diperiksa_di_server(): void
    {
        $bagian = $this->bagianBeralur();
        $this->sebagai('Kepala');
        $this->isian()->set("baris.{$bagian->id}.0.iku_id", (string) $this->iku->id)->set("baris.{$bagian->id}.0.teks", 'Poin')
            ->set("baris.{$bagian->id}.0.kolom.skor", '5')->call('simpan', $bagian->id)->call('ajukan', $bagian->id);
        $poin = BagianKustomPoin::sole();

        // Pengisi bukan pemeriksa: setujui ditolak 403.
        $this->isian()->call('setujui', $poin->id)->assertForbidden();
        $this->assertSame(AlurBagianKustom::DIAJUKAN, AlurBagianKustom::statusPoin($poin->fresh()));

        // Pengisi yang tidak ada di daftar pengaju: ajukan/tarik ditolak 403.
        $bagian->update(['alur' => ['pengaju' => []] + $bagian->alur]);
        BagianKustom::lupakanCache();
        $this->isian()->call('tarikPengajuan', $bagian->id)->assertForbidden();

        // Tim SAKIP yang bukan pengisi tidak bisa mengajukan.
        $this->sebagai('Tim SAKIP');
        $this->isian()->call('ajukan', $bagian->id)->assertForbidden();

        // Pemeriksa dicabut: setujui ditolak 403.
        $bagian->update(['alur' => ['pemeriksa' => ['kepala']] + $bagian->alur]);
        BagianKustom::lupakanCache();
        $this->isian()->call('setujui', $poin->id)->assertForbidden();
        $this->assertSame(AlurBagianKustom::DIAJUKAN, AlurBagianKustom::statusPoin($poin->fresh()));
    }

    public function test_bukti_wajib_sebelum_diajukan_terhubung_ke_poin_yang_benar(): void
    {
        $bagian = $this->bagianBeralur(['mode' => 'wajib', 'min' => 1, 'saat' => 'ajukan']);
        $this->assertSame('ajukan', $bagian->aturanBuktiDi('isian_bagian_kustom')['saat']);
        $this->assertSame('simpan', $bagian->aturanBuktiDi('pengisian')['saat'], 'Isian Kegiatan selalu memeriksa saat simpan/ajukan Isian Kegiatan.');

        $this->sebagai('Kepala');
        // Draf boleh disimpan tanpa bukti …
        $k = $this->isian()
            ->set("baris.{$bagian->id}.0.iku_id", (string) $this->iku->id)->set("baris.{$bagian->id}.0.teks", 'Poin satu')->set("baris.{$bagian->id}.0.kolom.skor", '1')
            ->call('tambahBaris', $bagian->id)
            ->set("baris.{$bagian->id}.1.iku_id", (string) $this->iku->id)->set("baris.{$bagian->id}.1.teks", 'Poin dua')->set("baris.{$bagian->id}.1.kolom.skor", '2')
            ->call('simpan', $bagian->id)->assertHasNoErrors();
        $this->assertSame(2, BagianKustomPoin::count());

        // … tetapi belum bisa diajukan.
        $k->call('ajukan', $bagian->id)->assertHasErrors(["baris.{$bagian->id}"]);
        $this->assertSame(0, BagianKustomPoin::whereNotNull('diajukan_pada')->count());

        // Bukti diunggah untuk poin KEDUA saja.
        $k->set("bukti.{$bagian->id}.1", [$this->pdf('dua.pdf')])->call('simpan', $bagian->id)->assertHasNoErrors();
        $poinDua = BagianKustomPoin::where('teks', 'Poin dua')->sole();
        $berkas = Berkas::sole();
        $this->assertSame($poinDua->id, (int) $berkas->ref_id);
        $this->assertSame(BagianKustomPoin::class, $berkas->ref_type);
        $this->assertSame('menunggu', $berkas->status_verifikasi);

        // Poin satu masih tanpa bukti → seluruh pengajuan ditolak.
        $k->call('ajukan', $bagian->id)->assertHasErrors(["baris.{$bagian->id}"]);

        $k->set("bukti.{$bagian->id}.0", [$this->pdf('satu.pdf')])->call('simpan', $bagian->id)->assertHasNoErrors()
            ->call('ajukan', $bagian->id)->assertHasNoErrors();
        $this->assertSame(2, BagianKustomPoin::whereNotNull('diajukan_pada')->count());

        // Disetujui → bukti poin itu ikut terverifikasi, bukti poin lain tidak tersentuh.
        $this->sebagai('Tim SAKIP');
        $this->isian()->call('setujui', $poinDua->id)->assertHasNoErrors();
        $this->assertSame('terverifikasi', $berkas->fresh()->status_verifikasi);
        $this->assertSame('menunggu', Berkas::where('ref_id', '!=', $poinDua->id)->sole()->status_verifikasi);
    }

    public function test_bukti_wajib_sebelum_disetujui_menahan_persetujuan(): void
    {
        $bagian = $this->bagianBeralur(['mode' => 'wajib', 'min' => 1, 'saat' => 'verifikasi']);
        $this->sebagai('Kepala');
        $this->isian()->set("baris.{$bagian->id}.0.iku_id", (string) $this->iku->id)->set("baris.{$bagian->id}.0.teks", 'Tanpa bukti')
            ->set("baris.{$bagian->id}.0.kolom.skor", '3')->call('simpan', $bagian->id)->assertHasNoErrors()
            ->call('ajukan', $bagian->id)->assertHasNoErrors();
        $poin = BagianKustomPoin::sole();

        $this->sebagai('Tim SAKIP');
        $this->isian()->call('setujui', $poin->id)->assertHasErrors(["periksa.{$poin->id}"]);
        $this->assertSame(AlurBagianKustom::DIAJUKAN, AlurBagianKustom::statusPoin($poin->fresh()));
    }

    /* ---------- 11: notula ---------- */

    public function test_notula_hanya_mencetak_isian_disetujui_bila_diatur(): void
    {
        $bagian = $this->bagianBeralur();
        $dasar = ['bagian_kustom_id' => $bagian->id, 'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id, 'menu' => 'isian_bagian_kustom', 'peran_pengisi' => 'kepala'];
        BagianKustomPoin::create($dasar + ['teks' => 'Sudah disetujui', 'status_verifikasi' => 'terverifikasi']);
        BagianKustomPoin::create($dasar + ['teks' => 'Masih draf', 'status_verifikasi' => 'menunggu']);

        $notula = app(NotulaService::class)->untukTriwulan(2026, 2);
        $teks = fn () => app(NotulaService::class)->kumpulkanDataBagianSatu($notula)['bagianKustomPerBagian']->flatMap(fn ($b) => $b->poin->pluck('teks'))->all();

        $this->assertSame(['Sudah disetujui'], $teks());

        $bagian->update(['alur' => ['notula' => 'semua'] + $bagian->alur]);
        $this->assertEqualsCanonicalizing(['Sudah disetujui', 'Masih draf'], $teks());

        // Bagian tanpa poin yang disetujui tidak dicetak sama sekali.
        $bagian->update(['alur' => ['notula' => 'disetujui'] + $bagian->alur]);
        BagianKustomPoin::where('teks', 'Sudah disetujui')->update(['status_verifikasi' => 'menunggu']);
        $this->assertSame([], $teks());
    }

    /* ---------- 12, 14: data lama aman & cache ---------- */

    public function test_perubahan_konfigurasi_tidak_menghilangkan_data_lama(): void
    {
        $this->sebagai('Tim SAKIP');
        $m = Livewire::test(BagianKustomManager::class);
        $b = $this->bagianB($m);
        $poin = BagianKustomPoin::create([
            'bagian_kustom_id' => $b->id, 'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id, 'menu' => 'isian_bagian_kustom', 'peran_pengisi' => 'tim_sakip',
            'teks' => 'Data lama', 'status_verifikasi' => 'terverifikasi',
            'nilai_kolom' => ['rincian_kegiatan' => json_encode([['nama' => 'Rapat', 'peserta' => '10']]), 'catatan_evaluasi' => 'Baik'],
        ]);
        $sebelum = $poin->nilai_kolom;
        $m = Livewire::test(BagianKustomManager::class)->call('buka', $b->id);

        // Menghapus kolom tabel yang berisi data ditolak.
        $m->call('hapusKolomTabel', $b->id, 0, 1)->call('simpan', $b->id)->assertHasErrors(["form.{$b->id}.kolom"]);
        $m->call('batalUbah', $b->id);

        // Ganti label, tambah kolom, jadikan bersyarat, periode, alur — data tetap.
        $m->set("form.{$b->id}.kolom.2.label", 'Evaluasi Akhir')
            ->set("form.{$b->id}.periode_berlaku.triwulan", ['2', '4'])
            ->set("form.{$b->id}.alur.mode", 'verifikasi')->set("form.{$b->id}.alur.pemeriksa", ['tim_sakip'])
            ->call('simpan', $b->id)->assertHasNoErrors();

        $this->assertSame($sebelum, $poin->fresh()->nilai_kolom);
        $this->assertSame('terverifikasi', $poin->fresh()->status_verifikasi);
        $this->assertSame('Evaluasi Akhir', collect($b->fresh()->kolomList())->firstWhere('kunci', 'catatan_evaluasi')['label']);
        $this->assertSame([2, 4], $b->fresh()->periodeBerlaku()['triwulan']);
        $this->assertSame(1, BagianKustomPoin::count());
    }

    public function test_cache_diperbarui_setelah_konfigurasi_berubah_dan_periode_berlaku_diterapkan(): void
    {
        $this->sebagai('Tim SAKIP');
        $m = Livewire::test(BagianKustomManager::class);
        $a = $this->bagianA($m);

        // Isi cache dulu.
        $this->assertTrue(BagianKustom::daftarAktif()->contains('id', $a->id));
        $this->isian()->assertSee('Capaian Layanan');

        // Hanya TW I & III: tidak tampil di April (TW II), dan aksi simpan ditolak.
        $m->set("form.{$a->id}.periode_berlaku.triwulan", ['1', '3'])->call('simpan', $a->id)->assertHasNoErrors();
        $this->assertSame([1, 3], BagianKustom::daftarAktif()->firstWhere('id', $a->id)->periodeBerlaku()['triwulan'], 'Cache dilupakan setelah simpan.');
        // Formulirnya tidak tampil; halaman hanya menjelaskan bahwa bagian tidak berlaku.
        $this->isian()->assertDontSeeHtml('id="bagian-kustom-'.$a->id.'"')
            ->assertSee('"Capaian Layanan" tidak berlaku pada TW II 2026')
            ->call('simpan', $a->id)->assertForbidden();

        // Juli (TW III) tampil kembali.
        Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
        $this->isian()->set('bulan', 7)->assertSee('Capaian Layanan');

        // Kolom baru langsung tampil di formulir tanpa menunggu cache kedaluwarsa.
        $m->set("form.{$a->id}.periode_berlaku.triwulan", [])->call('tambahKolom', $a->id);
        $j = count($m->get("form.{$a->id}.kolom")) - 1;
        $m->set("form.{$a->id}.kolom.{$j}.label", 'Kolom Baru Sekali')->call('simpan', $a->id)->assertHasNoErrors();
        $this->isian()->assertSee('Kolom Baru Sekali');
    }

    public function test_salinan_bagian_membawa_struktur_alur_dan_periode(): void
    {
        $this->sebagai('Tim SAKIP');
        $m = Livewire::test(BagianKustomManager::class);
        $b = $this->bagianB($m);
        $m->set("form.{$b->id}.alur.mode", 'verifikasi')->set("form.{$b->id}.alur.pemeriksa", ['tim_sakip'])
            ->set("form.{$b->id}.periode_berlaku.triwulan", ['4'])->call('simpan', $b->id)->assertHasNoErrors();

        $m->call('duplikasi', $b->id);
        $salinan = BagianKustom::where('nama', 'like', 'Sosialisasi Statistik (salinan)%')->sole();

        $this->assertSame('verifikasi', $salinan->alurKerja()['mode']);
        $this->assertSame([4], $salinan->periodeBerlaku()['triwulan']);
        $this->assertSame(array_column($b->fresh()->kolomList(), 'tabel_kolom'), array_column($salinan->kolomList(), 'tabel_kolom'));
        $this->assertSame(0, BagianKustomPoin::where('bagian_kustom_id', $salinan->id)->count());
    }

    /* ---------- Unit: rumus ---------- */

    public function test_rumus_aman_dan_tepat(): void
    {
        $this->assertSame('75', RumusBagianKustom::hitung('{a} / {b} * 100', ['a' => '60', 'b' => '80']));
        $this->assertSame('-3', RumusBagianKustom::hitung('-{a} + ({b} - {a}) * 0', ['a' => '3', 'b' => '9']));
        $this->assertSame('0.333333', RumusBagianKustom::hitung('{a} / 3', ['a' => '1']));
        $this->assertSame('3.5', RumusBagianKustom::hitung('{a} + 1,5', ['a' => '2']));
        $this->assertNull(RumusBagianKustom::hitung('{a} / {b}', ['a' => '1', 'b' => '0']), 'Bagi nol = kosong.');
        $this->assertNull(RumusBagianKustom::hitung('{a} + {b}', ['a' => '1', 'b' => null]), 'Nilai kosong = kosong.');

        $this->assertNull(RumusBagianKustom::galat('({a} + {b}) / 2', ['a', 'b']));
        $this->assertNotNull(RumusBagianKustom::galat('{a} + system("x")', ['a']));
        $this->assertNotNull(RumusBagianKustom::galat('{a} +', ['a']));
        $this->assertNotNull(RumusBagianKustom::galat('({a}', ['a']));
        $this->assertNotNull(RumusBagianKustom::galat('2 * 3', []));
    }
}
