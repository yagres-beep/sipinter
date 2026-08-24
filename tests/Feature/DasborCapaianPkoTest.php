<?php

namespace Tests\Feature;

use App\Livewire\DasborCapaian;
use App\Models\CapaianTahunan;
use App\Models\MasterIku;
use App\Models\NilaiSakip;
use App\Models\PengaturanCapaian;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Penilaian Kinerja Organisasi (PKO) di Dasbor Capaian — lihat App\Livewire\
 * DasborCapaian::hitungPko(), yang menggabungkan Nilai SAKIP (App\Models\NilaiSakip)
 * dengan Capaian Setahun TW IV tiap IKU (App\Models\CapaianTahunan::capaianSetahun()).
 */
class DasborCapaianPkoTest extends TestCase
{
    use RefreshDatabase;

    protected function loginSebagaiTimSakip(): User
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $user = User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip-pko@example.test', 'email' => 'sakip-pko@example.test',
            'password' => 'password', 'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_simpan_nilai_sakip_tersimpan_per_tahun(): void
    {
        $this->loginSebagaiTimSakip();

        Livewire::test(DasborCapaian::class)
            ->set('tahun', 2026)
            ->set('nilaiSakipInput', 65)
            ->call('simpanNilaiSakip')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('nilai_sakip', ['tahun' => 2026, 'nilai' => 65]);
    }

    public function test_hitung_pko_menggabungkan_nilai_sakip_dan_capaian_setahun_tw4(): void
    {
        $this->loginSebagaiTimSakip();

        $iku = MasterIku::create(['kode' => '1131', 'indikator' => 'Uji PKO', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        // Capaian Setahun TW IV = 120% (target_tahunan=100, realisasi_tw4=120,
        // metode 'langsung' default) — akan dibatasi batas_normalisasi_pko.
        CapaianTahunan::create([
            'iku_id' => $iku->id, 'tahun' => 2026,
            'target_tahunan' => 100, 'realisasi_tw4' => 120,
        ]);

        NilaiSakip::create(['tahun' => 2026, 'nilai' => 65]); // -> Predikat "B/Baik" -> koreksi 15%.
        PengaturanCapaian::ambil()->update(['batas_normalisasi_pko' => 110]);
        PengaturanCapaian::lupakanCache();

        $component = Livewire::test(DasborCapaian::class)->set('tahun', 2026);

        // Normalisasi = min(120,110) = 110. Nilai Akhir = 110*(100%-15%) = 93.5.
        $pko = $component->viewData('pko');

        $this->assertSame('B/Baik', $pko['predikat_sakip']);
        $this->assertSame(15.0, $pko['koreksi_persen']);
        $this->assertEqualsWithDelta(93.5, $pko['total_capaian_pk'], 0.01);
        $this->assertEqualsWithDelta(93.5, $pko['rata_rata_capaian_pk'], 0.01);
        $this->assertSame('BAIK', $pko['predikat_pko']);
        $this->assertSame(1, $pko['jumlah_iku_dihitung']);
    }

    public function test_hitung_pko_pakai_capaian_setahun_tw4_walau_iku_berjenis_triwulanan(): void
    {
        $this->loginSebagaiTimSakip();

        // IKU berjenis "Triwulanan" — sesuai sheet resmi, kolom AJ (Normalisasi
        // Capaian PK) TETAP merujuk Capaian Terhadap Target Setahun TW IV untuk
        // jenis ini juga (bukan Capaian Terhadap Target Triwulanan berjalan).
        $iku = MasterIku::create([
            'kode' => '1133', 'indikator' => 'Uji PKO Triwulanan', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'jenis_periode' => MasterIku::JENIS_PERIODE_TRIWULANAN,
        ]);

        CapaianTahunan::create([
            'iku_id' => $iku->id, 'tahun' => 2026,
            'target_tahunan' => 200,
            'alokasi_tw1' => 20, 'alokasi_tw2' => 30,
            'realisasi_tw1' => 20, 'realisasi_tw2' => 30,
        ]);
        // Capaian Terhadap Target Triwulanan TW II = 50/50*100 = 100% (kalau basisnya
        // SALAH pakai ini, Normalisasi = 100). Capaian Terhadap Target Setahun TW IV =
        // 50/200*100 = 25% (basis yang BENAR sesuai sheet resmi) -> Normalisasi = 25.

        $component = Livewire::test(DasborCapaian::class)->set('tahun', 2026)->set('triwulan', 2);
        $pko = $component->viewData('pko');

        $this->assertSame(1, $pko['jumlah_iku_dihitung']);
        $this->assertEqualsWithDelta(25.0, $pko['rata_rata_capaian_pk'], 0.01);
    }

    public function test_hitung_pko_mengecualikan_iku_berjenis_proksi(): void
    {
        $this->loginSebagaiTimSakip();

        $iku = MasterIku::create([
            'kode' => '1134', 'indikator' => 'Uji PKO IKU', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'jenis_iku' => MasterIku::JENIS_IKU,
        ]);
        $proksi = MasterIku::create([
            'kode' => '1135', 'indikator' => 'Uji PKO Proksi', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'jenis_iku' => MasterIku::JENIS_PROKSI,
        ]);

        // Kedua-duanya punya Capaian Setahun TW IV 100% (target=100, realisasi=100)
        // supaya bedanya HANYA jenis_iku, bukan datanya.
        CapaianTahunan::create(['iku_id' => $iku->id, 'tahun' => 2026, 'target_tahunan' => 100, 'realisasi_tw4' => 100]);
        CapaianTahunan::create(['iku_id' => $proksi->id, 'tahun' => 2026, 'target_tahunan' => 100, 'realisasi_tw4' => 100]);

        $component = Livewire::test(DasborCapaian::class)->set('tahun', 2026);
        $pko = $component->viewData('pko');

        // Hanya IKU "iku" yang dihitung -- Proksi sama sekali tidak ikut menyumbang
        // Total/Rata-rata Capaian PK, walau datanya identik.
        $this->assertSame(1, $pko['jumlah_iku_dihitung']);
        $this->assertEqualsWithDelta(100.0, $pko['total_capaian_pk'], 0.01);
        $this->assertEqualsWithDelta(100.0, $pko['rata_rata_capaian_pk'], 0.01);
    }

    public function test_hitung_pko_melewati_iku_tanpa_capaian_setahun_tw4(): void
    {
        $this->loginSebagaiTimSakip();

        // IKU ada tapi belum punya baris CapaianTahunan sama sekali tahun ini.
        MasterIku::create(['kode' => '1132', 'indikator' => 'Uji Belum Ada Data', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        $component = Livewire::test(DasborCapaian::class)->set('tahun', 2026);
        $pko = $component->viewData('pko');

        $this->assertNull($pko['nilai_sakip']);
        $this->assertNull($pko['predikat_sakip']);
        $this->assertNull($pko['rata_rata_capaian_pk']);
        $this->assertNull($pko['predikat_pko']);
        $this->assertSame(0, $pko['jumlah_iku_dihitung']);
    }

    public function test_capaian_kinerja_per_triwulan_rata_rata_iku_mengecualikan_proksi(): void
    {
        $this->loginSebagaiTimSakip();

        $iku1 = MasterIku::create(['kode' => '1136', 'indikator' => 'Uji TW A', 'tim' => 'Uji', 'penanggung_jawab' => 'A', 'jenis_iku' => MasterIku::JENIS_IKU]);
        $iku2 = MasterIku::create(['kode' => '1137', 'indikator' => 'Uji TW B', 'tim' => 'Uji', 'penanggung_jawab' => 'A', 'jenis_iku' => MasterIku::JENIS_IKU]);
        $proksi = MasterIku::create(['kode' => '1138', 'indikator' => 'Uji TW Proksi', 'tim' => 'Uji', 'penanggung_jawab' => 'A', 'jenis_iku' => MasterIku::JENIS_PROKSI]);

        // TW II: iku1 capaian 100% (alokasi=50,realisasi=50), iku2 capaian 50%
        // (alokasi=100,realisasi=50). Proksi capaian 120% tapi HARUS diabaikan.
        CapaianTahunan::create(['iku_id' => $iku1->id, 'tahun' => 2026, 'alokasi_tw2' => 50, 'realisasi_tw2' => 50]);
        CapaianTahunan::create(['iku_id' => $iku2->id, 'tahun' => 2026, 'alokasi_tw2' => 100, 'realisasi_tw2' => 50]);
        CapaianTahunan::create(['iku_id' => $proksi->id, 'tahun' => 2026, 'alokasi_tw2' => 10, 'realisasi_tw2' => 1000]);

        $component = Livewire::test(DasborCapaian::class)->set('tahun', 2026);
        $perTriwulan = $component->viewData('capaianKinerjaPerTriwulan');

        // (100+50)/2 = 75, Proksi tidak ikut menyumbang.
        $this->assertEqualsWithDelta(75.0, $perTriwulan[2], 0.01);
        // TW I belum ada data sama sekali (alokasi & realisasi 0 untuk keduanya) -> "-".
        $this->assertSame('-', $perTriwulan[1]);
    }

    public function test_capaian_per_sasaran_mengelompokkan_dan_mengecualikan_proksi(): void
    {
        $this->loginSebagaiTimSakip();

        $ikuA = MasterIku::create(['kode' => '1139', 'indikator' => 'Uji Sasaran A1', 'tim' => 'Uji', 'penanggung_jawab' => 'A', 'sasaran' => 'Sasaran Satu', 'jenis_iku' => MasterIku::JENIS_IKU]);
        $ikuB = MasterIku::create(['kode' => '1140', 'indikator' => 'Uji Sasaran A2', 'tim' => 'Uji', 'penanggung_jawab' => 'A', 'sasaran' => 'Sasaran Satu', 'jenis_iku' => MasterIku::JENIS_IKU]);
        $ikuTanpaSasaran = MasterIku::create(['kode' => '1141', 'indikator' => 'Uji Tanpa Sasaran', 'tim' => 'Uji', 'penanggung_jawab' => 'A', 'jenis_iku' => MasterIku::JENIS_IKU]);
        $proksi = MasterIku::create(['kode' => '1142', 'indikator' => 'Uji Sasaran Proksi', 'tim' => 'Uji', 'penanggung_jawab' => 'A', 'sasaran' => 'Sasaran Satu', 'jenis_iku' => MasterIku::JENIS_PROKSI]);

        CapaianTahunan::create(['iku_id' => $ikuA->id, 'tahun' => 2026, 'alokasi_tw2' => 100, 'realisasi_tw2' => 100]); // 100%
        CapaianTahunan::create(['iku_id' => $ikuB->id, 'tahun' => 2026, 'alokasi_tw2' => 100, 'realisasi_tw2' => 0]); // "-" (belum ada realisasi)
        CapaianTahunan::create(['iku_id' => $ikuTanpaSasaran->id, 'tahun' => 2026, 'alokasi_tw2' => 100, 'realisasi_tw2' => 0]); // "-"
        CapaianTahunan::create(['iku_id' => $proksi->id, 'tahun' => 2026, 'alokasi_tw2' => 10, 'realisasi_tw2' => 1000]);

        $component = Livewire::test(DasborCapaian::class)->set('tahun', 2026)->set('triwulan', 2);
        $perSasaran = $component->viewData('capaianPerSasaran');

        // Sasaran Satu: hanya ikuA (100%) yang valid -- ikuB "-" diabaikan, Proksi dikecualikan.
        $this->assertEqualsWithDelta(100.0, $perSasaran['Sasaran Satu'], 0.01);
        // IKU tanpa sasaran terisi masuk label "Tanpa Sasaran"; capaiannya sendiri "-".
        $this->assertSame('-', $perSasaran['Tanpa Sasaran']);
    }
}
