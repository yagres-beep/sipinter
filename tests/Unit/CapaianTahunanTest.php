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

    /**
     * Bagian 4 edge case 2 — Tipe A ("%"): target% TIDAK selalu 100. Contoh dari
     * spek: X=8,Y=90 -> 8,89%.
     */
    public function test_tipe_a_target_persen_tidak_selalu_seratus_delapan_koma_delapan_sembilan(): void
    {
        $capaian = $this->buatCapaianRasio(['x_target' => 8, 'y_target' => 90]);

        $this->assertEqualsWithDelta(8.89, $capaian->targetTahunan(), 0.01);
    }

    /**
     * Bagian 4 edge case 2 — Tipe A ("%"): contoh kedua dari spek, X=4,Y=4 -> 100%
     * (kebetulan 100, TAPI bukan aturan tetap — dibuktikan berbeda dari contoh di atas).
     */
    public function test_tipe_a_target_persen_bisa_seratus_persen(): void
    {
        $capaian = $this->buatCapaianRasio(['x_target' => 4, 'y_target' => 4]);

        $this->assertEqualsWithDelta(100.0, $capaian->targetTahunan(), 0.01);
    }

    /**
     * Bagian 4 edge case 1 — Kumulatif TW I = nilai TW I itu sendiri (Tipe A).
     */
    public function test_tipe_a_kumulatif_tw_satu_sama_dengan_nilai_tw_satu_sendiri(): void
    {
        $capaian = $this->buatCapaianRasio([
            'x_alokasi_tw1' => 8, 'y_alokasi_tw1' => 90,
        ]);

        $this->assertEqualsWithDelta(8.89, $capaian->alokasiKumulatif(1), 0.01);
    }

    /**
     * Bagian 4 edge case 1 — Kumulatif TW I = nilai TW I itu sendiri (Tipe B).
     */
    public function test_tipe_b_kumulatif_tw_satu_sama_dengan_nilai_tw_satu_sendiri(): void
    {
        $capaian = $this->buatCapaianRasio(['alokasi_tw1' => 1.09], MasterIku::METODE_LANGSUNG);

        $this->assertEqualsWithDelta(1.09, $capaian->alokasiKumulatif(1), 0.01);
    }

    /**
     * Bagian 4 edge case 3 — Tipe B ("Non %"): variasi contoh Indeks Pelayanan
     * Publik dari spek (target 4,35), disesuaikan ke presisi 2 desimal karena
     * kolom alokasi_tw dan realisasi_tw di CapaianTahunan memakai cast 'decimal:2'
     * (spek aslinya memakai presisi 5 desimal — angka LITERAL spek 76,00%/18,17%
     * diverifikasi terpisah di CapaianCalculatorServiceTest terhadap fungsi murni
     * hitungCapaian()/hitungPersentase(), yang tidak terpengaruh presisi kolom DB).
     */
    public function test_tipe_b_indeks_pelayanan_publik_variasi_presisi_dua_desimal(): void
    {
        $capaian = $this->buatCapaianRasio([
            'target_tahunan' => 4.35,
            'alokasi_tw1' => 0.52, 'alokasi_tw2' => 0.52,
            'realisasi_tw1' => 0.40, 'realisasi_tw2' => 0.39,
        ], MasterIku::METODE_LANGSUNG);

        $this->assertEqualsWithDelta(1.04, $capaian->alokasiKumulatif(2), 0.001);
        $this->assertEqualsWithDelta(0.79, $capaian->realisasiKumulatif(2), 0.001);
        // 0.79 / 1.04 * 100 = 75.96.
        $this->assertEqualsWithDelta(75.96, $capaian->capaianTriwulanan(2), 0.01);
        // 0.79 / 4.35 * 100 = 18.16.
        $this->assertEqualsWithDelta(18.16, $capaian->capaianSetahun(2), 0.01);
    }

    /**
     * Bagian 4 edge case 4 — Realisasi triwulan belum diisi (null) -> capaian TW
     * itu "-" (bukan angka), dan TIDAK mencemari kumulatif triwulan berikutnya
     * seolah-olah bernilai 0 sungguhan (beda dari alokasi 0 yang memang sengaja 0).
     */
    public function test_realisasi_null_menghasilkan_tidak_dinilai_bukan_dianggap_nol_sungguhan(): void
    {
        $capaian = $this->buatCapaianRasio([
            'alokasi_tw1' => 50, 'alokasi_tw2' => 50,
            'realisasi_tw1' => 50, // TW II realisasi belum diisi (null).
        ], MasterIku::METODE_LANGSUNG);

        // TW II: alokasi kumulatif 100 > 0, realisasi kumulatif tetap 50 (TW II belum
        // menambah apa-apa, BUKAN realisasi_tw2 dianggap 0 lalu capaian TW II jadi "-").
        $this->assertEqualsWithDelta(50.0, $capaian->realisasiKumulatif(2), 0.01);
        $this->assertEqualsWithDelta(50.0, $capaian->capaianTriwulanan(2), 0.01);
    }

    /**
     * MasterIku::formula_capaian (App\Services\FormulaCapaianService) HANYA
     * dipakai untuk metode 'langsung' -- menggantikan rumus baku
     * Capaian::hitungPersentase() sepenuhnya (termasuk tidak lagi dibatasi
     * batas_maksimal_persen, karena rumusnya sendiri yang menentukan).
     */
    public function test_formula_kustom_dipakai_untuk_metode_langsung_bila_diisi(): void
    {
        $iku = new MasterIku(['metode_capaian' => MasterIku::METODE_LANGSUNG, 'formula_capaian' => 'alokasi + realisasi']);
        $capaian = new CapaianTahunan(['alokasi_tw1' => 10, 'realisasi_tw1' => 5]);
        $capaian->setRelation('masterIku', $iku);

        $this->assertEqualsWithDelta(15.0, $capaian->capaianTriwulanan(1), 0.01);
    }

    /**
     * IKU 'rasio' TIDAK PERNAH memakai formula_capaian walau kolomnya kebetulan
     * terisi (seharusnya tidak bisa terjadi lewat form Master IKU, tapi
     * capaianFormula() tetap menjaga di sisi model juga) -- tetap rumus X÷Y baku.
     */
    public function test_formula_kustom_diabaikan_untuk_metode_rasio(): void
    {
        $iku = new MasterIku(['metode_capaian' => MasterIku::METODE_RASIO, 'formula_capaian' => 'alokasi + realisasi']);
        $capaian = new CapaianTahunan([
            'x_target' => 3, 'y_target' => 3,
            'x_alokasi_tw1' => 1, 'y_alokasi_tw1' => 3,
            'x_realisasi_tw1' => 1, 'y_realisasi_tw1' => 3,
        ]);
        $capaian->setRelation('masterIku', $iku);

        $this->assertEqualsWithDelta(100.0, $capaian->capaianTriwulanan(1), 0.01);
    }

    public function test_tanpa_formula_kustom_metode_langsung_tetap_pakai_rumus_baku(): void
    {
        $iku = new MasterIku(['metode_capaian' => MasterIku::METODE_LANGSUNG]);
        $capaian = new CapaianTahunan(['alokasi_tw1' => 50, 'realisasi_tw1' => 25]);
        $capaian->setRelation('masterIku', $iku);

        // Rumus baku: realisasi/alokasi*100 = 50.0, BUKAN hasil formula apa pun.
        $this->assertEqualsWithDelta(50.0, $capaian->capaianTriwulanan(1), 0.01);
    }
}
