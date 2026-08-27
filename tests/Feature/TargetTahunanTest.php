<?php

namespace Tests\Feature;

use App\Livewire\TargetTahunan;
use App\Models\CapaianTahunan;
use App\Models\MasterIku;
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

    public function test_simpan_target_tahunan_rasio_menyimpan_kolom_x_y(): void
    {
        $this->loginSebagaiTimSakip();
        $iku = MasterIku::create([
            'kode' => 'UJI-101', 'indikator' => 'IKU %', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'metode_capaian' => MasterIku::METODE_RASIO,
        ]);

        Livewire::test(TargetTahunan::class)
            ->set('tahun', 2026)
            ->set("nilai.{$iku->id}.x_target", 3)
            ->set("nilai.{$iku->id}.y_target", 4)
            ->call('simpan')
            ->assertHasNoErrors();

        $ct = CapaianTahunan::where('iku_id', $iku->id)->where('tahun', 2026)->first();
        $this->assertEquals(3, $ct->x_target);
        $this->assertEquals(4, $ct->y_target);
        $this->assertNull($ct->target_tahunan);
    }

    /**
     * Alokasi X TW I-IV untuk IKU rasio diisi SEKALI di sini (bukan diketik ulang
     * tiap sesi Verifikasi Capaian). Alokasi Y HANYA diminta SEKALI sebagai total
     * (satu input, bukan 4 kotak identik per TW -- nilainya sama sepanjang tahun)
     * -- diratakan jadi TW I & TW II-IV otomatis 0 di kolom tersimpan, supaya
     * kumulatifnya (alokasiKumulatif()) tetap konstan sepanjang tahun. Realisasi Y
     * HARUS otomatis mengikuti pola yang sama (bukan isian terpisah), supaya
     * CapaianTahunan::realisasiKumulatif() tetap menghitung Y yang benar walau
     * Tim SAKIP di Verifikasi Capaian cuma mengisi Realisasi X.
     */
    public function test_simpan_alokasi_tw_rasio_menyalin_realisasi_y_dari_alokasi_y(): void
    {
        $this->loginSebagaiTimSakip();
        $iku = MasterIku::create([
            'kode' => 'UJI-104', 'indikator' => 'IKU % Alokasi TW', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'metode_capaian' => MasterIku::METODE_RASIO,
        ]);

        Livewire::test(TargetTahunan::class)
            ->set('tahun', 2026)
            ->set("nilai.{$iku->id}.x_alokasi_tw1", 1)
            ->set("nilai.{$iku->id}.x_alokasi_tw2", 1)
            ->set("nilai.{$iku->id}.x_alokasi_tw3", 2)
            ->set("nilai.{$iku->id}.x_alokasi_tw4", 3)
            ->set("nilai.{$iku->id}.y_alokasi_tw1", 3)
            ->call('simpan')
            ->assertHasNoErrors();

        $ct = CapaianTahunan::where('iku_id', $iku->id)->where('tahun', 2026)->first();

        $this->assertEquals(1, $ct->x_alokasi_tw1);
        $this->assertEquals(2, $ct->x_alokasi_tw3);
        $this->assertEquals(3, $ct->y_alokasi_tw1);
        $this->assertEquals(0, $ct->y_alokasi_tw2);
        $this->assertEquals(0, $ct->y_alokasi_tw3);
        $this->assertEquals(0, $ct->y_alokasi_tw4);

        // Realisasi Y HARUS mengikuti pola Alokasi Y yang sama (3 di TW I, 0 di
        // sisanya) -- kumulatifnya tetap 3 sepanjang tahun -- tanpa pernah diisi
        // langsung di sini maupun di Verifikasi Capaian.
        $this->assertEquals(3, $ct->y_realisasi_tw1);
        $this->assertEquals(0, $ct->y_realisasi_tw2);
        $this->assertEquals(0, $ct->y_realisasi_tw3);
        $this->assertEquals(0, $ct->y_realisasi_tw4);
    }

    /**
     * Jenis Nilai (%/Non %) IKU bisa DIREVISI langsung dari halaman Target Tahunan
     * (dropdown per baris) -- tersimpan balik ke MasterIku::metode_capaian, bukan
     * cuma efek lokal di halaman ini, supaya Verifikasi Capaian & Notula ikut
     * memakai jenis yang baru.
     */
    public function test_ubah_jenis_nilai_dari_langsung_ke_rasio_tersimpan_ke_master_iku(): void
    {
        $this->loginSebagaiTimSakip();
        $iku = MasterIku::create([
            'kode' => 'UJI-105', 'indikator' => 'IKU Uji Revisi Jenis', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'metode_capaian' => MasterIku::METODE_LANGSUNG,
        ]);

        Livewire::test(TargetTahunan::class)
            ->set('tahun', 2026)
            ->set("jenisNilai.{$iku->id}", MasterIku::METODE_RASIO)
            ->set("nilai.{$iku->id}.x_target", 5)
            ->set("nilai.{$iku->id}.y_target", 10)
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
