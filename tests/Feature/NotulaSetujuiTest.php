<?php

namespace Tests\Feature;

use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Services\NotulaService;
use Barryvdh\DomPDF\Facade\Pdf as PdfFacade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sebelum ini, Kegiatan::setujui() (diverifikasi -> disetujui) tidak pernah dipicu
 * di mana pun (lihat catatan lama di App\Models\Kegiatan) — status kegiatan/capaian
 * diam di "diverifikasi" selamanya walau notula triwulanannya sudah disetujui Kepala.
 * Tes ini memastikan NotulaService::setujui() sekarang ikut menyetujui kegiatan &
 * mencatat riwayat statusnya.
 */
class NotulaSetujuiTest extends TestCase
{
    use RefreshDatabase;

    protected function buatKepala(): User
    {
        $peran = Role::firstOrCreate(['nama' => 'Kepala']);

        return User::create([
            'nama' => 'Kepala Uji',
            'username' => 'kepala-uji@example.test', 'email' => 'kepala-uji@example.test',
            'password' => 'password',
            'role_id' => $peran->id,
            'status_verifikasi' => 'terverifikasi',
        ]);
    }

    public function test_setujui_notula_ikut_menyetujui_kegiatan_dan_mencatat_riwayat(): void
    {
        $kepala = $this->buatKepala();

        $iku = MasterIku::create([
            'kode' => 'UJI-NOTULA', 'indikator' => 'Indikator uji notula', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        $capaian = Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id]);

        $kegiatan = Kegiatan::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan siap disetujui',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        $notula = Notula::create([
            'periode_id' => $periode->id,
            'status' => Notula::STATUS_MENUNGGU_PERSETUJUAN,
            'bagian1_html' => '<p>Bagian I</p>',
        ]);

        // bagian2/3_pdf (pratinjau iframe) harus berupa PDF sungguhan — dirender
        // lewat DomPDF yang sama dipakai kode produksi. Render notula MENYATU yang
        // dipakai NotulaService::setujui() sendiri memakai bagian2/3_html (inline).
        $dir = storage_path("app/private/notula/{$notula->id}");

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        PdfFacade::loadHTML('<p>Bagian II</p>')->save($dir.'/bagian2.pdf');
        PdfFacade::loadHTML('<p>Bagian III</p>')->save($dir.'/bagian3.pdf');

        $notula->update([
            'bagian2_pdf' => "notula/{$notula->id}/bagian2.pdf",
            'bagian3_pdf' => "notula/{$notula->id}/bagian3.pdf",
            'bagian2_html' => '<p>Bagian II</p>',
            'bagian3_html' => '<p>Bagian III</p>',
        ]);

        app(NotulaService::class)->setujui($notula, $kepala);

        $this->assertSame(Kegiatan::STATUS_DISETUJUI, $kegiatan->fresh()->status_dokumen);

        $riwayat = $capaian->fresh()->riwayatStatus;
        $this->assertCount(1, $riwayat);
        $this->assertSame('disetujui', $riwayat->first()->status);
        $this->assertSame($kepala->id, $riwayat->first()->user_id);

        $this->assertSame(Notula::STATUS_DISETUJUI, $notula->fresh()->status);
    }
}
