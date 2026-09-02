<?php

namespace Tests\Feature;

use App\Livewire\TargetTahunan;
use App\Models\CapaianTahunan;
use App\Models\MasterIku;
use App\Models\RincianN;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Target Tahunan seluruh IKU dalam satu tabel (App\Livewire\TargetTahunan) —
 * dipisah dari App\Livewire\VerifikasiCapaian supaya diisi sekali per tahun di
 * satu tempat, bukan diketik ulang tiap sesi verifikasi bulanan per IKU.
 */
class TargetTahunanTest extends TestCase
{
    use RefreshDatabase;

    protected function loginSebagaiTimSakip(): User
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $user = User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip-target@example.test', 'email' => 'sakip-target@example.test',
            'password' => 'password', 'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_simpan_target_tahunan_langsung_untuk_iku_non_persen(): void
    {
        $this->loginSebagaiTimSakip();
        $iku = MasterIku::create(['kode' => 'UJI-100', 'indikator' => 'IKU Non %', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        Livewire::test(TargetTahunan::class)
            ->set('tahun', 2026)
            ->set("nilai.{$iku->id}.target_tahunan", 75)
            ->call('simpan')
            ->assertHasNoErrors();

        $ct = CapaianTahunan::where('iku_id', $iku->id)->where('tahun', 2026)->first();
        $this->assertEquals(75, $ct->target_tahunan);
    }

    /**
     * Target Tahunan (rasio) TIDAK LAGI diketik terpisah (x_target/y_target lama,
     * tidak ada input-nya lagi) — SELALU diturunkan dari Alokasi Kumulatif TW IV
     * (lihat CapaianTahunan::targetTahunan()).
     */
    public function test_simpan_target_tahunan_rasio_diturunkan_dari_alokasi_tw_empat(): void
    {
        $this->loginSebagaiTimSakip();
        $iku = MasterIku::create([
            'kode' => 'UJI-101', 'indikator' => 'IKU %', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'metode_capaian' => MasterIku::METODE_RASIO,
        ]);

        $component = Livewire::test(TargetTahunan::class)
            ->set('tahun', 2026)
            ->set("nilai.{$iku->id}.x_alokasi_tw4", 3)
            ->call('tambahN', $iku->id)
            ->call('tambahN', $iku->id)
            ->call('tambahN', $iku->id)
            ->call('tambahN', $iku->id);

        $kunciList = array_keys($component->get('rincianN')[$iku->id]);
        foreach ($kunciList as $i => $kunci) {
            $component->set("rincianN.{$iku->id}.{$kunci}.uraian", 'Item '.($i + 1));
        }

        $component->call('simpan')->assertHasNoErrors();

        $ct = CapaianTahunan::where('iku_id', $iku->id)->where('tahun', 2026)->first();
        $this->assertEquals(3, $ct->x_alokasi_tw4);
        $this->assertEquals(4, $ct->y_alokasi_tw4);
        $this->assertEqualsWithDelta(75.0, $ct->targetTahunan(), 0.01);
        $this->assertNull($ct->target_tahunan);
    }

    /**
     * Alokasi X TW I-IV untuk IKU rasio diisi SEKALI di sini (bukan diketik ulang
     * tiap sesi Verifikasi Capaian) sebagai angka KUMULATIF langsung (persis sheet
     * "LK_Kabkot" resmi — lihat CapaianTahunan::alokasiKumulatif(), dibaca apa
     * adanya, TIDAK dijumlahkan lagi). Alokasi Y kini SELALU berasal dari JUMLAH
     * baris Rincian N (App\Models\RincianN, otomatis aktif untuk semua IKU rasio —
     * lihat MasterIku::pakaiRasio()), bukan angka manual — diulang SAMA di keempat
     * TW di kolom tersimpan (Y konstan, dibaca langsung tanpa jumlah). Realisasi Y
     * mengikuti pola SAMA seperti Alokasi Y (diulang sama di keempat TW, BUKAN nol
     * di TW II-IV) — CapaianTahunan::realisasiKumulatif() membaca y_realisasi_tw{n}
     * LANGSUNG per TW untuk 'rasio' (TIDAK dijumlahkan, beda dari x_realisasi yang
     * tetap dijumlah).
     */
    public function test_simpan_alokasi_tw_rasio_menyalin_realisasi_y_dari_alokasi_y(): void
    {
        $this->loginSebagaiTimSakip();
        $iku = MasterIku::create([
            'kode' => 'UJI-104', 'indikator' => 'IKU % Alokasi TW', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'metode_capaian' => MasterIku::METODE_RASIO,
        ]);

        $component = Livewire::test(TargetTahunan::class)
            ->set('tahun', 2026)
            ->call('tambahN', $iku->id)
            ->call('tambahN', $iku->id)
            ->call('tambahN', $iku->id);

        $kunciList = array_keys($component->get('rincianN')[$iku->id]);
        foreach ($kunciList as $i => $kunci) {
            $component->set("rincianN.{$iku->id}.{$kunci}.uraian", 'Item '.($i + 1));
        }

        $component
            ->set("nilai.{$iku->id}.x_alokasi_tw1", 1)
            ->set("nilai.{$iku->id}.x_alokasi_tw2", 1)
            ->set("nilai.{$iku->id}.x_alokasi_tw3", 2)
            ->set("nilai.{$iku->id}.x_alokasi_tw4", 3)
            ->call('simpan')
            ->assertHasNoErrors();

        $ct = CapaianTahunan::where('iku_id', $iku->id)->where('tahun', 2026)->first();

        $this->assertEquals(1, $ct->x_alokasi_tw1);
        $this->assertEquals(2, $ct->x_alokasi_tw3);
        $this->assertEquals(3, $ct->y_alokasi_tw1);
        $this->assertEquals(3, $ct->y_alokasi_tw2);
        $this->assertEquals(3, $ct->y_alokasi_tw3);
        $this->assertEquals(3, $ct->y_alokasi_tw4);

        // Realisasi Y HARUS diulang sama di keempat TW (3), PERSIS pola Alokasi Y
        // di atas -- tanpa pernah diisi langsung di sini maupun di Verifikasi Capaian.
        $this->assertEquals(3, $ct->y_realisasi_tw1);
        $this->assertEquals(3, $ct->y_realisasi_tw2);
        $this->assertEquals(3, $ct->y_realisasi_tw3);
        $this->assertEquals(3, $ct->y_realisasi_tw4);
    }

    /**
     * Jenis Nilai (%/Non %) di Target Tahunan sekarang HANYA mengikuti
     * MasterIku::metode_capaian (read-only) -- satu-satunya cara mengubahnya
     * adalah lewat form Master IKU, bukan lagi dari halaman ini.
     */
    public function test_jenis_nilai_mengikuti_metode_capaian_master_iku(): void
    {
        $this->loginSebagaiTimSakip();
        $iku = MasterIku::create([
            'kode' => 'UJI-105', 'indikator' => 'IKU Uji Jenis', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'metode_capaian' => MasterIku::METODE_RASIO,
        ]);

        Livewire::test(TargetTahunan::class)
            ->set('tahun', 2026)
            ->assertSee('% (X ÷ Y)')
            ->set("nilai.{$iku->id}.x_alokasi_tw4", 5)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertEquals(MasterIku::METODE_RASIO, $iku->fresh()->metode_capaian);
    }

    public function test_simpan_tidak_membuat_baris_untuk_iku_yang_tidak_disentuh(): void
    {
        $this->loginSebagaiTimSakip();
        MasterIku::create(['kode' => 'UJI-102', 'indikator' => 'IKU Belum Disentuh', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        Livewire::test(TargetTahunan::class)
            ->set('tahun', 2026)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('capaian_tahunan', 0);
    }

    public function test_tambah_rincian_n_menentukan_alokasi_y_otomatis(): void
    {
        $this->loginSebagaiTimSakip();
        $iku = MasterIku::create([
            'kode' => 'UJI-106', 'indikator' => 'IKU Uji Rincian N', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'metode_capaian' => MasterIku::METODE_RASIO,
        ]);

        $component = Livewire::test(TargetTahunan::class)->set('tahun', 2026)
            ->call('tambahN', $iku->id)
            ->call('tambahN', $iku->id)
            ->assertHasNoErrors();

        $kunciList = array_keys($component->get('rincianN')[$iku->id]);
        foreach ($kunciList as $i => $kunci) {
            $component->set("rincianN.{$iku->id}.{$kunci}.uraian", 'Item '.($i + 1));
        }

        $component->call('simpan')->assertHasNoErrors();

        $this->assertDatabaseCount('rincian_n', 2);
        $ct = CapaianTahunan::where('iku_id', $iku->id)->where('tahun', 2026)->first();
        $this->assertEquals(2, $ct->y_alokasi_tw1);
        $this->assertEquals(2, $ct->y_alokasi_tw2);
        $this->assertEquals(2, $ct->y_realisasi_tw1);
        $this->assertEquals(2, $ct->y_realisasi_tw2);
    }

    public function test_hapus_rincian_n_yang_tersimpan_menghapus_dari_db(): void
    {
        $this->loginSebagaiTimSakip();
        $iku = MasterIku::create([
            'kode' => 'UJI-107', 'indikator' => 'IKU Uji Hapus Rincian N', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'metode_capaian' => MasterIku::METODE_RASIO,
        ]);
        $n = RincianN::create(['iku_id' => $iku->id, 'tahun' => 2026, 'uraian' => 'Item lama']);

        Livewire::test(TargetTahunan::class)
            ->set('tahun', 2026)
            ->call('hapusN', $iku->id, (string) $n->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('rincian_n', ['id' => $n->id]);
    }

    public function test_ganti_tahun_memuat_nilai_tahun_tsb(): void
    {
        $this->loginSebagaiTimSakip();
        $iku = MasterIku::create(['kode' => 'UJI-103', 'indikator' => 'IKU Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        CapaianTahunan::create(['iku_id' => $iku->id, 'tahun' => 2025, 'target_tahunan' => 60]);
        CapaianTahunan::create(['iku_id' => $iku->id, 'tahun' => 2026, 'target_tahunan' => 90]);

        $component = Livewire::test(TargetTahunan::class)->set('tahun', 2026);
        $this->assertEquals(90, $component->get('nilai')[$iku->id]['target_tahunan']);

        $component->set('tahun', 2025);
        $this->assertEquals(60, $component->get('nilai')[$iku->id]['target_tahunan']);
    }

    public function test_route_master_iku_ditolak_untuk_peran_selain_tim_sakip(): void
    {
        $peranKetuaTim = Role::create(['nama' => 'Ketua Tim']);
        $ketuaTim = User::create([
            'nama' => 'Ketua Tim Uji', 'username' => 'ketua-target@example.test', 'email' => 'ketua-target@example.test',
            'password' => 'password', 'role_id' => $peranKetuaTim->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($ketuaTim);

        $this->get(route('master-iku.index'))->assertForbidden();
    }
}
