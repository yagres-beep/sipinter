<?php

namespace Tests\Feature;

use App\Livewire\DasborCapaian;
use App\Models\CapaianTahunan;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\NilaiSakip;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dasbor Capaian — Predikat SAKIP (info saja, Rumus 2.1) dan agregat Bagian 3
 * (capaianKinerjaPerTriwulan/capaianSetahunPerTriwulan/capaianPerSasaran/
 * dataRekap()). Rumus Penilaian Kinerja Organisasi (PKO) TIDAK ADA di sini —
 * dihapus dari cakupan aplikasi ini.
 */
class DasborCapaianTest extends TestCase
{
    use RefreshDatabase;

    protected function loginSebagaiTimSakip(): User
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $user = User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip-dasbor@example.test', 'email' => 'sakip-dasbor@example.test',
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

    public function test_kepala_tidak_bisa_mengubah_nilai_sakip(): void
    {
        $peran = Role::create(['nama' => 'Kepala']);
        $kepala = User::create([
            'nama' => 'Kepala Uji', 'username' => 'kepala-dasbor@example.test', 'email' => 'kepala-dasbor@example.test',
            'password' => 'password', 'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($kepala);

        Livewire::test(DasborCapaian::class)
            ->set('tahun', 2026)
            ->set('nilaiSakipInput', 65)
            ->call('simpanNilaiSakip')
            ->assertForbidden();

        $this->assertDatabaseMissing('nilai_sakip', ['tahun' => 2026]);
    }

    public function test_info_sakip_menampilkan_predikat_tanpa_perhitungan_pko(): void
    {
        $this->loginSebagaiTimSakip();

        NilaiSakip::create(['tahun' => 2026, 'nilai' => 65]); // -> Predikat "B/Baik".

        $component = Livewire::test(DasborCapaian::class)->set('tahun', 2026);
        $infoSakip = $component->viewData('infoSakip');

        $this->assertSame(65.0, $infoSakip['nilai_sakip']);
        $this->assertSame('B/Baik', $infoSakip['predikat_sakip']);
    }

    public function test_info_sakip_null_bila_belum_diisi(): void
    {
        $this->loginSebagaiTimSakip();

        $component = Livewire::test(DasborCapaian::class)->set('tahun', 2026);
        $infoSakip = $component->viewData('infoSakip');

        $this->assertNull($infoSakip['nilai_sakip']);
        $this->assertNull($infoSakip['predikat_sakip']);
    }

    public function test_capaian_kinerja_per_triwulan_rata_rata_seluruh_iku(): void
    {
        $this->loginSebagaiTimSakip();

        $iku1 = MasterIku::create(['kode' => '1136', 'indikator' => 'Uji TW A', 'tim' => 'Uji']);
        $iku2 = MasterIku::create(['kode' => '1137', 'indikator' => 'Uji TW B', 'tim' => 'Uji']);

        // TW II: iku1 capaian 100% (alokasi=50,realisasi=50), iku2 capaian 50%
        // (alokasi=100,realisasi=50).
        CapaianTahunan::create(['iku_id' => $iku1->id, 'tahun' => 2026, 'alokasi_tw2' => 50, 'realisasi_tw2' => 50]);
        CapaianTahunan::create(['iku_id' => $iku2->id, 'tahun' => 2026, 'alokasi_tw2' => 100, 'realisasi_tw2' => 50]);

        $component = Livewire::test(DasborCapaian::class)->set('tahun', 2026);
        $perTriwulan = $component->viewData('capaianKinerjaPerTriwulan');

        // (100+50)/2 = 75.
        $this->assertEqualsWithDelta(75.0, $perTriwulan[2], 0.01);
        // TW I belum ada data sama sekali (alokasi & realisasi 0 untuk keduanya) -> "-".
        $this->assertSame('-', $perTriwulan[1]);
    }

    public function test_capaian_setahun_per_triwulan_dibagi_jumlah_total_indikator_iku(): void
    {
        $this->loginSebagaiTimSakip();

        // Target Tahunan=200 untuk keduanya (kini SELALU = alokasi_tw4, lihat
        // CapaianTahunan::targetTahunan()). iku1: realisasi_tw2 kumulatif=100 -> 50%.
        // iku2: belum ada realisasi sama sekali -> "-".
        $iku1 = MasterIku::create(['kode' => '2001', 'indikator' => 'Uji Setahun A', 'tim' => 'Uji']);
        $iku2 = MasterIku::create(['kode' => '2002', 'indikator' => 'Uji Setahun B', 'tim' => 'Uji']);

        CapaianTahunan::create(['iku_id' => $iku1->id, 'tahun' => 2026, 'alokasi_tw4' => 200, 'realisasi_tw2' => 100]);
        CapaianTahunan::create(['iku_id' => $iku2->id, 'tahun' => 2026, 'alokasi_tw4' => 200]);

        $component = Livewire::test(DasborCapaian::class)->set('tahun', 2026);
        $perTriwulan = $component->viewData('capaianSetahunPerTriwulan');

        // (50 + 0) / 2 = 25 -- pembagi jumlah TOTAL indikator IKU (2), bukan jumlah nilai valid (1).
        $this->assertEqualsWithDelta(25.0, $perTriwulan[2], 0.01);
        // TW I: kedua IKU belum ada realisasi sama sekali -> semua "-" -> "-".
        $this->assertSame('-', $perTriwulan[1]);
    }

    public function test_capaian_per_sasaran_mengelompokkan_dan_mengabaikan_nilai_belum_dinilai(): void
    {
        $this->loginSebagaiTimSakip();

        $ikuA = MasterIku::create(['kode' => '1139', 'indikator' => 'Uji Sasaran A1', 'tim' => 'Uji', 'sasaran' => 'Sasaran Satu']);
        $ikuB = MasterIku::create(['kode' => '1140', 'indikator' => 'Uji Sasaran A2', 'tim' => 'Uji', 'sasaran' => 'Sasaran Satu']);
        $ikuTanpaSasaran = MasterIku::create(['kode' => '1141', 'indikator' => 'Uji Tanpa Sasaran', 'tim' => 'Uji']);

        CapaianTahunan::create(['iku_id' => $ikuA->id, 'tahun' => 2026, 'alokasi_tw2' => 100, 'realisasi_tw2' => 100]); // 100%
        CapaianTahunan::create(['iku_id' => $ikuB->id, 'tahun' => 2026, 'alokasi_tw2' => 100, 'realisasi_tw2' => 0]); // "-" (belum ada realisasi)
        CapaianTahunan::create(['iku_id' => $ikuTanpaSasaran->id, 'tahun' => 2026, 'alokasi_tw2' => 100, 'realisasi_tw2' => 0]); // "-"

        $component = Livewire::test(DasborCapaian::class)->set('tahun', 2026)->set('triwulan', 2);
        $perSasaran = $component->viewData('capaianPerSasaran');

        // Sasaran Satu: hanya ikuA (100%) yang valid -- ikuB "-" diabaikan.
        $this->assertEqualsWithDelta(100.0, $perSasaran['Sasaran Satu'], 0.01);
        // IKU tanpa sasaran terisi masuk label "Tanpa Sasaran"; capaiannya sendiri "-".
        $this->assertSame('-', $perSasaran['Tanpa Sasaran']);
    }

    public function test_data_rekap_menghitung_capaian_untuk_semua_indikator(): void
    {
        $this->loginSebagaiTimSakip();

        $periode = Periode::create(['tahun' => 2026, 'triwulan' => 2, 'bulan' => 4, 'bulan_ke' => 4]);

        $iku = MasterIku::create(['kode' => '3001', 'indikator' => 'Uji Rekap IKU', 'tim' => 'Uji']);

        CapaianTahunan::create(['iku_id' => $iku->id, 'tahun' => 2026, 'alokasi_tw4' => 100, 'alokasi_tw2' => 100, 'realisasi_tw2' => 100]);

        Kegiatan::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id, 'jenis' => 'bukan_survei_sensus',
            'uraian_kegiatan' => 'Kegiatan IKU', 'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        $component = Livewire::test(DasborCapaian::class)->set('tahun', 2026)->set('triwulan', 2);
        $rekap = $component->viewData('rekap');

        $baris = $rekap->keyBy(fn ($r) => $r['iku']->id);

        $this->assertSame(100.0, $baris[$iku->id]['persentase']);
        $this->assertSame(100.0, $baris[$iku->id]['persentase_setahun']);
    }

    /**
     * Rekap per IKU memakai rumus yang sama dengan kartu rata-rata, Notula, dan LAKIN
     * (CapaianTahunan::capaianTriwulanan()/capaianSetahun()) -- termasuk rumus kustom
     * IKU Non % dan pembilang/penyebut IKU %.
     */
    public function test_data_rekap_sama_dengan_rumus_capaian_tahunan_termasuk_rumus_kustom_dan_rasio(): void
    {
        $this->loginSebagaiTimSakip();

        $periode = Periode::create(['tahun' => 2026, 'triwulan' => 2, 'bulan' => 5, 'bulan_ke' => 5]);

        // Non % dengan rumus kustom "lebih kecil lebih baik": alokasi/realisasi x 100.
        $kustom = MasterIku::create(['kode' => '3101', 'indikator' => 'Uji Rumus Kustom', 'tim' => 'Uji', 'formula_capaian' => 'alokasi / realisasi * 100']);
        CapaianTahunan::create(['iku_id' => $kustom->id, 'tahun' => 2026, 'alokasi_tw2' => 50, 'alokasi_tw4' => 50, 'realisasi_tw2' => 100]);

        // % : N = 10, 4 rincian terealisasi s.d. TW II.
        $rasio = MasterIku::create(['kode' => '3102', 'indikator' => 'Uji Rasio', 'tim' => 'Uji', 'metode_capaian' => MasterIku::METODE_RASIO]);
        CapaianTahunan::create([
            'iku_id' => $rasio->id, 'tahun' => 2026,
            'x_alokasi_tw2' => 5, 'x_alokasi_tw4' => 10,
            'y_alokasi_tw1' => 10, 'y_alokasi_tw2' => 10, 'y_alokasi_tw3' => 10, 'y_alokasi_tw4' => 10,
            'y_realisasi_tw1' => 10, 'y_realisasi_tw2' => 10, 'y_realisasi_tw3' => 10, 'y_realisasi_tw4' => 10,
            'x_realisasi_tw1' => 2, 'x_realisasi_tw2' => 2,
        ]);

        foreach ([$kustom, $rasio] as $iku) {
            Kegiatan::create([
                'iku_id' => $iku->id, 'periode_id' => $periode->id, 'jenis' => 'bukan_survei_sensus',
                'uraian_kegiatan' => 'Kegiatan '.$iku->kode, 'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
            ]);
        }

        $baris = Livewire::test(DasborCapaian::class)->set('tahun', 2026)->set('triwulan', 2)
            ->viewData('rekap')->keyBy(fn ($r) => $r['iku']->id);

        foreach ([$kustom, $rasio] as $iku) {
            $ct = CapaianTahunan::with('masterIku')->where('iku_id', $iku->id)->first();
            $this->assertSame($ct->capaianTriwulanan(2), $baris[$iku->id]['persentase'], $iku->kode);
            $this->assertSame($ct->capaianSetahun(2), $baris[$iku->id]['persentase_setahun'], $iku->kode);
        }

        $this->assertSame(50.0, $baris[$kustom->id]['persentase'], 'rumus kustom dipakai, bukan realisasi/target');

        // TW I realisasinya 0: rumus kustom "alokasi / realisasi" membagi nol ->
        // "-" (belum dinilai), bukan galat halaman Dasbor.
        $this->assertNull(CapaianTahunan::with('masterIku')->where('iku_id', $kustom->id)->first()->capaianTriwulanan(1));
        $this->assertSame(80.0, $baris[$rasio->id]['persentase'], '40% / 50% = 80%');
    }
}
