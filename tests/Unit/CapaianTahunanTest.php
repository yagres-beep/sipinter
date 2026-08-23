<?php

namespace Tests\Unit;

use App\Models\CapaianTahunan;
use App\Models\MasterIku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rumus alokasiKumulatif()/realisasiKumulatif()/targetTahunan() — keputusan produk
 * (BEDA dari sheet resmi "LK_Kabkot" yang mengisi tiap kolom TW dengan nilai
 * kumulatif langsung): Tim SAKIP hanya mengisi kontribusi TRIWULAN ITU SENDIRI
 * (bukan kumulatif), supaya tidak perlu melihat/menjumlah isian triwulan
 * sebelumnya — aplikasi yang menjumlahkan otomatis dari TW I s.d. TW yang diminta.
 * Model diinstansiasi langsung (tanpa DB) — masterIku diikat lewat setRelation()
 * supaya pakaiRasio() tidak perlu query. RefreshDatabase tetap dipakai karena
 * capaianTriwulanan()/capaianSetahun() lewat Capaian::hitungPersentase() membaca
 * PengaturanCapaian::ambil() dari DB.
 */
class CapaianTahunanTest extends TestCase
{
    use RefreshDatabase;

    protected function buatCapaianRasio(array $atribut, string $metode = MasterIku::METODE_RASIO): CapaianTahunan
    {
        $iku = new MasterIku(['metode_capaian' => $metode]);

        $capaian = new CapaianTahunan($atribut);
        $capaian->setRelation('masterIku', $iku);

        return $capaian;
    }

    public function test_alokasi_kumulatif_rasio_menjumlahkan_x_dan_y_per_tw(): void
    {
        // Tim SAKIP isi kontribusi TW-nya sendiri: X=[1,0,1,1], Y=[3,0,0,0].
        $capaian = $this->buatCapaianRasio([
            'x_alokasi_tw1' => 1, 'x_alokasi_tw2' => 0, 'x_alokasi_tw3' => 1, 'x_alokasi_tw4' => 1,
            'y_alokasi_tw1' => 3, 'y_alokasi_tw2' => 0, 'y_alokasi_tw3' => 0, 'y_alokasi_tw4' => 0,
        ]);

        // TW I: X=1,Y=3 -> 33.33%. TW III kumulatif: X=1+0+1=2, Y=3+0+0=3 -> 66.67%.
        $this->assertEqualsWithDelta(33.33, $capaian->alokasiKumulatif(1), 0.01);
        $this->assertEqualsWithDelta(33.33, $capaian->alokasiKumulatif(2), 0.01);
        $this->assertEqualsWithDelta(66.67, $capaian->alokasiKumulatif(3), 0.01);
        $this->assertEqualsWithDelta(100.0, $capaian->alokasiKumulatif(4), 0.01);
    }

    public function test_realisasi_kumulatif_rasio_menjumlahkan_x_dan_y_per_tw(): void
    {
        $capaian = $this->buatCapaianRasio([
            'x_realisasi_tw1' => 1, 'x_realisasi_tw2' => 1,
            'y_realisasi_tw1' => 3, 'y_realisasi_tw2' => 0,
        ]);

        // TW I: X=1,Y=3 -> 33.33%. TW II kumulatif: X=1+1=2, Y=3+0=3 -> 66.67%.
        $this->assertEqualsWithDelta(33.33, $capaian->realisasiKumulatif(1), 0.01);
        $this->assertEqualsWithDelta(66.67, $capaian->realisasiKumulatif(2), 0.01);
    }

    public function test_realisasi_kumulatif_rasio_nol_bila_penyebut_masih_kosong(): void
    {
        $capaian = $this->buatCapaianRasio(['x_realisasi_tw1' => 5]);

        $this->assertSame(0.0, $capaian->realisasiKumulatif(1));
    }

    public function test_target_tahunan_rasio_tidak_ikut_dijumlah_lintas_tw(): void
    {
        // Target Tahunan diisi SEKALI untuk satu tahun (x_target/y_target) — TERPISAH
        // dari X/Y per-TW manapun, dan TIDAK dijumlahkan seperti alokasi/realisasi.
        $capaian = $this->buatCapaianRasio([
            'x_target' => 3, 'y_target' => 3,
            'x_alokasi_tw1' => 1, 'y_alokasi_tw1' => 3,
            'x_alokasi_tw2' => 1, 'y_alokasi_tw2' => 3,
        ]);

        $this->assertEqualsWithDelta(100.0, $capaian->targetTahunan(), 0.01);
        // Alokasi kumulatif TW II tetap dijumlah (2/6=33.33), TIDAK memengaruhi Target.
        $this->assertEqualsWithDelta(33.33, $capaian->alokasiKumulatif(2), 0.01);
    }

    public function test_capaian_triwulanan_dan_setahun_rasio(): void
    {
        // Target Tahunan 100 (x_target=3,y_target=3), TW I X=1,Y=3 untuk alokasi
        // MAUPUN realisasi (tercapai penuh TW I) -> Capaian Triwulanan TW I =
        // 100.00%, Capaian Setahun TW I = 33.33%.
        $capaian = $this->buatCapaianRasio([
            'x_target' => 3, 'y_target' => 3,
            'x_alokasi_tw1' => 1, 'y_alokasi_tw1' => 3,
            'x_realisasi_tw1' => 1, 'y_realisasi_tw1' => 3,
        ]);

        $this->assertEqualsWithDelta(100.0, $capaian->capaianTriwulanan(1), 0.01);
        $this->assertEqualsWithDelta(33.33, $capaian->capaianSetahun(1), 0.01);
    }

    public function test_metode_langsung_menjumlahkan_alokasi_dan_realisasi_per_tw(): void
    {
        // Tim SAKIP isi kontribusi TW-nya sendiri (bukan kumulatif): TW I=25, TW II=60
        // -> kumulatif TW II otomatis 25+60=85.
        $capaian = $this->buatCapaianRasio(
            [
                'target_tahunan' => 101.67,
                'alokasi_tw1' => 25, 'alokasi_tw2' => 60, 'alokasi_tw3' => 10, 'alokasi_tw4' => 6.67,
                'realisasi_tw1' => 55.6, 'realisasi_tw2' => 30.86,
                'x_alokasi_tw1' => 999, 'y_alokasi_tw1' => 1, // kolom rasio harus diabaikan total
            ],
            MasterIku::METODE_LANGSUNG
        );

        $this->assertEqualsWithDelta(25.0, $capaian->alokasiKumulatif(1), 0.01);
        $this->assertEqualsWithDelta(85.0, $capaian->alokasiKumulatif(2), 0.01);
        $this->assertEqualsWithDelta(101.67, $capaian->alokasiKumulatif(4), 0.01);
        $this->assertEqualsWithDelta(55.6, $capaian->realisasiKumulatif(1), 0.01);
        $this->assertEqualsWithDelta(86.46, $capaian->realisasiKumulatif(2), 0.01);
        $this->assertEqualsWithDelta(101.67, $capaian->targetTahunan(), 0.01);

        // Capaian % (Triwulanan) TW II: realisasi kumulatif 86.46 / alokasi kumulatif 85 -> 101.72.
        $this->assertEqualsWithDelta(101.72, $capaian->capaianTriwulanan(2), 0.01);
        // Capaian % (Setahun) TW II: realisasi kumulatif 86.46 / target 101.67 -> 85.04.
        $this->assertEqualsWithDelta(85.04, $capaian->capaianSetahun(2), 0.01);
    }
}
