<?php

namespace Tests\Feature;

use App\Livewire\BagianKustomManager;
use App\Livewire\IsianBagianKustom;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\RiwayatKonfigurasiBagianKustom;
use App\Models\Role;
use App\Models\User;
use App\Support\AlurBagianKustom;
use App\Support\JenisKolomBagianKustom;
use App\Support\KelengkapanBagianKustom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\PenjagaDatabaseUji;
use Tests\TestCase;

/**
 * Penyempurnaan mesin Bagian Kustom (30 Okt 2026): jenis kolom tambahan, operator
 * syarat tampil, persetujuan berjenjang, pemeriksaan lintas menu, versi konfigurasi,
 * dan pemeriksaan sebelum terbit. Semua lewat jalur yang dipakai pengguna sungguhan.
 */
class BagianKustomMesinDinamisTest extends TestCase
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

        $this->iku = MasterIku::create(['kode' => 'MD-1', 'indikator' => 'IKU mesin dinamis', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
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

    protected function isian(string $menu = 'isian_bagian_kustom')
    {
        return Livewire::test(IsianBagianKustom::class, ['menu' => $menu])->set('tahun', 2026)->set('bulan', 4);
    }

    protected function buatLewatUi($m, string $nama, array $peran = ['tim_sakip'], array $menu = ['isian_bagian_kustom']): BagianKustom
    {
        $m->set('namaBaru', $nama)->set('peranBaru', $peran)->set('penempatanBaru', $menu)->call('tambah')->assertHasNoErrors();
        $bagian = BagianKustom::where('nama', $nama)->firstOrFail();

        if ($m->get('bukaId') !== $bagian->id) {
            $m->call('buka', $bagian->id);
        }

        return $bagian;
    }

    protected function kolomUi($m, int $id, array $isi, string $sub = ''): int
    {
        $m->call('tambahKolom', $id, $sub);
        $j = count($m->get("form.{$id}.kolom")) - 1;

        foreach ($isi as $k => $v) {
            $m->set("form.{$id}.kolom.{$j}.{$k}", $v);
        }

        return $j;
    }

    /** Bagian "Register Risiko" dengan semua jenis kolom tambahan, dibuat lewat layar. */
    protected function bagianJenisBaru($m): BagianKustom
    {
        $b = $this->buatLewatUi($m, 'Register Risiko');
        $this->kolomUi($m, $b->id, ['label' => 'Petunjuk', 'tipe' => 'informasi', 'isi_info' => 'Isi sesuai pedoman risiko.']);
        $this->kolomUi($m, $b->id, ['label' => 'Kode Risiko', 'tipe' => 'kode', 'pola' => 'angka', 'wajib' => true]);
        $this->kolomUi($m, $b->id, ['label' => 'Ada Mitigasi', 'tipe' => 'ya_tidak', 'wajib' => true]);
        $this->kolomUi($m, $b->id, ['label' => 'Rencana Mitigasi', 'tipe' => 'teks', 'wajib' => true, 'syarat_kunci' => 'ada_mitigasi', 'syarat_operator' => 'sama', 'syarat_nilai' => 'ya']);
        $this->kolomUi($m, $b->id, ['label' => 'Biaya', 'tipe' => 'mata_uang', 'bulat' => true]);
        $this->kolomUi($m, $b->id, ['label' => 'Catatan Biaya Besar', 'tipe' => 'teks', 'wajib' => true, 'syarat_kunci' => 'biaya', 'syarat_operator' => 'lebih_besar', 'syarat_nilai' => '1000000']);
        $this->kolomUi($m, $b->id, ['label' => 'Jam Rapat', 'tipe' => 'waktu']);
        $this->kolomUi($m, $b->id, ['label' => 'Tenggat', 'tipe' => 'tanggal_waktu']);
        $this->kolomUi($m, $b->id, ['label' => 'Email PJ', 'tipe' => 'email']);
        $this->kolomUi($m, $b->id, ['label' => 'IKU Terkait', 'tipe' => 'referensi', 'sumber' => 'iku']);
        $m->call('simpan', $b->id)->assertHasNoErrors();

        return $b->fresh();
    }

    public function test_jenis_kolom_baru_dibuat_lewat_ui_tersimpan_dan_dimuat_ulang(): void
    {
        $this->sebagai('Tim SAKIP');
        $m = Livewire::test(BagianKustomManager::class);
        $b = $this->bagianJenisBaru($m);
        $k = collect($b->kolomList())->keyBy('kunci');

        $this->assertSame(['petunjuk', 'kode_risiko', 'ada_mitigasi', 'rencana_mitigasi', 'biaya', 'catatan_biaya_besar', 'jam_rapat', 'tenggat', 'email_pj', 'iku_terkait'], $k->keys()->all());
        $this->assertSame('informasi', $k['petunjuk']['tipe']);
        $this->assertSame('Isi sesuai pedoman risiko.', $k['petunjuk']['isi_info']);
        $this->assertFalse($k['petunjuk']['wajib']);
        $this->assertSame('angka', $k['kode_risiko']['pola']);
        $this->assertSame('Rp', $k['biaya']['satuan']);
        $this->assertSame(0, $k['biaya']['desimal']);
        $this->assertTrue($k['biaya']['bulat']);
        $this->assertSame('iku', $k['iku_terkait']['sumber']);
        $this->assertSame(['kunci' => 'biaya', 'operator' => 'lebih_besar', 'nilai' => '1000000'], $k['catatan_biaya_besar']['tampil_jika']);

        // Dimuat ulang ke form editor (mis. setelah refresh) dengan nilai yang sama.
        $m2 = Livewire::test(BagianKustomManager::class)->call('buka', $b->id);
        $form = collect($m2->get("form.{$b->id}.kolom"))->keyBy('kunci');
        $this->assertSame('angka', $form['kode_risiko']['pola']);
        $this->assertTrue($form['biaya']['bulat']);
        $this->assertSame('iku', $form['iku_terkait']['sumber']);
        $this->assertSame('Isi sesuai pedoman risiko.', $form['petunjuk']['isi_info']);
        $this->assertSame('lebih_besar', $form['catatan_biaya_besar']['syarat_operator']);

        // Simpan ulang tanpa perubahan tidak mengubah definisi maupun versinya.
        $versi = $b->versiKonfigurasi();
        $m2->call('simpan', $b->id)->assertHasNoErrors();
        $this->assertSame($versi, $b->fresh()->versiKonfigurasi());
    }

    public function test_isian_jenis_baru_divalidasi_server_disimpan_kanonik_dan_ditampilkan(): void
    {
        $this->sebagai('Tim SAKIP');
        $b = $this->bagianJenisBaru(Livewire::test(BagianKustomManager::class));
        $p = "baris.{$b->id}.0";

        $k = $this->isian()
            ->assertSee('Isi sesuai pedoman risiko.')
            ->set("{$p}.iku_id", (string) $this->iku->id)->set("{$p}.teks", 'Risiko data terlambat')
            ->set("{$p}.kolom.kode_risiko", 'R-01')
            ->set("{$p}.kolom.ada_mitigasi", 'mungkin')
            ->set("{$p}.kolom.biaya", '1.500,50')
            ->set("{$p}.kolom.jam_rapat", '25:00')
            ->set("{$p}.kolom.tenggat", '2026-02-30T10:00')
            ->set("{$p}.kolom.email_pj", 'bukan-email')
            ->set("{$p}.kolom.iku_terkait", '999999')
            ->call('simpan', $b->id)
            ->assertHasErrors(["{$p}.kolom.kode_risiko", "{$p}.kolom.ada_mitigasi", "{$p}.kolom.biaya", "{$p}.kolom.jam_rapat", "{$p}.kolom.tenggat", "{$p}.kolom.email_pj", "{$p}.kolom.iku_terkait"]);

        $this->assertSame(0, BagianKustomPoin::count());

        // "Ya" → kolom bersyarat wajib; biaya > 1 juta → catatan wajib.
        $k->set("{$p}.kolom.kode_risiko", '0012')->set("{$p}.kolom.ada_mitigasi", 'ya')
            ->set("{$p}.kolom.biaya", '2.500.000')->set("{$p}.kolom.jam_rapat", '9:05')
            ->set("{$p}.kolom.tenggat", '2026-06-30T14:30')->set("{$p}.kolom.email_pj", 'pj@bps.go.id')
            ->set("{$p}.kolom.iku_terkait", (string) $this->iku->id)
            ->call('simpan', $b->id)
            ->assertHasErrors(["{$p}.kolom.rencana_mitigasi", "{$p}.kolom.catatan_biaya_besar"]);

        $k->set("{$p}.kolom.rencana_mitigasi", 'Jadwal ulang')->set("{$p}.kolom.catatan_biaya_besar", 'Disetujui PPK')
            ->call('simpan', $b->id)->assertHasNoErrors();

        $poin = BagianKustomPoin::sole();
        $n = $poin->nilai_kolom;
        $this->assertSame('0012', $n['kode_risiko']);
        $this->assertSame('ya', $n['ada_mitigasi']);
        $this->assertSame('2500000', $n['biaya']);
        $this->assertSame('09:05', $n['jam_rapat']);
        $this->assertSame('2026-06-30T14:30', $n['tenggat']);
        $this->assertSame((string) $this->iku->id, $n['iku_terkait']);
        $this->assertArrayNotHasKey('petunjuk', $n);

        $tampil = $b->fresh()->nilaiKolomTampil($n);
        $this->assertSame('Rp 2.500.000', $tampil['biaya']);
        $this->assertSame('Ya', $tampil['ada_mitigasi']);
        $this->assertSame('09.05', $tampil['jam_rapat']);
        $this->assertSame('30 Juni 2026 pukul 14.30', $tampil['tenggat']);
        $this->assertSame('MD-1 — IKU mesin dinamis', $tampil['iku_terkait']);

        // Label referensi dibaca ulang: ganti nama IKU tidak memutus isian lama.
        $this->iku->update(['indikator' => 'IKU berganti nama']);
        \Illuminate\Support\Once::flush();
        $this->assertSame('MD-1 — IKU berganti nama', $b->fresh()->nilaiKolomTampil($poin->fresh()->nilai_kolom)['iku_terkait']);
    }

    public function test_operator_syarat_tampil_dievaluasi_sama_seperti_di_browser(): void
    {
        $angka = ['kunci' => 'nilai', 'label' => 'Nilai', 'tipe' => 'angka', 'desimal' => 2, 'pemisah' => 'koma'];
        $s = fn ($op, $nilai = '') => BagianKustom::normalkanSyarat(['kunci' => 'nilai', 'operator' => $op, 'nilai' => $nilai]);

        $this->assertTrue(BagianKustom::syaratTerpenuhi($s('lebih_besar', '1000'), ['nilai' => '1.250,5'], $angka));
        $this->assertFalse(BagianKustom::syaratTerpenuhi($s('lebih_besar', '1000'), ['nilai' => '999,99'], $angka));
        $this->assertTrue(BagianKustom::syaratTerpenuhi($s('lebih_kecil', '10,5'), ['nilai' => '10'], $angka));
        $this->assertFalse(BagianKustom::syaratTerpenuhi($s('lebih_besar', '1'), ['nilai' => ''], $angka));
        $this->assertTrue(BagianKustom::syaratTerpenuhi($s('berisi', 'banjir'), ['nilai' => 'Risiko BANJIR musiman']));
        $this->assertTrue(BagianKustom::syaratTerpenuhi($s('berisi', 'tinggi'), ['nilai' => '["Rendah","Sangat Tinggi"]']));
        $this->assertTrue(BagianKustom::syaratTerpenuhi($s('kosong'), ['nilai' => '']));
        $this->assertFalse(BagianKustom::syaratTerpenuhi($s('kosong'), ['nilai' => 'x']));
        $this->assertTrue(BagianKustom::syaratTerpenuhi($s('lebih_besar', '2026-06-30'), ['nilai' => '2026-07-01']));
        $this->assertSame(['kunci' => 'nilai', 'operator' => 'kosong', 'nilai' => ''], $s('kosong', 'diabaikan'));
        $this->assertSame(1250.5, BagianKustom::angkaSyarat('1.250,5', 'koma'));
        $this->assertSame(1250.5, BagianKustom::angkaSyarat('1,250.5', 'titik'));
        $this->assertNull(BagianKustom::angkaSyarat('12a', ''));
    }

    public function test_builder_menolak_syarat_dan_jenis_yang_tidak_sah(): void
    {
        $this->sebagai('Tim SAKIP');
        $m = Livewire::test(BagianKustomManager::class);
        $b = $this->buatLewatUi($m, 'Uji Galat Mesin');
        $this->kolomUi($m, $b->id, ['label' => 'Nama', 'tipe' => 'teks']);
        $m->call('simpan', $b->id)->assertHasNoErrors();

        // Perbandingan pada kolom teks ditolak.
        $j = $this->kolomUi($m, $b->id, ['label' => 'Lanjut', 'tipe' => 'teks', 'syarat_kunci' => 'nama', 'syarat_operator' => 'lebih_besar', 'syarat_nilai' => '5']);
        $m->call('simpan', $b->id)->assertHasErrors(["form.{$b->id}.kolom.{$j}.syarat_operator"]);

        // Teks informasi tanpa isi ditolak.
        $m->set("form.{$b->id}.kolom.{$j}", array_merge($m->get("form.{$b->id}.kolom.{$j}"), ['tipe' => 'informasi', 'syarat_kunci' => '', 'isi_info' => '']));
        $m->call('simpan', $b->id)->assertHasErrors(["form.{$b->id}.kolom.{$j}.isi_info"]);

        // Kolom yang sudah berisi teks bebas tidak bisa diganti menjadi email tanpa data cocok.
        $m->set("form.{$b->id}.kolom", [$m->get("form.{$b->id}.kolom.0")])->call('simpan', $b->id)->assertHasNoErrors();
        BagianKustomPoin::create(['bagian_kustom_id' => $b->id, 'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id, 'menu' => 'isian_bagian_kustom', 'peran_pengisi' => 'tim_sakip', 'teks' => 'x', 'nilai_kolom' => ['nama' => 'Budi'], 'status_verifikasi' => 'terverifikasi']);
        $m->set("form.{$b->id}.kolom.0.tipe", 'email')->call('simpan', $b->id)->assertHasErrors(["form.{$b->id}.kolom"]);
        $this->assertSame('teks', collect($b->fresh()->kolomList())->firstWhere('kunci', 'nama')['tipe']);
        $this->assertSame('Budi', BagianKustomPoin::sole()->nilai_kolom['nama']);
    }

    public function test_sumber_referensi_yang_sudah_berisi_tidak_bisa_diganti(): void
    {
        $this->sebagai('Tim SAKIP');
        $m = Livewire::test(BagianKustomManager::class);
        $b = $this->buatLewatUi($m, 'Uji Referensi');
        $this->kolomUi($m, $b->id, ['label' => 'Acuan', 'tipe' => 'referensi', 'sumber' => 'iku']);
        $m->call('simpan', $b->id)->assertHasNoErrors();

        BagianKustomPoin::create(['bagian_kustom_id' => $b->id, 'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id, 'menu' => 'isian_bagian_kustom', 'peran_pengisi' => 'tim_sakip', 'teks' => 'x', 'nilai_kolom' => ['acuan' => (string) $this->iku->id], 'status_verifikasi' => 'terverifikasi']);

        $m->set("form.{$b->id}.kolom.0.sumber", 'pengguna')->call('simpan', $b->id)->assertHasErrors(["form.{$b->id}.kolom"]);
        $this->assertSame('iku', collect($b->fresh()->kolomList())->firstWhere('kunci', 'acuan')['sumber']);
    }

    /** Bagian beralur dua tahap: Ketua Tim mengisi di Rekap Kinerja Tahunan; Tim SAKIP lalu Kepala menyetujui. */
    protected function bagianBerjenjangLewatUi(): BagianKustom
    {
        $this->sebagai('Tim SAKIP');
        $m = Livewire::test(BagianKustomManager::class);
        $b = $this->buatLewatUi($m, 'Evaluasi RTL Berjenjang', ['ketua_tim'], ['lakin']);
        $this->kolomUi($m, $b->id, ['label' => 'Skor', 'tipe' => 'angka', 'wajib' => true]);
        $m->set("form.{$b->id}.alur.mode", 'verifikasi')
            ->set("form.{$b->id}.alur.pengaju", ['ketua_tim'])
            ->set("form.{$b->id}.alur.pemeriksa", ['tim_sakip'])
            ->set("form.{$b->id}.alur.label_tahap1", 'Pemeriksaan Tim SAKIP')
            ->call('tambahTahapAlur', $b->id)
            ->set("form.{$b->id}.alur.tahap_lanjut.0.label", 'Persetujuan Kepala')
            ->set("form.{$b->id}.alur.tahap_lanjut.0.pemeriksa", ['kepala'])
            ->set("form.{$b->id}.alur.label_status.dikembalikan", 'Perlu perbaikan')
            ->call('simpan', $b->id)->assertHasNoErrors();

        return $b->fresh();
    }

    public function test_persetujuan_berjenjang_mengikuti_giliran_tahap_dan_diperiksa_server(): void
    {
        $b = $this->bagianBerjenjangLewatUi();
        $alur = $b->alurKerja();
        $this->assertSame(2, AlurBagianKustom::jumlahTahap($alur));
        $this->assertSame(['Pemeriksaan Tim SAKIP', 'Persetujuan Kepala'], array_column($alur['tahapan'], 'label'));
        $this->assertSame(['tim_sakip', 'kepala'], $alur['pemeriksa']);

        $this->sebagai('Ketua Tim');
        $this->isian('lakin')
            ->set("baris.{$b->id}.0.iku_id", (string) $this->iku->id)->set("baris.{$b->id}.0.teks", 'Evaluasi TW II')
            ->set("baris.{$b->id}.0.kolom.skor", '8')
            ->call('simpan', $b->id)->assertHasNoErrors()
            ->call('ajukan', $b->id)->assertHasNoErrors();
        $poin = BagianKustomPoin::sole();
        $this->assertSame(1, $poin->tahap_periksa);

        // Kepala belum gilirannya (tahap 1 milik Tim SAKIP): ditolak, status tetap.
        $this->sebagai('Kepala');
        $this->isian('lakin')->assertSee('bukan giliran')->call('setujui', $poin->id)->assertHasErrors(["periksa.{$poin->id}"]);
        $this->assertSame(1, $poin->fresh()->tahap_periksa);

        // Tim SAKIP menyetujui tahap 1 → diteruskan ke tahap 2 (masih "diajukan").
        $this->sebagai('Tim SAKIP');
        $this->isian('lakin')->assertSee('Setujui &amp; teruskan', false)->call('setujui', $poin->id)->assertHasNoErrors();
        $poin->refresh();
        $this->assertSame(AlurBagianKustom::DIAJUKAN, AlurBagianKustom::statusPoin($poin));
        $this->assertSame(2, $poin->tahap_periksa);
        $this->assertStringContainsString('Persetujuan Kepala (2/2)', AlurBagianKustom::labelPoin($poin, $alur));

        // Tim SAKIP tidak bisa menyetujui dua kali; pengisi tidak bisa menarik lagi.
        $this->isian('lakin')->call('setujui', $poin->id)->assertHasErrors(["periksa.{$poin->id}"]);
        $this->sebagai('Ketua Tim');
        $this->isian('lakin')->assertDontSee('Tarik pengajuan')->call('tarikPengajuan', $b->id)->assertHasErrors(["baris.{$b->id}"]);
        $this->assertSame(2, $poin->fresh()->tahap_periksa);

        // Kepala mengembalikan → label status kustom, kembali ke pengisi.
        $this->sebagai('Kepala');
        $this->isian('lakin')->set("catatanPeriksa.{$poin->id}", 'Lengkapi skor')->call('kembalikan', $poin->id)->assertHasNoErrors();
        $poin->refresh();
        $this->assertSame(AlurBagianKustom::DIKEMBALIKAN, AlurBagianKustom::statusPoin($poin));
        $this->assertNull($poin->tahap_periksa);
        $this->assertSame('Perlu perbaikan', AlurBagianKustom::labelPoin($poin, $alur));

        // Diajukan ulang mulai lagi dari tahap 1, lalu disetujui kedua tahap.
        $this->sebagai('Ketua Tim');
        $this->isian('lakin')->assertSee('Perlu perbaikan')->set("baris.{$b->id}.0.kolom.skor", '9')
            ->call('simpan', $b->id)->assertHasNoErrors()->call('ajukan', $b->id)->assertHasNoErrors();
        $this->assertSame(1, $poin->fresh()->tahap_periksa);

        $this->sebagai('Tim SAKIP');
        $this->isian('lakin')->call('setujui', $poin->id)->assertHasNoErrors();
        $this->sebagai('Kepala');
        $this->isian('lakin')->call('setujui', $poin->id)->assertHasNoErrors();
        $poin->refresh();
        $this->assertSame(AlurBagianKustom::DISETUJUI, AlurBagianKustom::statusPoin($poin));
        $this->assertNull($poin->tahap_periksa);
        $this->assertSame('9', $poin->nilai_kolom['skor']);
    }

    public function test_pemeriksa_memeriksa_isian_dari_menu_penempatan_lain_tanpa_duplikasi(): void
    {
        // Ketua Tim mengisi di RTL & Evaluasi; Tim SAKIP memeriksa dari Isian Bagian Kustom.
        $b = BagianKustom::create([
            'nama' => 'Monitoring Program', 'aktif' => true, 'peran' => ['ketua_tim'], 'penempatan' => ['rtl_evaluasi', 'isian_bagian_kustom'],
            'alur' => ['mode' => 'verifikasi', 'pengaju' => ['ketua_tim'], 'pemeriksa' => ['tim_sakip'], 'ubah_setelah_disetujui' => false, 'notula' => 'semua'],
            'kolom' => [['kunci' => 'progres', 'label' => 'Progres', 'tipe' => 'persentase', 'wajib' => true]],
        ]);

        $this->sebagai('Ketua Tim');
        $this->isian('rtl_evaluasi')
            ->set("baris.{$b->id}.0.iku_id", (string) $this->iku->id)->set("baris.{$b->id}.0.teks", 'Program A')
            ->set("baris.{$b->id}.0.kolom.progres", '75')
            ->call('simpan', $b->id)->call('ajukan', $b->id)->assertHasNoErrors();
        $poin = BagianKustomPoin::sole();
        $this->assertSame('rtl_evaluasi', $poin->menu);

        $this->sebagai('Tim SAKIP');
        $this->isian()->assertSee('Program A')->assertSee('diisi di RTL &amp; Evaluasi', false)
            ->call('setujui', $poin->id)->assertHasNoErrors();

        $this->assertSame(1, BagianKustomPoin::count());
        $this->assertSame(AlurBagianKustom::DISETUJUI, AlurBagianKustom::statusPoin($poin->fresh()));

        // Poin Isian Kegiatan tidak bisa diperiksa lewat alur generik (punya Verifikasi sendiri).
        $b->update(['penempatan' => ['pengisian', 'rtl_evaluasi', 'isian_bagian_kustom']]);
        BagianKustom::lupakanCache();
        $poinKegiatan = BagianKustomPoin::create(['bagian_kustom_id' => $b->id, 'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id, 'menu' => 'pengisian', 'peran_pengisi' => 'ketua_tim', 'teks' => 'Isian Kegiatan', 'status_verifikasi' => 'menunggu', 'diajukan_pada' => now()]);
        $this->isian()->assertDontSee('Isian Kegiatan</div>', false)->call('setujui', $poinKegiatan->id)->assertForbidden();
        $this->assertSame('menunggu', $poinKegiatan->fresh()->status_verifikasi);
    }

    public function test_versi_konfigurasi_naik_dan_tercatat_pada_riwayat_serta_isian(): void
    {
        $this->sebagai('Tim SAKIP');
        $m = Livewire::test(BagianKustomManager::class);
        $b = $this->buatLewatUi($m, 'Uji Versi');
        $this->assertSame(1, $b->fresh()->versiKonfigurasi());

        $this->kolomUi($m, $b->id, ['label' => 'Catatan', 'tipe' => 'teks']);
        $m->call('simpan', $b->id)->assertHasNoErrors();
        $this->assertSame(2, $b->fresh()->versiKonfigurasi());
        $this->assertSame(2, RiwayatKonfigurasiBagianKustom::where('bagian_kustom_id', $b->id)->where('aksi', 'ubah')->latest('id')->value('versi'));

        $this->isian()->set("baris.{$b->id}.0.iku_id", (string) $this->iku->id)->set("baris.{$b->id}.0.teks", 'Isian v2')
            ->call('simpan', $b->id)->assertHasNoErrors();
        $poin = BagianKustomPoin::sole();
        $this->assertSame(2, $poin->versi_konfigurasi);

        // Konfigurasi berubah lagi: isian lama tetap mencatat versi saat disimpan.
        $m->set("form.{$b->id}.kolom.0.label", 'Catatan Baru')->call('simpan', $b->id)->assertHasNoErrors();
        $this->assertSame(3, $b->fresh()->versiKonfigurasi());
        $this->assertSame(2, $poin->fresh()->versi_konfigurasi);

        // Perubahan status saja (tanpa isi) tidak mengubah versi isian.
        $poin->fresh()->update(['status_verifikasi' => 'menunggu']);
        $this->assertSame(2, $poin->fresh()->versi_konfigurasi);
    }

    public function test_pemeriksaan_sebelum_terbit_tiga_tingkat_dan_tampil_di_pratinjau(): void
    {
        $b = BagianKustom::create([
            'nama' => 'Uji Kelengkapan', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom'],
            'kolom' => [
                ['kunci' => 'catatan', 'label' => 'Catatan', 'tipe' => 'teks', 'tampil_jika' => ['kunci' => 'hantu', 'operator' => 'terisi', 'nilai' => '']],
                ['kunci' => 'hasil', 'label' => 'Hasil', 'tipe' => 'hitung', 'rumus' => '{tidak_ada} * 2'],
            ],
            // Tahap 2 hanya Ketua Tim — tidak bisa membuka Isian Bagian Kustom.
            'alur' => ['mode' => 'verifikasi', 'pengaju' => ['tim_sakip'], 'pemeriksa' => ['kepala'], 'tahapan' => [['label' => 'A', 'pemeriksa' => ['kepala']], ['label' => 'B', 'pemeriksa' => ['ketua_tim']]]],
        ]);

        $masalah = collect(KelengkapanBagianKustom::periksa($b, 2026, 2));
        $galat = $masalah->where('tingkat', 'galat')->pluck('pesan')->implode(' | ');
        $this->assertStringContainsString('{hantu}', $galat);
        $this->assertStringContainsString('{tidak_ada}', $galat);
        $this->assertStringContainsString('Tahap pemeriksaan 2', $galat);
        $this->assertTrue($masalah->contains('tingkat', 'info'));
        $this->assertSame($masalah->whereIn('tingkat', ['galat', 'saran'])->count(), KelengkapanBagianKustom::jumlahMasalah($masalah->all()));

        $this->sebagai('Tim SAKIP');
        Livewire::test(BagianKustomManager::class)->call('buka', $b->id)->call('perbaiki', $b->id, 'pratinjau')
            ->assertSee('Pemeriksaan sebelum diterbitkan')->assertSee('Kesalahan — perlu diperbaiki')->assertSee('Simulasi alur kerja');
    }

    public function test_konfigurasi_lama_tetap_satu_tahap_dan_tidak_ditulis_ulang(): void
    {
        $lama = ['mode' => 'verifikasi', 'pengaju' => ['ketua_tim'], 'pemeriksa' => ['tim_sakip'], 'ubah_setelah_disetujui' => false, 'notula' => 'semua'];
        $alur = AlurBagianKustom::normalkan($lama, ['ketua_tim']);
        $this->assertSame([['label' => 'Pemeriksaan', 'pemeriksa' => ['tim_sakip']]], $alur['tahapan']);
        $this->assertSame(AlurBagianKustom::LABEL_STATUS, $alur['label_status']);

        // Poin lama tanpa tahap_periksa dianggap tahap 1 dan tetap bisa diperiksa.
        $poin = new BagianKustomPoin(['status_verifikasi' => 'menunggu', 'diajukan_pada' => now()]);
        $this->assertSame(1, AlurBagianKustom::tahapPoin($poin, $alur));
        $this->assertTrue(AlurBagianKustom::bolehPeriksa($alur, 'tim_sakip', $poin, 'setujui'));
        $this->assertSame('terverifikasi', AlurBagianKustom::hasilPeriksa($alur, $poin, 'setujui')['status_verifikasi']);

        // Bagian lama (alur NULL) yang disimpan tanpa mengubah alur tetap NULL.
        $b = BagianKustom::create(['nama' => 'Bagian Lama', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom'], 'kolom' => []]);
        $this->sebagai('Tim SAKIP');
        Livewire::test(BagianKustomManager::class)->call('buka', $b->id)->set("form.{$b->id}.deskripsi", 'Diubah')->call('simpan', $b->id)->assertHasNoErrors();
        $this->assertNull($b->fresh()->alur);
        $this->assertSame('Diubah', $b->fresh()->deskripsi);

        // Jenis kolom lama tetap dikenal dan nilainya tidak berubah tafsir.
        $this->assertSame('12,5', JenisKolomBagianKustom::tampil('12,5', BagianKustom::normalkanKolom(['kunci' => 'a', 'label' => 'A', 'tipe' => 'angka'])));
    }
}
