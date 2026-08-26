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
        $this->loginSebagaiTimSakip();

        Livewire::test(KompilasiNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->call('susunUlangOtomatis')
            ->assertDispatched('bagian1-diperbarui');
    }

    public function test_simpan_suntingan_bagian1_menyimpan_konten_dari_editor(): void
    {
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
        $this->loginSebagaiTimSakip();

        Livewire::test(KompilasiNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->set('triwulan', 2)
            ->assertDispatched('bagian1-diperbarui');
    }

    public function test_simpan_detail_rapat_menyimpan_dan_tampil_di_bagian1(): void
    {
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

    public function test_penomoran_iku_pada_tabel_capaian_dimulai_ulang_per_sasaran(): void
    {
        MasterIku::create(['kode' => '9001', 'indikator' => 'Uji IKU Satu', 'sasaran' => 'Sasaran Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        MasterIku::create(['kode' => '9002', 'indikator' => 'Uji IKU Dua', 'sasaran' => 'Sasaran Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        $notula = app(NotulaService::class)->untukTriwulan(2026, 3);
        $html = app(NotulaService::class)->susunBagianSatu($notula);

        $this->assertStringContainsString('<td>1</td>', $html);
        $this->assertStringContainsString('<td>2</td>', $html);
        $this->assertStringNotContainsString('<td>9001</td>', $html);
        $this->assertStringNotContainsString('<td>9002</td>', $html);
        $this->assertStringContainsString('9001 — Uji IKU Satu', $html);
        $this->assertStringContainsString('9002 — Uji IKU Dua', $html);
    }

    public function test_ringkasan_capaian_dihitung_dari_rekap_dan_mengecualikan_iku_tanpa_nilai(): void
    {
        $ikuBernilai = MasterIku::create(['kode' => '9003', 'indikator' => 'Uji IKU Tiga', 'sasaran' => 'Sasaran Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        MasterIku::create(['kode' => '9004', 'indikator' => 'Uji IKU Empat Tanpa Nilai', 'sasaran' => 'Sasaran Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        CapaianTahunan::create([
            'iku_id' => $ikuBernilai->id,
            'tahun' => 2026,
            'target_tahunan' => 100,
            'alokasi_tw1' => 25,
            'realisasi_tw1' => 25,
        ]);

        $notula = app(NotulaService::class)->untukTriwulan(2026, 1);
        $html = app(NotulaService::class)->susunBagianSatu($notula);

        $this->assertStringContainsString('<b>100 persen</b>', $html);
        $this->assertStringContainsString('<b>25 persen</b>', $html);
    }

    public function test_bagian1_menampilkan_volume_ro_dasar_hitung_dan_catatan_bila_terisi(): void
    {
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
            'rincian_output' => 'Publikasi/Laporan Statistik Uji',
            'volume_ro' => '3 publikasi',
            'progres_persen' => 75,
        ]);

        Capaian::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
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

    public function test_bagian1_pakai_uraian_kegiatan_sebagai_rincian_output_bila_belum_diisi_tim_sakip(): void
    {
        $iku = MasterIku::create([
            'kode' => '9005a', 'indikator' => 'Uji IKU Lima A', 'sasaran' => 'Sasaran Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
        ]);

        $periode = Periode::create([
            'tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false,
        ]);

        Kegiatan::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan uji tanpa rincian output',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        $notula = app(NotulaService::class)->untukTriwulan(2026, 2);
        $html = app(NotulaService::class)->susunBagianSatu($notula);

        $this->assertStringContainsString('Kegiatan uji tanpa rincian output', $html);
    }

    public function test_bagian1_tw_pertama_tidak_mencoba_tautan_bukti_dukung_tw_sebelumnya(): void
    {
        MasterIku::create(['kode' => '9006', 'indikator' => 'Uji IKU Enam', 'sasaran' => 'Sasaran Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        $notula = app(NotulaService::class)->untukTriwulan(2026, 1);

        // Triwulan I tidak punya triwulan sebelumnya (tahun yang sama) — pastikan
        // susunBagianSatu() tidak melempar galat saat mencoba menyusunnya.
        $html = app(NotulaService::class)->susunBagianSatu($notula);

        $this->assertStringContainsString('Tautan Bukti Dukung Tindak Lanjut Triwulan Sebelumnya', $html);
    }

    public function test_bagian1_indikator_sakip_dan_berakhlak_memakai_format_khusus(): void
    {
        MasterIku::create(['kode' => '9008', 'indikator' => 'Nilai SAKIP oleh Inspektorat', 'sasaran' => 'Dukungan Manajemen', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        MasterIku::create(['kode' => '9009', 'indikator' => 'Indeks Implementasi BerAKHLAK', 'sasaran' => 'Dukungan Manajemen', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        $notula = app(NotulaService::class)->untukTriwulan(2026, 2);
        $html = app(NotulaService::class)->susunBagianSatu($notula);

        // SAKIP: pertanyaan analisis khusus + tabel Indikator Proksi, BUKAN tabel
        // Rincian Output biasa.
        $this->assertStringContainsString('Jelaskan mengenai persentase monitoring capaian kinerja triwulanan yang terlaksana tepat waktu', $html);
        $this->assertStringContainsString('Indikator Proksi', $html);

        // BerAKHLAK: pertanyaan analisis khusus, TANPA tabel sama sekali.
        $this->assertStringContainsString('Jelaskan mengenai Persentase kegiatan untuk mengoptimalkan implementasi BerAKHLAK yang terlaksana sesuai rencana', $html);

        // Keduanya tidak ikut memicu tabel "Realisasi Volume RO dan Progress
        // Pelaksanaan Kegiatan" bawaan (khusus indikator biasa).
        $this->assertStringNotContainsString('Realisasi Volume RO dan Progress Pelaksanaan Kegiatan', $html);
    }
}
