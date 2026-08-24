<?php

namespace Tests\Feature;

use App\Livewire\KompilasiNotula;
use App\Models\CapaianTahunan;
use App\Models\MasterIku;
use App\Models\Notula;
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
            ->call('simpanDetailRapat')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('notula', [
            'hari_tanggal' => 'Senin, 12 Oktober 2026',
            'waktu' => '09.00 - 11.00 WITA',
            'tempat' => 'Ruang Rapat BPS',
            'pimpinan_rapat' => 'Kepala BPS',
            'notulis' => 'Notulis Uji',
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
}
