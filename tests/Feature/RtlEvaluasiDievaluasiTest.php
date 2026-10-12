<?php

namespace Tests\Feature;

use App\Models\Berkas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\RtlEvaluasi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi: Ketua Tim kini cukup mengunggah BUKTI realisasi RTL (teks realisasi tidak
 * lagi diisi), tapi dasbor & daftar verifikasi dulu hanya menghitung poin RTL yang
 * teks realisasinya terisi — jadi bukti yang sudah diunggah tidak pernah muncul
 * untuk diverifikasi Tim SAKIP.
 */
class RtlEvaluasiDievaluasiTest extends TestCase
{
    use RefreshDatabase;

    public function test_poin_rtl_dengan_bukti_saja_dihitung_sudah_dievaluasi(): void
    {
        $iku = MasterIku::create(['kode' => 'UJI-RTL', 'indikator' => 'Indikator RTL', 'tim' => 'Uji']);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        $berbukti = RtlEvaluasi::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'rtl_teks' => 'RTL berbukti']);
        $teksLama = RtlEvaluasi::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'rtl_teks' => 'RTL teks lama', 'realisasi' => 'Sudah dilaksanakan']);
        $kosong = RtlEvaluasi::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'rtl_teks' => 'RTL belum']);

        Berkas::create([
            'ref_id' => $berbukti->id,
            'ref_type' => RtlEvaluasi::class,
            'kategori' => 'evaluasi_rtl',
            'nama_file' => 'bukti.pdf',
            'path' => 'bukti-evaluasi-rtl/bukti.pdf',
            'status_verifikasi' => 'menunggu',
        ]);

        $this->assertEqualsCanonicalizing(
            [$berbukti->id, $teksLama->id],
            RtlEvaluasi::yangSudahDievaluasi()->pluck('id')->all()
        );
        $this->assertSame([$kosong->id], RtlEvaluasi::yangBelumDievaluasi()->pluck('id')->all());
    }
}
