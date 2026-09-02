<?php

namespace Tests\Feature;

use App\Livewire\KompilasiNotula;
use App\Models\Capaian;
use App\Models\CapaianTahunan;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Services\NotulaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class KompilasiNotulaTest extends TestCase
{
    use RefreshDatabase;

    protected function loginSebagaiTimSakip(): User
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $user = User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip-notula@example.test', 'email' => 'sakip-notula@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_susun_ulang_otomatis_mengirim_event_untuk_memperbarui_editor_wysiwyg(): void
    {
        $this->fakeKonversiBagian1KeXmlMentah();
        $this->loginSebagaiTimSakip();

        Livewire::test(KompilasiNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->call('susunUlangOtomatis')
            ->assertDispatched('bagian1-diperbarui');
    }

    public function test_simpan_suntingan_bagian1_menyimpan_konten_dari_editor(): void
    {
        $this->fakeKonversiBagian1KeXmlMentah();
        $this->loginSebagaiTimSakip();

        $component = Livewire::test(KompilasiNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->set('bagian1EditText', '<h3>Uji Editor Word</h3><p>Konten disunting langsung.</p>')
            ->call('simpanSuntinganBagian1')
            ->assertHasNoErrors();

        $this->assertStringContainsString(
            'Uji Editor Word',
            Notula::first()->bagian1_html
        );
    }

    public function test_bagian_2_menolak_format_tidak_didukung(): void
    {
        $this->fakeKonversiBagian1KeXmlMentah();
        $this->loginSebagaiTimSakip();

        // docx/xlsx/odt/ods, gambar (jpg/png), dan PDF SEKARANG didukung (notula
        // menyatu — lihat NotulaService::konversiKeKontenInline()); format lain
        // seperti .txt tetap ditolak di lapisan validasi Livewire.
        Livewire::test(KompilasiNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->set('bagian2File', UploadedFile::fake()->create('bagian2.txt', 100, 'text/plain'))
            ->call('unggahBagian', 2)
            ->assertHasErrors('bagian2File');
    }

    public function test_ganti_triwulan_mengirim_event_untuk_memuat_ulang_editor(): void
    {
        $this->fakeKonversiBagian1KeXmlMentah();
        $this->loginSebagaiTimSakip();

        Livewire::test(KompilasiNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->set('triwulan', 2)
            ->assertDispatched('bagian1-diperbarui');
    }

    public function test_simpan_detail_rapat_menyimpan_dan_tampil_di_bagian1(): void
    {
        $this->fakeKonversiBagian1KeXmlMentah();
        $this->loginSebagaiTimSakip();

        $component = Livewire::test(KompilasiNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->set('hariTanggal', 'Senin, 12 Oktober 2026')
            ->set('waktu', '09.00 - 11.00 WITA')
            ->set('tempat', 'Ruang Rapat BPS')
            ->set('pimpinanRapat', 'Kepala BPS')
            ->set('notulis', 'Notulis Uji')
            ->set('kepalaSatker', 'Kepala Satker Uji')
            ->call('simpanDetailRapat')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('notula', [
            'hari_tanggal' => 'Senin, 12 Oktober 2026',
            'waktu' => '09.00 - 11.00 WITA',
            'tempat' => 'Ruang Rapat BPS',
            'pimpinan_rapat' => 'Kepala BPS',
            'notulis' => 'Notulis Uji',
            'kepala_satker' => 'Kepala Satker Uji',
        ]);

        $component->call('susunUlangOtomatis');

        $html = Notula::first()->bagian1_html;
        $this->assertStringContainsString('Senin, 12 Oktober 2026', $html);
        $this->assertStringContainsString('Kepala BPS', $html);
    }

    /**
     * Rekap capaian triwulanan/PK (dipakai narasi pembuka Bagian I) murni perhitungan
     * data -- diuji langsung lewat kumpulkanDataBagianSatu(), TIDAK perlu lewat
     * rendering/konversi apa pun (lebih tahan terhadap perubahan tata letak template).
     */
    public function test_rekap_capaian_dihitung_dari_capaian_tahunan_dan_mengecualikan_iku_tanpa_nilai(): void
    {
        $ikuBernilai = MasterIku::create(['kode' => '9003', 'indikator' => 'Uji IKU Tiga', 'sasaran' => 'Sasaran Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $ikuTanpaNilai = MasterIku::create(['kode' => '9004', 'indikator' => 'Uji IKU Empat Tanpa Nilai', 'sasaran' => 'Sasaran Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        CapaianTahunan::create([
            'iku_id' => $ikuBernilai->id,
            'tahun' => 2026,
            'target_tahunan' => 100,
            'alokasi_tw1' => 25,
            'realisasi_tw1' => 25,
        ]);

        $notula = app(NotulaService::class)->untukTriwulan(2026, 1);
        // Bagian I sejak sekarang hanya menampilkan IKU yang Capaian::status-nya sudah
        // diverifikasi (lihat NotulaService::kumpulkanDataBagianSatu()) -- kedua IKU
        // perlu baris Capaian diverifikasi supaya keduanya tetap ikut dihitung, murni
        // untuk membuktikan yang TANPA NILAI di CapaianTahunan-lah yang dikecualikan
        // dari rata-rata, bukan karena belum diverifikasi.
        Capaian::create(['iku_id' => $ikuBernilai->id, 'periode_id' => $notula->periode_id, 'status' => Capaian::STATUS_DIVERIFIKASI]);
        Capaian::create(['iku_id' => $ikuTanpaNilai->id, 'periode_id' => $notula->periode_id, 'status' => Capaian::STATUS_DIVERIFIKASI]);

        $data = app(NotulaService::class)->kumpulkanDataBagianSatu($notula);

        $this->assertSame(100.0, $data['rataCapaianTw']);
        $this->assertSame(25.0, $data['rataCapaianPk']);
    }

    /**
     * "Terhadap Target Setahun" (capaian_pk) HARUS membagi dengan jumlah TOTAL
     * indikator, bukan cuma yang sudah ternilai -- beda dari "Terhadap Target
     * Triwulanan" (capaian_tw) yang membagi dengan jumlah nilai valid saja -- sesuai
     * baris 87 kolom Y-AB (SUMIF/COUNTIF) vs U-X (AVERAGEIFS) sheet "LK_Kabkot", Kertas
     * Kerja Pengukuran Kinerja Triwulanan resmi (lihat docblock
     * CapaianCalculatorService::rataRataCapaianSetahun()). IKU B realisasinya 0 (belum
     * ada capaian TW ini) sehingga capaian_tw/capaian_pk-nya "-" (TIDAK_DINILAI):
     * capaian_tw mengecualikannya dari pembagi (tetap 100.0, dari IKU A saja), TAPI
     * capaian_pk TETAP menghitungnya di pembagi sebagai 0 -- (25.0 + 0) / 2 = 12.5,
     * BUKAN 25.0/1 seperti bila IKU B ikut dibuang dari pembagi juga.
     */
    public function test_rekap_capaian_pk_membagi_dengan_jumlah_total_indikator_bukan_hanya_yang_ternilai(): void
    {
        $ikuA = MasterIku::create(['kode' => '9005', 'indikator' => 'Uji IKU Lima', 'sasaran' => 'Sasaran Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $ikuB = MasterIku::create(['kode' => '9006', 'indikator' => 'Uji IKU Enam Belum Ternilai', 'sasaran' => 'Sasaran Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        CapaianTahunan::create([
            'iku_id' => $ikuA->id,
            'tahun' => 2026,
            'target_tahunan' => 100,
            'alokasi_tw1' => 25,
            'realisasi_tw1' => 25,
        ]);
        CapaianTahunan::create([
            'iku_id' => $ikuB->id,
            'tahun' => 2026,
            'target_tahunan' => 100,
            'alokasi_tw1' => 25,
            'realisasi_tw1' => 0,
        ]);

        $notula = app(NotulaService::class)->untukTriwulan(2026, 1);
        Capaian::create(['iku_id' => $ikuA->id, 'periode_id' => $notula->periode_id, 'status' => Capaian::STATUS_DIVERIFIKASI]);
        Capaian::create(['iku_id' => $ikuB->id, 'periode_id' => $notula->periode_id, 'status' => Capaian::STATUS_DIVERIFIKASI]);

        $data = app(NotulaService::class)->kumpulkanDataBagianSatu($notula);

        $this->assertSame(100.0, $data['rataCapaianTw']);
        $this->assertSame(12.5, $data['rataCapaianPk']);
    }

    /**
     * Smoke test triwulan I (tidak punya triwulan sebelumnya di tahun yang sama) --
     * pastikan susunBagianSatu() tidak melempar galat sepanjang seluruh alur generate
     * template -> konversi -> simpan.
     */
    public function test_bagian1_tw_pertama_tidak_melempar_galat(): void
    {
        $this->fakeKonversiBagian1KeXmlMentah();

        MasterIku::create(['kode' => '9006', 'indikator' => 'Uji IKU Enam', 'sasaran' => 'Sasaran Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        $notula = app(NotulaService::class)->untukTriwulan(2026, 1);
        $html = app(NotulaService::class)->susunBagianSatu($notula);

        $this->assertNotSame('', trim($html));
        $this->assertSame($html, Notula::first()->bagian1_html);
    }

    /**
     * susunBagianSatu() harus benar-benar menjalankan seluruh pipa: generate docx dari
     * template resmi -> konversi ke HTML -> simpan ke bagian1_html -> bersihkan berkas
     * docx sementara (tidak dibiarkan menumpuk di storage/app/private).
     */
    public function test_susun_bagian_satu_membersihkan_docx_sementara_setelah_konversi(): void
    {
        $this->fakeKonversiBagian1KeXmlMentah();

        MasterIku::create(['kode' => '9007', 'indikator' => 'Uji IKU Tujuh', 'sasaran' => 'Sasaran Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        $notula = app(NotulaService::class)->untukTriwulan(2026, 2);
        app(NotulaService::class)->susunBagianSatu($notula);

        $docxPath = storage_path("app/private/notula/{$notula->id}/bagian1-sementara/bagian1.docx");
        $this->assertFileDoesNotExist($docxPath);
    }

    public function test_bagian1_menampilkan_volume_ro_dasar_hitung_dan_catatan_bila_terisi(): void
    {
        $this->fakeKonversiBagian1KeXmlMentah();

        $iku = MasterIku::create([
            'kode' => '9005',
            'indikator' => 'Uji IKU Lima',
            'sasaran' => 'Sasaran Uji',
            'tim' => 'Uji',
            'penanggung_jawab' => 'A',
            'dasar_hitung' => 'Jumlah publikasi tepat waktu dibagi jumlah seluruh publikasi',
            'basis_data' => 'Data internal Fungsi Statistik',
        ]);

        $periode = Periode::create([
            'tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false,
        ]);

        $kegiatan = Kegiatan::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan uji Bagian I',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        $kegiatan->rincianOutput()->create([
            'uraian' => 'Publikasi/Laporan Statistik Uji',
            'volume_ro' => '3 publikasi',
            'progres_persen' => 75,
        ]);

        Capaian::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'status' => Capaian::STATUS_DIVERIFIKASI,
            'catatan' => 'Penjelasan tambahan hasil rapat',
        ]);

        $notula = app(NotulaService::class)->untukTriwulan(2026, 2);
        $html = app(NotulaService::class)->susunBagianSatu($notula);

        $this->assertStringContainsString('Publikasi/Laporan Statistik Uji', $html);
        $this->assertStringContainsString('3 publikasi', $html);
        $this->assertStringContainsString('75,00%', $html);
        $this->assertStringContainsString('Jumlah publikasi tepat waktu dibagi jumlah seluruh publikasi', $html);
        $this->assertStringContainsString('Data internal Fungsi Statistik', $html);
        $this->assertStringContainsString('Penjelasan tambahan hasil rapat', $html);
    }
}
