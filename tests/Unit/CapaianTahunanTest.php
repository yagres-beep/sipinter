<?php

namespace Tests\Unit;

use App\Models\CapaianTahunan;
use App\Models\MasterIku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rumus alokasiKumulatif()/realisasiKumulatif()/targetTahunan(). DUA pola berbeda
 * (lihat docblock App\Models\CapaianTahunan):
 * - Alokasi (KEDUA metode) & Realisasi Y (rasio): Tim SAKIP mengetik langsung angka
 *   KUMULATIF/konstan TW I s.d. TW tsb (PERSIS sheet resmi "LK_Kabkot", kolom M-P
 *   untuk Alokasi, Q-T untuk Realisasi -- verifikasi terhadap sheet menunjukkan
 *   baris Non % pun demikian) — dibaca apa adanya per TW, TIDAK dijumlahkan lagi.
 *   Target Tahunan (KEDUA metode) SELALU = Alokasi Kumulatif TW IV.
 * - Realisasi X (rasio) & Realisasi (IKU 'langsung'): PENGECUALIAN -- Realisasi X
 *   (checklist Rincian N) mengisi kontribusi TRIWULAN ITU SENDIRI (bukan
 *   kumulatif) — TETAP dijumlahkan otomatis dari TW I s.d. TW yang diminta. Untuk
 *   'langsung', Realisasi JUGA sudah kumulatif langsung diketik (SAMA pola dengan
 *   Alokasi), dibaca apa adanya juga.
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

    public function test_alokasi_kumulatif_rasio_dibaca_langsung_tanpa_dijumlah(): void
    {
        // Tim SAKIP isi angka KUMULATIF langsung per TW (persis sheet "LK_Kabkot"
        // resmi): X=[1,1,2,3], Y=3 konstan di keempat TW.
        $capaian = $this->buatCapaianRasio([
            'x_alokasi_tw1' => 1, 'x_alokasi_tw2' => 1, 'x_alokasi_tw3' => 2, 'x_alokasi_tw4' => 3,
            'y_alokasi_tw1' => 3, 'y_alokasi_tw2' => 3, 'y_alokasi_tw3' => 3, 'y_alokasi_tw4' => 3,
        ]);

        // Dibaca APA ADANYA per TW (TIDAK dijumlahkan) -- TW I: 1/3=33.33%,
        // TW III: 2/3=66.67%, TW IV: 3/3=100%.
        $this->assertEqualsWithDelta(33.33, $capaian->alokasiKumulatif(1), 0.01);
        $this->assertEqualsWithDelta(33.33, $capaian->alokasiKumulatif(2), 0.01);
        $this->assertEqualsWithDelta(66.67, $capaian->alokasiKumulatif(3), 0.01);
        $this->assertEqualsWithDelta(100.0, $capaian->alokasiKumulatif(4), 0.01);
    }

    public function test_realisasi_kumulatif_rasio_menjumlahkan_x_tapi_membaca_y_langsung(): void
    {
        // Y konstan sepanjang tahun (diulang sama di keempat TW, PERSIS pola
        // Alokasi Y -- lihat App\Livewire\TargetTahunan::simpan()), X TETAP
        // dijumlahkan lintas TW (kontribusi mentah per TW dari checklist Rincian N).
        $capaian = $this->buatCapaianRasio([
            'x_realisasi_tw1' => 1, 'x_realisasi_tw2' => 1,
            'y_realisasi_tw1' => 3, 'y_realisasi_tw2' => 3,
        ]);

        // TW I: X=1,Y=3 -> 33.33%. TW II: X kumulatif=1+1=2, Y dibaca langsung=3
        // (BUKAN dijumlah lagi) -> 66.67%.
        $this->assertEqualsWithDelta(33.33, $capaian->realisasiKumulatif(1), 0.01);
        $this->assertEqualsWithDelta(66.67, $capaian->realisasiKumulatif(2), 0.01);
    }

    public function test_realisasi_kumulatif_rasio_nol_bila_penyebut_masih_kosong(): void
    {
        $capaian = $this->buatCapaianRasio(['x_realisasi_tw1' => 5]);

        $this->assertSame(0.0, $capaian->realisasiKumulatif(1));
    }

    public function test_target_tahunan_rasio_sama_dengan_alokasi_kumulatif_tw_empat(): void
    {
        // Target Tahunan (rasio) TIDAK LAGI diketik terpisah (x_target/y_target lama,
        // tidak dipakai lagi) -- SELALU = Alokasi Kumulatif TW IV.
        $capaian = $this->buatCapaianRasio([
            'x_alokasi_tw1' => 1, 'y_alokasi_tw1' => 3,
            'x_alokasi_tw2' => 1, 'y_alokasi_tw2' => 3,
            'x_alokasi_tw3' => 2, 'y_alokasi_tw3' => 3,
            'x_alokasi_tw4' => 3, 'y_alokasi_tw4' => 3,
        ]);

        $this->assertEqualsWithDelta(100.0, $capaian->targetTahunan(), 0.01);
        // Alokasi kumulatif TW II tetap dibaca langsung (1/3=33.33), TIDAK
        // dipengaruhi/memengaruhi Target (yang membaca TW IV).
        $this->assertEqualsWithDelta(33.33, $capaian->alokasiKumulatif(2), 0.01);
    }

    public function test_capaian_triwulanan_dan_setahun_rasio(): void
    {
        // Contoh persis sheet "LK_Kabkot" resmi: Alokasi Kumulatif X=[1,1,2,3],
        // Y=3 konstan (Target Tahunan otomatis dari TW IV = 3/3 = 100%). TW I
        // realisasi X=1,Y=3 (tercapai penuh alokasi kumulatif TW I) -> Capaian
        // Triwulanan TW I = 100.00%, Capaian Setahun TW I = 33.33%.
        $capaian = $this->buatCapaianRasio([
            'x_alokasi_tw1' => 1, 'y_alokasi_tw1' => 3,
            'x_alokasi_tw2' => 1, 'y_alokasi_tw2' => 3,
            'x_alokasi_tw3' => 2, 'y_alokasi_tw3' => 3,
            'x_alokasi_tw4' => 3, 'y_alokasi_tw4' => 3,
            'x_realisasi_tw1' => 1, 'y_realisasi_tw1' => 3,
        ]);

        $this->assertEqualsWithDelta(100.0, $capaian->capaianTriwulanan(1), 0.01);
        $this->assertEqualsWithDelta(33.33, $capaian->capaianSetahun(1), 0.01);
    }

    public function test_metode_langsung_membaca_alokasi_dan_realisasi_kumulatif_apa_adanya(): void
    {
        // Angka PERSIS baris "Non %" sheet "LK_Kabkot" resmi (D76, satuan Persen):
        // Alokasi M-P=[25,85,95,101.67] & Realisasi Q-R=[55.6,86.46] -- Tim SAKIP
        // mengetik langsung angka KUMULATIF ini (BUKAN kontribusi 25,60,10,6.67
        // yang perlu dijumlah -- itu KEPUTUSAN LAMA yang sudah tidak berlaku).
        $capaian = $this->buatCapaianRasio(
            [
                'alokasi_tw1' => 25, 'alokasi_tw2' => 85, 'alokasi_tw3' => 95, 'alokasi_tw4' => 101.67,
                'realisasi_tw1' => 55.6, 'realisasi_tw2' => 86.46,
                'x_alokasi_tw1' => 999, 'y_alokasi_tw1' => 1, // kolom rasio harus diabaikan total
            ],
            MasterIku::METODE_LANGSUNG
        );

        $this->assertEqualsWithDelta(25.0, $capaian->alokasiKumulatif(1), 0.01);
        $this->assertEqualsWithDelta(85.0, $capaian->alokasiKumulatif(2), 0.01);
        $this->assertEqualsWithDelta(101.67, $capaian->alokasiKumulatif(4), 0.01);
        $this->assertEqualsWithDelta(55.6, $capaian->realisasiKumulatif(1), 0.01);
        $this->assertEqualsWithDelta(86.46, $capaian->realisasiKumulatif(2), 0.01);
        // Target Tahunan SELALU = Alokasi Kumulatif TW IV, sama seperti 'rasio'.
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
        $capaian = $this->buatCapaianRasio(['x_alokasi_tw4' => 8, 'y_alokasi_tw4' => 90]);

        $this->assertEqualsWithDelta(8.89, $capaian->targetTahunan(), 0.01);
    }

    /**
     * Bagian 4 edge case 2 — Tipe A ("%"): contoh kedua dari spek, X=4,Y=4 -> 100%
     * (kebetulan 100, TAPI bukan aturan tetap — dibuktikan berbeda dari contoh di atas).
     */
    public function test_tipe_a_target_persen_bisa_seratus_persen(): void
    {
        $capaian = $this->buatCapaianRasio(['x_alokasi_tw4' => 4, 'y_alokasi_tw4' => 4]);

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
        // Angka PERSIS baris D79 sheet "LK_Kabkot" resmi -- Alokasi M-P=[0,1.04,4.05,4.35]
        // (P=TW IV=Target=4.35); Realisasi Q-R dimulai 0, TW II kumulatif 0.79
        // (dibulatkan 2 desimal, lihat docblock kelas ini soal cast decimal:2).
        $capaian = $this->buatCapaianRasio([
            'alokasi_tw1' => 0, 'alokasi_tw2' => 1.04, 'alokasi_tw3' => 4.05, 'alokasi_tw4' => 4.35,
            'realisasi_tw1' => 0, 'realisasi_tw2' => 0.79,
        ], MasterIku::METODE_LANGSUNG);

        $this->assertEqualsWithDelta(1.04, $capaian->alokasiKumulatif(2), 0.001);
        $this->assertEqualsWithDelta(0.79, $capaian->realisasiKumulatif(2), 0.001);
        // 0.79 / 1.04 * 100 = 75.96.
        $this->assertEqualsWithDelta(75.96, $capaian->capaianTriwulanan(2), 0.01);
        // 0.79 / 4.35 * 100 = 18.16.
        $this->assertEqualsWithDelta(18.16, $capaian->capaianSetahun(2), 0.01);
    }

    /**
     * Bagian 4 edge case 4 (versi kumulatif-apa-adanya) — TW yang belum diisi
     * (null) dibaca sebagai 0.0 apa adanya, TIDAK mewarisi angka TW sebelumnya --
     * beda dari model lama (dicabut) yang menjumlahkan kontribusi lintas TW
     * sehingga TW belum-terisi otomatis "mewarisi" total TW sebelumnya.
     */
    public function test_metode_langsung_tw_belum_terisi_dibaca_nol_bukan_mewarisi_tw_sebelumnya(): void
    {
        $capaian = $this->buatCapaianRasio([
            'alokasi_tw2' => 50, 'realisasi_tw2' => 50,
            // alokasi_tw1/realisasi_tw1 belum diisi (null).
        ], MasterIku::METODE_LANGSUNG);

        $this->assertSame(0.0, $capaian->alokasiKumulatif(1));
        $this->assertSame(0.0, $capaian->realisasiKumulatif(1));
        $this->assertEqualsWithDelta(50.0, $capaian->alokasiKumulatif(2), 0.01);
        $this->assertEqualsWithDelta(50.0, $capaian->realisasiKumulatif(2), 0.01);
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
