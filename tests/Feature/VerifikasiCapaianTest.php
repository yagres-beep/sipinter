<?php

namespace Tests\Feature;

use App\Livewire\VerifikasiCapaian;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\RtlEvaluasi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VerifikasiCapaianTest extends TestCase
{
    use RefreshDatabase;

    protected function buatSakip(): User
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);

        return User::create([
            'nama' => 'Tim SAKIP Uji',
            'username' => 'sakip-uji@example.test', 'email' => 'sakip-uji@example.test',
            'password' => 'password',
            'role_id' => $peran->id,
            'status_verifikasi' => 'terverifikasi',
        ]);
    }

    protected function siapkanIkuDenganDuaKegiatan(): array
    {
        $iku = MasterIku::create([
            'kode' => 'UJI-010',
            'indikator' => 'Indikator uji verifikasi',
            'tim' => 'Uji',
            'penanggung_jawab' => 'Ketua Uji',
        ]);

        $periode = Periode::create([
            'tahun' => 2026,
            'bulan' => 8,
            'triwulan' => 3,
            'bulan_ke' => 2,
            'flag_bulan_terlewat' => false,
        ]);

        $capaian = Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id]);

        $kegiatan1 = Kegiatan::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan pertama',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIAJUKAN,
        ]);

        $kegiatan2 = Kegiatan::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan kedua',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIAJUKAN,
        ]);

        $berkas1 = Berkas::create([
            'ref_id' => $kegiatan1->id,
            'ref_type' => Kegiatan::class,
            'kategori' => 'capaian',
            'nama_file' => 'bukti1.pdf',
            'status_verifikasi' => 'menunggu',
        ]);

        $berkas2 = Berkas::create([
            'ref_id' => $kegiatan2->id,
            'ref_type' => Kegiatan::class,
            'kategori' => 'capaian',
            'nama_file' => 'bukti2.pdf',
            'status_verifikasi' => 'menunggu',
        ]);

        return compact('iku', 'periode', 'capaian', 'kegiatan1', 'kegiatan2', 'berkas1', 'berkas2');
    }

    public function test_verifikasi_selesai_memverifikasi_semua_kegiatan_di_iku_dan_periode_yang_sama(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->set('target_pk', 100)
            ->set('target_tw', 50)
            ->set('realisasi', 45)
            ->set('persentase_capaian', 90)
            ->call('verifikasiSelesai')
            ->assertHasNoErrors();

        $this->assertSame('diverifikasi', $data['kegiatan1']->fresh()->status_dokumen);
        $this->assertSame('diverifikasi', $data['kegiatan2']->fresh()->status_dokumen);

        $capaian = $data['capaian']->fresh();
        $this->assertEquals(50, $capaian->target_tw);
        $this->assertEquals(45, $capaian->realisasi);
    }

    public function test_koreksi_teks_kegiatan_tersimpan_saat_verifikasi_selesai(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->set("koreksiKegiatan.{$data['kegiatan1']->id}", 'Kegiatan pertama (dikoreksi Tim SAKIP)')
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->set('target_pk', 100)
            ->set('target_tw', 50)
            ->set('realisasi', 45)
            ->set('persentase_capaian', 90)
            ->call('verifikasiSelesai')
            ->assertHasNoErrors();

        $this->assertSame('Kegiatan pertama (dikoreksi Tim SAKIP)', $data['kegiatan1']->fresh()->uraian_kegiatan);
    }

    public function test_verifikasi_selesai_gagal_bila_masih_ada_berkas_menunggu(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiSesuai', $data['berkas1']->id)
            // berkas2 dibiarkan "menunggu"
            ->set('target_pk', 100)
            ->set('target_tw', 50)
            ->set('realisasi', 45)
            ->set('persentase_capaian', 90)
            ->call('verifikasiSelesai')
            ->assertHasErrors(['berkas']);

        $this->assertSame('diajukan', $data['kegiatan1']->fresh()->status_dokumen);
        $this->assertSame('diajukan', $data['kegiatan2']->fresh()->status_dokumen);
    }

    public function test_kembalikan_ke_ketua_tim_mengembalikan_semua_kegiatan_terkait(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiTolak', $data['berkas1']->id)
            ->set('catatanBerkas.'.$data['berkas1']->id, 'Bukti tidak jelas')
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('kembalikanKeKetuaTim')
            ->assertHasNoErrors();

        $this->assertSame('dikembalikan', $data['kegiatan1']->fresh()->status_dokumen);
        $this->assertSame('dikembalikan', $data['kegiatan2']->fresh()->status_dokumen);
    }

    public function test_berkas_list_menggabungkan_bukti_capaian_solusi_dan_rtl(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        $kendalaSolusi = KendalaSolusi::create([
            'iku_id' => $data['iku']->id,
            'periode_id' => $data['periode']->id,
            'kendala' => 'Kendala uji',
            'solusi' => 'Solusi uji',
        ]);

        Berkas::create([
            'ref_id' => $kendalaSolusi->id,
            'ref_type' => KendalaSolusi::class,
            'kategori' => 'solusi',
            'nama_file' => 'bukti-solusi.pdf',
            'status_verifikasi' => 'menunggu',
        ]);

        // RTL disimpan dengan periode TARGET-nya (sama triwulan dengan capaian ini,
        // triwulan III) â€” sama seperti alur nyata "RTL Baru" yang ditetapkan saat
        // mengisi triwulan sebelumnya untuk dilaksanakan triwulan berjalan ini.
        $periodeRtl = Periode::create([
            'tahun' => 2026,
            'bulan' => 7,
            'triwulan' => 3,
            'bulan_ke' => 1,
            'flag_bulan_terlewat' => false,
        ]);

        $rtl = RtlEvaluasi::create([
            'iku_id' => $data['iku']->id,
            'periode_id' => $periodeRtl->id,
            'rtl_teks' => 'RTL triwulan sebelumnya',
            'berlaku_bulan' => 'RTL untuk April, Mei, Juni',
            'pic' => 'PIC Uji',
            'batas_waktu' => '2026-06-30',
            'realisasi' => 'Sudah dilaksanakan',
            'status_cocok' => 'cocok',
        ]);

        Berkas::create([
            'ref_id' => $rtl->id,
            'ref_type' => RtlEvaluasi::class,
            'kategori' => 'evaluasi_rtl',
            'nama_file' => 'bukti-rtl.pdf',
            'status_verifikasi' => 'menunggu',
        ]);

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']]);

        $namaBerkas = $component->instance()->berkasList()->pluck('nama_file')->all();

        $this->assertContains('bukti1.pdf', $namaBerkas);
        $this->assertContains('bukti2.pdf', $namaBerkas);
        $this->assertContains('bukti-solusi.pdf', $namaBerkas);
        $this->assertContains('bukti-rtl.pdf', $namaBerkas);
        $this->assertCount(4, $namaBerkas);
    }
}
