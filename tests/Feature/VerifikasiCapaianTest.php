<?php

namespace Tests\Feature;

use App\Livewire\VerifikasiCapaian;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\CapaianTahunan;
use App\Models\IndikatorProksi;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\Periode;
use App\Models\RincianN;
use App\Models\RincianOutput;
use App\Models\Role;
use App\Models\RtlEvaluasi;
use App\Models\User;
use App\Services\NotulaService;
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

        $capaian = Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);

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
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('verifikasiSelesai')
            ->assertHasNoErrors();

        $this->assertSame('diverifikasi', $data['kegiatan1']->fresh()->status_dokumen);
        $this->assertSame('diverifikasi', $data['kegiatan2']->fresh()->status_dokumen);

        $capaianTahunan = CapaianTahunan::where('iku_id', $data['iku']->id)->where('tahun', 2026)->first();
        $this->assertEquals(50, $capaianTahunan->alokasi_tw3);
        $this->assertEquals(45, $capaianTahunan->realisasi_tw3);
    }

    public function test_iku_poin_alokasi_dan_realisasi_semua_tw_bisa_diedit_dan_tersimpan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        $komponen = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']]);

        for ($tw = 1; $tw <= 4; $tw++) {
            $komponen->assertSeeHtml('wire:model.live.debounce.400ms="realisasi_tw'.$tw.'"')
                ->assertSeeHtml('wire:model.live.debounce.400ms="alokasi_tw'.$tw.'"');
        }

        $komponen->assertSet('editAlokasi', false)->set('editAlokasi', true);

        $komponen->set('alokasi_tw1', 25)->set('realisasi_tw1', 20)
            ->set('alokasi_tw4', 101.67)->set('realisasi_tw4', 90)
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $capaianTahunan = CapaianTahunan::where('iku_id', $data['iku']->id)->where('tahun', 2026)->first();
        $this->assertEquals(25, $capaianTahunan->alokasi_tw1);
        $this->assertEquals(20, $capaianTahunan->realisasi_tw1);
        $this->assertEquals(101.67, $capaianTahunan->alokasi_tw4);
        $this->assertEquals(90, $capaianTahunan->realisasi_tw4);
        $this->assertEquals(101.67, $capaianTahunan->targetTahunan());
    }

    public function test_iku_poin_realisasi_tw_menyamakan_tw_sesudahnya(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->set('realisasi_tw1', 10)
            ->assertSet('realisasi_tw2', 10)->assertSet('realisasi_tw3', 10)->assertSet('realisasi_tw4', 10)
            ->set('realisasi_tw2', 30)
            ->assertSet('realisasi_tw1', 10)->assertSet('realisasi_tw3', 30)->assertSet('realisasi_tw4', 30)
            ->set('realisasi_tw4', 50)
            // koreksi TW I yang lebih kecil tidak menimpa TW sesudahnya yang sudah lebih besar
            ->set('realisasi_tw1', 5)
            ->assertSet('realisasi_tw2', 30)->assertSet('realisasi_tw4', 50)
            // naikkan TW III melewati TW IV -> TW IV ikut naik
            ->set('realisasi_tw3', 60)
            ->assertSet('realisasi_tw4', 60);
    }

    public function test_batal_edit_alokasi_mengembalikan_nilai_tersimpan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        $komponen = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']]);
        $awal = $komponen->get('alokasi_tw2');

        $komponen->set('editAlokasi', true)
            ->set('alokasi_tw2', 999)
            ->call('batalEditAlokasi')
            ->assertSet('editAlokasi', false)
            ->assertSet('alokasi_tw2', $awal);
    }

    public function test_koreksi_teks_kegiatan_tersimpan_saat_verifikasi_selesai(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->set("koreksiKegiatan.{$data['kegiatan1']->id}", 'Kegiatan pertama (dikoreksi Tim SAKIP)')
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
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
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('verifikasiSelesai')
            ->assertHasErrors(['berkas']);

        $this->assertSame('diajukan', $data['kegiatan1']->fresh()->status_dokumen);
        $this->assertSame('diajukan', $data['kegiatan2']->fresh()->status_dokumen);
    }

    public function test_kembalikan_ke_ketua_tim_hanya_mengembalikan_kegiatan_yang_buktinya_ditolak(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->set('catatanBerkas.'.$data['berkas1']->id, 'Bukti tidak jelas')
            ->call('tandaiTolak', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->call('kembalikanKeKetuaTim')
            ->assertHasNoErrors();

        // kegiatan1 buktinya sendiri ditolak -> dikembalikan; kegiatan2 buktinya
        // sendiri sudah sesuai -> ikut diverifikasi (BUKAN ikut dikembalikan begitu
        // saja), supaya bukti yang sebenarnya sudah benar tidak pernah tampil
        // "Sesuai" di bawah kegiatan berlabel "Dikembalikan".
        $this->assertSame('dikembalikan', $data['kegiatan1']->fresh()->status_dokumen);
        $this->assertSame('diverifikasi', $data['kegiatan2']->fresh()->status_dokumen);

        // Status BESAR Capaian tetap "dikembalikan" karena isian periode ini belum
        // selesai seluruhnya.
        $this->assertSame('dikembalikan', $data['capaian']->fresh()->status);
    }

    public function test_kembalikan_ke_ketua_tim_gagal_bila_masih_ada_berkas_menunggu(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->set('catatanBerkas.'.$data['berkas1']->id, 'Bukti tidak jelas')
            ->call('tandaiTolak', $data['berkas1']->id)
            // berkas2 dibiarkan "menunggu"
            ->call('kembalikanKeKetuaTim')
            ->assertHasErrors(['berkas']);

        $this->assertSame('diajukan', $data['kegiatan1']->fresh()->status_dokumen);
        $this->assertSame('diajukan', $data['kegiatan2']->fresh()->status_dokumen);
    }

    public function test_tandai_tolak_wajib_catatan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiTolak', $data['berkas1']->id)
            ->assertHasErrors(['catatanBerkas.'.$data['berkas1']->id]);

        $this->assertSame('menunggu', $data['berkas1']->fresh()->status_verifikasi);
    }

    public function test_simpan_sementara_mengubah_status_jadi_sedang_ditangani(): void
    {
        $sakip = $this->buatSakip();
        $this->actingAs($sakip);
        $data = $this->siapkanIkuDenganDuaKegiatan();

        // Sebagian ditandai (berkas1 diterima), berkas2 dibiarkan "menunggu" —
        // simpanSementara() TIDAK mensyaratkan semua berkas sudah ditandai, beda
        // dari verifikasiSelesai()/kembalikanKeKetuaTim().
        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('simpanSementara')
            ->assertHasNoErrors();

        $capaian = $data['capaian']->fresh();
        $this->assertSame('sedang_ditangani', $capaian->status);

        // Kegiatan TIDAK ikut berubah status_dokumen-nya — checkpoint ini murni
        // status Capaian, bukan transisi alur kerja kegiatan.
        $this->assertSame('diajukan', $data['kegiatan1']->fresh()->status_dokumen);
        $this->assertSame('diajukan', $data['kegiatan2']->fresh()->status_dokumen);

        $riwayat = $capaian->riwayatStatus;
        $this->assertCount(1, $riwayat);
        $this->assertSame('sedang_ditangani', $riwayat->first()->status);
        $this->assertSame($sakip->id, $riwayat->first()->user_id);
    }

    public function test_simpan_sementara_berulang_tidak_menambah_riwayat_ganda(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']]);

        $component->call('tandaiSesuai', $data['berkas1']->id)
            ->call('simpanSementara')
            ->assertHasNoErrors();

        $component->set('catatanBerkas.'.$data['berkas2']->id, 'Bukti kurang jelas')
            ->call('tandaiTolak', $data['berkas2']->id)
            ->call('simpanSementara')
            ->assertHasNoErrors();

        // Dua kali "Simpan Sementara" berturut-turut (masih sama-sama draft_sakip)
        // tetap satu baris riwayat saja, bukan bertambah tiap klik.
        $this->assertDatabaseCount('riwayat_status_capaian', 1);
        $this->assertSame('sedang_ditangani', $data['capaian']->fresh()->status);
    }

    public function test_bisa_lanjut_menandai_berkas_dan_menyelesaikan_dari_sedang_ditangani(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        $data['capaian']->update(['status' => Capaian::STATUS_SEDANG_DITANGANI]);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('verifikasiSelesai')
            ->assertHasNoErrors();

        $this->assertSame('diverifikasi', $data['capaian']->fresh()->status);
        $this->assertSame('diverifikasi', $data['kegiatan1']->fresh()->status_dokumen);
    }

    public function test_tandai_kendala_sesuai_menandai_terverifikasi(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $ks = KendalaSolusi::create(['iku_id' => $data['iku']->id, 'periode_id' => $data['periode']->id, 'kendala' => 'Kendala uji']);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiKendalaSesuai', $ks->id)
            ->assertHasNoErrors();

        $this->assertSame('terverifikasi', $ks->fresh()->status_verifikasi);
    }

    public function test_tandai_kendala_tolak_wajib_catatan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $ks = KendalaSolusi::create(['iku_id' => $data['iku']->id, 'periode_id' => $data['periode']->id, 'kendala' => 'Kendala uji']);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiKendalaTolak', $ks->id)
            ->assertHasErrors(['catatanKendala.'.$ks->id]);

        $this->assertSame('menunggu', $ks->fresh()->status_verifikasi);
    }

    /**
     * Regresi: kendalaSolusiList() di-cache satu siklus request (lihat
     * $cacheKendalaSolusiList), tapi tandaiKendalaTolak() memicu cache itu (lewat
     * kendalaBisaDiverifikasi()) SEBELUM melakukan raw update() ke DB — akibatnya
     * render() pada request YANG SAMA membaca cache basi (status masih "menunggu")
     * walau DB sudah tersimpan "ditolak". Di layar ini tampak seolah tombol "Simpan
     * Verifikasi" tidak merespon karena label "✓ Tersimpan" tidak pernah muncul.
     * Percobaan pertama TANPA catatan (gagal validasi, menyisakan error di
     * error bag) sekaligus menguji error itu ikut dibersihkan begitu percobaan
     * kedua (dengan catatan) berhasil — sebelumnya error lama itu bertahan
     * selamanya karena addError() tidak pernah di-reset.
     */
    public function test_tandai_kendala_tolak_dengan_catatan_langsung_tercermin_di_render(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $ks = KendalaSolusi::create(['iku_id' => $data['iku']->id, 'periode_id' => $data['periode']->id, 'kendala' => 'Kendala uji']);

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiKendalaTolak', $ks->id)
            ->assertHasErrors(['catatanKendala.'.$ks->id]);

        $component->set('catatanKendala.'.$ks->id, 'Solusi tidak relevan')
            ->call('tandaiKendalaTolak', $ks->id)
            ->assertHasNoErrors()
            ->assertSee('Tersimpan');

        $this->assertSame('ditolak', $component->instance()->kendalaSolusiList()->firstWhere('id', $ks->id)->status_verifikasi);
    }

    public function test_kembalikan_ke_ketua_tim_bisa_dipicu_kendala_ditolak_tanpa_berkas_ditolak(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $ks = KendalaSolusi::create(['iku_id' => $data['iku']->id, 'periode_id' => $data['periode']->id, 'kendala' => 'Kendala uji']);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->set('catatanKendala.'.$ks->id, 'Solusi tidak relevan')
            ->call('tandaiKendalaTolak', $ks->id)
            ->call('kembalikanKeKetuaTim')
            ->assertHasNoErrors();

        $this->assertSame('dikembalikan', $data['capaian']->fresh()->status);
        // Kedua kegiatan buktinya sendiri sesuai -> tetap diverifikasi (bukan ikut
        // dikembalikan) meski Capaian besar jadi dikembalikan gara-gara kendala ditolak.
        $this->assertSame('diverifikasi', $data['kegiatan1']->fresh()->status_dokumen);
        $this->assertSame('diverifikasi', $data['kegiatan2']->fresh()->status_dokumen);

        $riwayat = $data['capaian']->fresh()->riwayatStatus->first();
        $this->assertSame('Solusi tidak relevan', $riwayat->catatan);
    }

    public function test_verifikasi_selesai_gagal_bila_kendala_masih_menunggu(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        KendalaSolusi::create(['iku_id' => $data['iku']->id, 'periode_id' => $data['periode']->id, 'kendala' => 'Kendala uji']);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('verifikasiSelesai')
            ->assertHasErrors(['berkas']);

        // Sudah "sedang ditangani" (bukan lagi "diajukan") sejak berkas ditandai
        // Sesuai di atas — verifikasiSelesai yang gagal validasi tidak
        // mengembalikannya ke "diajukan".
        $this->assertSame('sedang_ditangani', $data['capaian']->fresh()->status);
    }

    public function test_tandai_uraian_sesuai_menandai_terverifikasi(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->assertHasNoErrors();

        $this->assertSame('terverifikasi', $data['kegiatan1']->fresh()->status_verifikasi_uraian);
    }

    public function test_tandai_uraian_tolak_wajib_catatan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiUraianTolak', $data['kegiatan1']->id)
            ->assertHasErrors(['catatanUraian.'.$data['kegiatan1']->id]);

        $this->assertSame('menunggu', $data['kegiatan1']->fresh()->status_verifikasi_uraian);
    }

    public function test_kembalikan_ke_ketua_tim_bisa_dipicu_uraian_ditolak_tanpa_berkas_ditolak(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->set('catatanUraian.'.$data['kegiatan1']->id, 'Uraian belum menjelaskan output')
            ->call('tandaiUraianTolak', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->call('kembalikanKeKetuaTim')
            ->assertHasNoErrors();

        // Uraiannya sendiri ditolak (biarpun buktinya sesuai) -> kegiatan1 tetap
        // dikembalikan; kegiatan2 (uraian & bukti sama-sama sesuai) ikut diverifikasi.
        $this->assertSame('dikembalikan', $data['kegiatan1']->fresh()->status_dokumen);
        $this->assertSame('diverifikasi', $data['kegiatan2']->fresh()->status_dokumen);

        $riwayat = $data['capaian']->fresh()->riwayatStatus->first();
        $this->assertSame('Uraian belum menjelaskan output', $riwayat->catatan);
    }

    protected function siapkanBagianKustomPoin(array $data): BagianKustomPoin
    {
        $bagian = BagianKustom::create(['nama' => 'Manajemen Risiko']);

        return BagianKustomPoin::create([
            'bagian_kustom_id' => $bagian->id,
            'iku_id' => $data['iku']->id,
            'periode_id' => $data['periode']->id,
            'teks' => 'Poin manajemen risiko uji',
        ]);
    }

    public function test_tandai_bagian_kustom_sesuai_menandai_terverifikasi(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $poin = $this->siapkanBagianKustomPoin($data);
        $poin->update(['catatan_bukti_dihapus' => 'Bukti lama tidak relevan']);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiBagianKustomSesuai', $poin->id)
            ->assertHasNoErrors();

        $poin->refresh();
        $this->assertSame('terverifikasi', $poin->status_verifikasi);
        // Pengingat "bukti dihapus" sudah tidak relevan begitu poin ini "Sesuai".
        $this->assertNull($poin->catatan_bukti_dihapus);
    }

    public function test_tandai_bagian_kustom_tolak_wajib_catatan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $poin = $this->siapkanBagianKustomPoin($data);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiBagianKustomTolak', $poin->id)
            ->assertHasErrors(['catatanBagianKustom.'.$poin->id]);

        $this->assertSame('menunggu', $poin->fresh()->status_verifikasi);
    }

    public function test_verifikasi_selesai_gagal_bila_bagian_kustom_masih_menunggu(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $this->siapkanBagianKustomPoin($data);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('verifikasiSelesai')
            ->assertHasErrors(['berkas']);

        $this->assertSame('sedang_ditangani', $data['capaian']->fresh()->status);
    }

    /**
     * "Dievaluasi" (lihat RtlEvaluasi::sudahDievaluasi(), dipakai
     * VerifikasiCapaian::rtlBisaDiverifikasi()) berarti minimal satu bukti realisasi
     * TERUNGGAH — bukan kolom teks realisasi (sudah dihapus dari alur pengisian Ketua
     * Tim), jadi baris berkas di bawah wajib ada supaya poin ini bisa ditandai
     * Sesuai/Tidak Sesuai Tim SAKIP.
     */
    protected function siapkanRtlDenganRealisasi(array $data): RtlEvaluasi
    {
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
        ]);

        Berkas::create([
            'ref_id' => $rtl->id,
            'ref_type' => RtlEvaluasi::class,
            'kategori' => 'evaluasi_rtl',
            'nama_file' => 'realisasi.pdf',
            'status_verifikasi' => 'menunggu',
        ]);

        return $rtl;
    }

    public function test_tandai_rtl_sesuai_menandai_terverifikasi(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $rtl = $this->siapkanRtlDenganRealisasi($data);
        $rtl->update(['catatan_bukti_dihapus' => 'Bukti lama tidak relevan']);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiRtlSesuai', $rtl->id)
            ->assertHasNoErrors();

        $rtl->refresh();
        $this->assertSame('terverifikasi', $rtl->status_verifikasi);
        // Pengingat "bukti dihapus" sudah tidak relevan begitu poin ini "Sesuai".
        $this->assertNull($rtl->catatan_bukti_dihapus);
    }

    public function test_tandai_rtl_tolak_wajib_catatan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $rtl = $this->siapkanRtlDenganRealisasi($data);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiRtlTolak', $rtl->id)
            ->assertHasErrors(['catatanRtl.'.$rtl->id]);

        $this->assertSame('menunggu', $rtl->fresh()->status_verifikasi);
    }

    public function test_rtl_belum_dilaporkan_tidak_bisa_diverifikasi_dan_tidak_menghalangi_verifikasi_selesai(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

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
            'realisasi' => null,
        ]);

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']]);

        $this->assertFalse($component->instance()->rtlBisaDiverifikasi($rtl->id));

        // Poin RTL yang belum dilaporkan (belum ada bukti terunggah sama sekali) tidak
        // ikut menghalangi "Verifikasi Selesai" — tidak ada apa pun untuk diperiksa.
        $component->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('verifikasiSelesai')
            ->assertHasNoErrors();

        $this->assertSame('menunggu', $rtl->fresh()->status_verifikasi);
    }

    /**
     * Regresi: sebelumnya rtlBisaDiverifikasi() menggerbang pada filled($poin->realisasi)
     * — kolom teks yang SUDAH TIDAK PERNAH diisi Ketua Tim (dihapus dari form Bagian 4,
     * lihat RtlEvaluasi::sudahDievaluasi()) — sehingga Tim SAKIP TIDAK PERNAH bisa
     * menandai Sesuai/Tidak Sesuai poin RTL walau Ketua Tim sudah unggah bukti, dan poin
     * itu juga tidak pernah menghalangi "Verifikasi Selesai". Ketua Tim HANYA unggah
     * bukti (realisasi tetap null) — Tim SAKIP HANYA perlu memverifikasi.
     */
    public function test_rtl_dengan_bukti_terunggah_tanpa_teks_realisasi_wajib_diverifikasi(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        $periodeRtl = Periode::create([
            'tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false,
        ]);

        $rtl = RtlEvaluasi::create([
            'iku_id' => $data['iku']->id,
            'periode_id' => $periodeRtl->id,
            'rtl_teks' => 'RTL triwulan sebelumnya',
            'realisasi' => null,
        ]);

        $berkasRtl = Berkas::create([
            'ref_id' => $rtl->id,
            'ref_type' => RtlEvaluasi::class,
            'kategori' => 'evaluasi_rtl',
            'nama_file' => 'realisasi.pdf',
            'status_verifikasi' => 'menunggu',
        ]);

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']]);

        $this->assertTrue($component->instance()->rtlBisaDiverifikasi($rtl->id));

        // Belum ditandai Tim SAKIP -> harus menghalangi "Verifikasi Selesai" (baik
        // berkasnya sendiri MAUPUN poin RTL-nya wajib ditandai, dua lapis terpisah).
        $component->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->call('tandaiSesuai', $berkasRtl->id)
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('verifikasiSelesai')
            ->assertHasErrors(['berkas']);

        $this->assertSame('menunggu', $rtl->fresh()->status_verifikasi);

        // Setelah ditandai Sesuai, verifikasi selesai baru boleh lanjut.
        $component->call('tandaiRtlSesuai', $rtl->id)
            ->call('verifikasiSelesai')
            ->assertHasNoErrors();

        $this->assertSame('terverifikasi', $rtl->fresh()->status_verifikasi);
    }

    /**
     * Poin RTL yang BARU DITETAPKAN Ketua Tim triwulan ini untuk triwulan BERIKUTNYA
     * (periode_id-nya TW4 2026, satu triwulan SETELAH periode capaian TW3 2026 milik
     * siapkanIkuDenganDuaKegiatan()) — beda dari siapkanRtlDenganRealisasi() yang
     * periodenya SAMA dengan capaian (realisasi RTL triwulan sebelumnya).
     */
    protected function siapkanRtlBerikutnyaBaruDitetapkan(array $data, string $statusVerifikasi = 'menunggu'): RtlEvaluasi
    {
        $periodeBerikutnya = Periode::create([
            'tahun' => 2026, 'bulan' => 10, 'triwulan' => 4, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false,
        ]);

        return RtlEvaluasi::create([
            'iku_id' => $data['iku']->id,
            'periode_id' => $periodeBerikutnya->id,
            'rtl_teks' => 'Rencana triwulan berikutnya',
            'berlaku_bulan' => 'RTL untuk Oktober, November, dan Desember',
            'pic' => 'Uji',
            'batas_waktu' => '2026-12-31',
            'status_verifikasi' => $statusVerifikasi,
        ]);
    }

    /**
     * Regresi: rtlBerikutnyaBaruDitetapkan() sebelumnya tidak punya orderBy() —
     * urutan baris dari DB tidak dijamin stabil antar query (terutama Postgres di
     * produksi), jadi tiap kali Tim SAKIP menandai satu poin Sesuai/Tidak Sesuai
     * (memicu render ulang & query ulang), urutan poin di layar bisa berubah-ubah
     * — terlihat seperti poin "berpindah posisi" sendiri.
     */
    public function test_urutan_rtl_berikutnya_tetap_stabil_setelah_satu_poin_ditandai(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        $periodeBerikutnya = Periode::create([
            'tahun' => 2026, 'bulan' => 10, 'triwulan' => 4, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false,
        ]);

        $poin1 = RtlEvaluasi::create([
            'iku_id' => $data['iku']->id, 'periode_id' => $periodeBerikutnya->id,
            'rtl_teks' => 'csaca', 'pic' => 'Uji', 'batas_waktu' => '2026-12-31',
        ]);
        $poin2 = RtlEvaluasi::create([
            'iku_id' => $data['iku']->id, 'periode_id' => $periodeBerikutnya->id,
            'rtl_teks' => 'dasdfas', 'pic' => 'Uji', 'batas_waktu' => '2026-12-31',
        ]);
        $poin3 = RtlEvaluasi::create([
            'iku_id' => $data['iku']->id, 'periode_id' => $periodeBerikutnya->id,
            'rtl_teks' => 'fascfa', 'pic' => 'Uji', 'batas_waktu' => '2026-12-31',
        ]);

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiRtlBerikutnyaSesuai', $poin2->id);

        $this->assertSame(
            [$poin1->id, $poin2->id, $poin3->id],
            $component->instance()->rtlBerikutnyaBaruDitetapkan()->pluck('id')->all()
        );
    }

    public function test_rtl_berikutnya_baru_ditetapkan_wajib_ditandai_sebelum_verifikasi_selesai(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $rtlBerikutnya = $this->siapkanRtlBerikutnyaBaruDitetapkan($data);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->assertSee('Rencana triwulan berikutnya')
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            // rtlBerikutnya sengaja TIDAK ditandai — harus menghalangi Verifikasi Selesai.
            ->call('verifikasiSelesai')
            ->assertHasErrors(['berkas']);

        $this->assertSame('diajukan', $data['kegiatan1']->fresh()->status_dokumen);
        $this->assertSame('menunggu', $rtlBerikutnya->fresh()->status_verifikasi);
    }

    public function test_status_perubahan_belum_disimpan_pada_verifikasi(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->assertSet('adaPerubahan', false)
            // Tanda Sesuai langsung tersimpan sendiri — bukan "perubahan belum disimpan".
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->assertSet('adaPerubahan', false)
            ->set('analisis_capaian', 'Analisis baru')
            ->assertSet('adaPerubahan', true)
            ->call('simpanPerubahan')
            ->assertSet('adaPerubahan', false)
            ->assertNotSet('terakhirDisimpan', null);

        $this->assertSame('Analisis baru', $data['capaian']->fresh()->analisis_capaian);
    }

    public function test_panel_syarat_merinci_isian_belum_ditandai_dan_yang_ditolak(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->assertSee('isian belum ditandai')
            ->assertSee($data['berkas1']->nama_file)
            ->set("catatanBerkas.{$data['berkas1']->id}", 'Bukan dokumen yang diminta')
            ->call('tandaiTolak', $data['berkas1']->id)
            ->assertSee('akan dikembalikan ke Ketua Tim')
            ->assertSee('Bukan dokumen yang diminta');

        $ditolak = $component->instance()->daftarIsianDitolak();
        $this->assertCount(1, $ditolak);
        $this->assertSame('Bukan dokumen yang diminta', $ditolak[0]['catatan']);

        $component->set("koreksiKegiatan.{$data['kegiatan1']->id}", '');
        $this->assertNotEmpty($component->instance()->syaratLainVerifikasiSelesai());
    }

    public function test_rtl_berikutnya_sesuai_meloloskan_verifikasi_selesai(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $rtlBerikutnya = $this->siapkanRtlBerikutnyaBaruDitetapkan($data);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->call('tandaiRtlBerikutnyaSesuai', $rtlBerikutnya->id)
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('verifikasiSelesai')
            ->assertHasNoErrors();

        $this->assertSame('terverifikasi', $rtlBerikutnya->fresh()->status_verifikasi);
        $this->assertSame('diverifikasi', $data['capaian']->fresh()->status);
    }

    /**
     * Regresi: sama seperti test_tandai_kendala_tolak_dengan_catatan_langsung_tercermin_di_render()
     * di atas, tapi untuk rtlBerikutnyaBaruDitetapkan() ($cacheRtlBerikutnya) —
     * sebelumnya satu klik "Sesuai" sudah tersimpan ke DB tapi render() pada
     * request yang sama masih menampilkan status "menunggu" (cache basi), jadi
     * tombol "Simpan Verifikasi" + label "✓ Tersimpan" tidak muncul sampai
     * pengguna mengklik ulang (memicu request baru dengan cache yang benar-benar
     * segar).
     */
    public function test_tandai_rtl_berikutnya_sesuai_langsung_tercermin_di_render(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $rtlBerikutnya = $this->siapkanRtlBerikutnyaBaruDitetapkan($data);

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiRtlBerikutnyaSesuai', $rtlBerikutnya->id)
            ->assertHasNoErrors()
            ->assertSee('Tersimpan');

        $this->assertSame(
            'terverifikasi',
            $component->instance()->rtlBerikutnyaBaruDitetapkan()->firstWhere('id', $rtlBerikutnya->id)->status_verifikasi
        );
    }

    /**
     * PIC Tindak Lanjut boleh dikosongkan Ketua Tim saat mengajukan (lihat
     * PengisianKegiatanTest::test_ajukan_isian_berhasil_meski_pic_tindak_lanjut_kosong())
     * — Tim SAKIP yang wajib mengisi/mengonfirmasinya di sini sebelum "Verifikasi
     * Selesai" bisa ditekan.
     */
    public function test_verifikasi_selesai_gagal_bila_pic_rtl_berikutnya_belum_diisi(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        $periodeBerikutnya = Periode::create([
            'tahun' => 2026, 'bulan' => 10, 'triwulan' => 4, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false,
        ]);

        $rtlBerikutnya = RtlEvaluasi::create([
            'iku_id' => $data['iku']->id, 'periode_id' => $periodeBerikutnya->id,
            'rtl_teks' => 'Rencana tanpa PIC', 'batas_waktu' => '2026-12-31',
        ]);

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->call('tandaiRtlBerikutnyaSesuai', $rtlBerikutnya->id)
            // PIC kosong terisi bawaan tim IKU; dikosongkan manual untuk menguji gerbangnya.
            ->assertSet('picRtlBerikutnyaTerpilih', $data['iku']->namaTimList())
            ->set('picRtlBerikutnyaTerpilih', [])
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('verifikasiSelesai')
            ->assertHasErrors(['picRtlBerikutnyaTerpilih']);

        $this->assertNull($rtlBerikutnya->fresh()->pic);

        // Setelah Tim SAKIP mengisi PIC, verifikasi selesai baru boleh lanjut & PIC
        // tersimpan ke baris RTL berikutnya.
        $component->set('picRtlBerikutnyaTerpilih', ['Tim Uji Verifikasi'])
            ->call('verifikasiSelesai')
            ->assertHasNoErrors();

        $this->assertSame('Tim Uji Verifikasi', $rtlBerikutnya->fresh()->pic);
        $this->assertSame('diverifikasi', $data['capaian']->fresh()->status);
    }

    /**
     * RF baru: PIC Tindak Lanjut boleh diisi lebih dari satu tim di sini juga —
     * ditambah satu per satu lewat chip (tambahPicRtlBerikutnya()), tersimpan sebagai
     * satu string dipisah koma ke rtl_evaluasi.pic.
     */
    public function test_verifikasi_selesai_bisa_menyimpan_lebih_dari_satu_pic_rtl_berikutnya(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        $periodeBerikutnya = Periode::create([
            'tahun' => 2026, 'bulan' => 10, 'triwulan' => 4, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false,
        ]);

        $rtlBerikutnya = RtlEvaluasi::create([
            'iku_id' => $data['iku']->id, 'periode_id' => $periodeBerikutnya->id,
            'rtl_teks' => 'Rencana dua PIC', 'batas_waktu' => '2026-12-31',
        ]);

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->call('tandaiRtlBerikutnyaSesuai', $rtlBerikutnya->id)
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->set('picRtlBerikutnyaTerpilih', [])
            ->set('picRtlBerikutnyaBaru', 'Tim Pertama')
            ->call('tambahPicRtlBerikutnya')
            ->set('picRtlBerikutnyaBaru', 'Tim Kedua')
            ->call('tambahPicRtlBerikutnya')
            ->assertSet('picRtlBerikutnyaTerpilih', ['Tim Pertama', 'Tim Kedua'])
            ->call('verifikasiSelesai')
            ->assertHasNoErrors();

        $this->assertSame('Tim Pertama, Tim Kedua', $rtlBerikutnya->fresh()->pic);
    }

    public function test_tandai_rtl_berikutnya_tolak_wajib_catatan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $rtlBerikutnya = $this->siapkanRtlBerikutnyaBaruDitetapkan($data);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiRtlBerikutnyaTolak', $rtlBerikutnya->id)
            ->assertHasErrors(['catatanRtlBerikutnya.'.$rtlBerikutnya->id]);

        $this->assertSame('menunggu', $rtlBerikutnya->fresh()->status_verifikasi);
    }

    public function test_kembalikan_ke_ketua_tim_bisa_dipicu_rtl_berikutnya_ditolak(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $rtlBerikutnya = $this->siapkanRtlBerikutnyaBaruDitetapkan($data);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->set('catatanRtlBerikutnya.'.$rtlBerikutnya->id, 'Rencana belum spesifik')
            ->call('tandaiRtlBerikutnyaTolak', $rtlBerikutnya->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('kembalikanKeKetuaTim')
            ->assertHasNoErrors();

        $rtlBerikutnya->refresh();
        $this->assertSame('ditolak', $rtlBerikutnya->status_verifikasi);
        $this->assertSame('Rencana belum spesifik', $rtlBerikutnya->catatan);
        $this->assertSame('dikembalikan', $data['capaian']->fresh()->status);

        $riwayat = $data['capaian']->fresh()->riwayatStatus->first();
        $this->assertStringContainsString('Rencana belum spesifik', (string) $riwayat->catatan);
    }

    public function test_berkas_list_menggabungkan_bukti_capaian_dan_rtl(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

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
        $this->assertContains('bukti-rtl.pdf', $namaBerkas);
        $this->assertCount(3, $namaBerkas);
    }

    public function test_verifikasi_selesai_mencatat_satu_riwayat_status_diverifikasi(): void
    {
        $sakip = $this->buatSakip();
        $this->actingAs($sakip);
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('verifikasiSelesai')
            ->assertHasNoErrors();

        // Dua kegiatan diverifikasi BERSAMAAN, tapi harus tercatat sebagai SATU poin
        // riwayat "diverifikasi" saja (bukan satu per kegiatan) karena keduanya
        // berbagi satu Capaian -- ditambah SATU baris "sedang ditangani" yang otomatis
        // tercatat begitu berkas pertama ditandai Sesuai (lihat tandaiSedangDitangani()).
        $this->assertDatabaseCount('riwayat_status_capaian', 2);

        $riwayat = $data['capaian']->fresh()->riwayatStatus;
        $this->assertSame('diverifikasi', $riwayat->first()->status);
        $this->assertSame($sakip->id, $riwayat->first()->user_id);
        $this->assertSame('sedang_ditangani', $riwayat->last()->status);
    }

    public function test_kembalikan_ke_ketua_tim_mencatat_riwayat_dikembalikan_dengan_catatan(): void
    {
        $sakip = $this->buatSakip();
        $this->actingAs($sakip);
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->set('catatanBerkas.'.$data['berkas1']->id, 'Bukti tidak jelas')
            ->call('tandaiTolak', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->call('kembalikanKeKetuaTim')
            ->assertHasNoErrors();

        // Ditambah SATU baris "sedang ditangani" yang otomatis tercatat begitu berkas1
        // ditandai Tidak Sesuai (panggilan tandai* pertama) -- lihat tandaiSedangDitangani().
        $this->assertDatabaseCount('riwayat_status_capaian', 2);

        $riwayat = $data['capaian']->fresh()->riwayatStatus;
        $this->assertSame('dikembalikan', $riwayat->first()->status);
        $this->assertSame($sakip->id, $riwayat->first()->user_id);
        $this->assertSame('Bukti tidak jelas', $riwayat->first()->catatan);
        $this->assertSame('sedang_ditangani', $riwayat->last()->status);
    }

    public function test_kegiatan_tambahan_pada_iku_dan_bulan_yang_sama_tergabung_satu_riwayat(): void
    {
        $sakip = $this->buatSakip();
        $this->actingAs($sakip);
        $data = $this->siapkanIkuDenganDuaKegiatan();

        // Kegiatan pertama diverifikasi lebih dulu.
        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('verifikasiSelesai')
            ->assertHasNoErrors();

        // Ketua Tim mengajukan kegiatan TAMBAHAN pada IKU+bulan yang sama.
        $kegiatan3 = Kegiatan::create([
            'iku_id' => $data['iku']->id,
            'periode_id' => $data['periode']->id,
            'uraian_kegiatan' => 'Kegiatan tambahan',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIAJUKAN,
        ]);

        $berkas3 = Berkas::create([
            'ref_id' => $kegiatan3->id,
            'ref_type' => Kegiatan::class,
            'kategori' => 'capaian',
            'nama_file' => 'bukti3.pdf',
            'status_verifikasi' => 'menunggu',
        ]);

        // Simulasi efek samping PengisianKegiatan::ajukanIsian() (di luar cakupan tes
        // ini) yang menarik Capaian kembali ke "diajukan" begitu ada kegiatan baru.
        // refresh() dulu wajib di sini — instance ini masih ingat atribut lama sejak
        // dibuat (sebelum verifikasiSelesai() mengubahnya ke "diverifikasi" lewat
        // instance LAIN di komponen Livewire di atas), jadi tanpa refresh() Eloquent
        // menganggap 'status' tidak berubah (masih 'diajukan' menurut memorinya) dan
        // diam-diam TIDAK mengirim query UPDATE sama sekali.
        $data['capaian']->refresh()->update(['status' => Capaian::STATUS_DIAJUKAN]);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->set('catatanBerkas.'.$berkas3->id, 'Bukti kegiatan tambahan tidak sesuai')
            ->call('tandaiTolak', $berkas3->id)
            ->call('tandaiUraianSesuai', $kegiatan3->id)
            ->call('kembalikanKeKetuaTim')
            ->assertHasNoErrors();

        // Satu Capaian (IKU+bulan yang sama) menyimpan KEEMPAT riwayat itu tergabung
        // dalam satu timeline, bukan tersebar/terputus per batch pengajuan -- dua
        // "sedang ditangani" tambahan (satu per batch, lihat tandaiSedangDitangani())
        // diselingi di antara "diverifikasi" (batch 1) dan "dikembalikan" (batch 2).
        $riwayat = $data['capaian']->fresh()->riwayatStatus;
        $this->assertCount(4, $riwayat);
        $this->assertSame(
            ['dikembalikan', 'sedang_ditangani', 'diverifikasi', 'sedang_ditangani'],
            $riwayat->pluck('status')->all()
        );
    }

    /**
     * Siapkan skenario: kegiatan1 & kegiatan2 sudah diverifikasi lebih dulu, lalu
     * kegiatan3 ditambahkan belakangan pada IKU+bulan yang sama sehingga Capaian
     * ditarik kembali ke "diajukan" — dipakai oleh kedua tes di bawah untuk
     * memastikan kegiatan1/kegiatan2 yang SUDAH diverifikasi tetap bisa dikoreksi
     * Tim SAKIP selagi Capaian-nya sendiri masih bisa diverifikasi.
     */
    protected function siapkanSkenarioKegiatanTambahan(): array
    {
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('verifikasiSelesai')
            ->assertHasNoErrors();

        $kegiatan3 = Kegiatan::create([
            'iku_id' => $data['iku']->id,
            'periode_id' => $data['periode']->id,
            'uraian_kegiatan' => 'Kegiatan tambahan',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIAJUKAN,
        ]);

        $berkas3 = Berkas::create([
            'ref_id' => $kegiatan3->id,
            'ref_type' => Kegiatan::class,
            'kategori' => 'capaian',
            'nama_file' => 'bukti3.pdf',
            'status_verifikasi' => 'menunggu',
        ]);

        $data['capaian']->refresh()->update(['status' => Capaian::STATUS_DIAJUKAN]);

        return $data + ['kegiatan3' => $kegiatan3, 'berkas3' => $berkas3];
    }

    public function test_kegiatan_yang_sudah_diverifikasi_tetap_bisa_dikoreksi_saat_ada_kegiatan_tambahan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanSkenarioKegiatanTambahan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->assertSet("koreksiKegiatan.{$data['kegiatan1']->id}", 'Kegiatan pertama')
            ->set("koreksiKegiatan.{$data['kegiatan1']->id}", 'Kegiatan pertama (dikoreksi)')
            ->set("koreksiKegiatan.{$data['kegiatan3']->id}", 'Kegiatan tambahan (dikoreksi)')
            ->set('catatanBerkas.'.$data['berkas3']->id, 'Bukti kegiatan tambahan tidak sesuai')
            ->call('tandaiTolak', $data['berkas3']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan3']->id)
            ->call('kembalikanKeKetuaTim')
            ->assertHasNoErrors();

        // kegiatan1 (sudah diverifikasi sebelumnya) tetap ikut tersunting selagi
        // Capaian-nya sendiri masih bisa diverifikasi...
        $this->assertSame('Kegiatan pertama (dikoreksi)', $data['kegiatan1']->fresh()->uraian_kegiatan);
        // ...sama seperti kegiatan3 (baru, masih "diajukan").
        $this->assertSame('Kegiatan tambahan (dikoreksi)', $data['kegiatan3']->fresh()->uraian_kegiatan);
    }

    public function test_berkas_kegiatan_yang_sudah_diverifikasi_tetap_bisa_ditandai_ulang(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanSkenarioKegiatanTambahan();

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()]);

        $this->assertTrue($component->instance()->berkasBisaDiverifikasi($data['berkas1']->id));

        $component->set('catatanBerkas.'.$data['berkas1']->id, 'Ternyata perlu diperbaiki lagi')
            ->call('tandaiTolak', $data['berkas1']->id);

        // berkas1 milik kegiatan1 yang sudah diverifikasi sebelumnya tetap boleh
        // ditandai ulang selagi Capaian-nya sendiri masih bisa diverifikasi.
        $this->assertSame('ditolak', $data['berkas1']->fresh()->status_verifikasi);
    }

    public function test_buka_kembali_menarik_capaian_disetujui_ke_dikembalikan(): void
    {
        $sakip = $this->buatSakip();
        $this->actingAs($sakip);
        $data = $this->siapkanIkuDenganDuaKegiatan();

        $data['capaian']->update(['status' => Capaian::STATUS_DISETUJUI]);
        $data['kegiatan1']->update(['status_dokumen' => Kegiatan::STATUS_DISETUJUI]);
        $data['kegiatan2']->update(['status_dokumen' => Kegiatan::STATUS_DISETUJUI]);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->assertSet('catatanBukaKembali', '')
            ->set('catatanBukaKembali', 'Ada kegiatan tambahan yang perlu dilaporkan')
            ->call('bukaKembali');

        $capaian = $data['capaian']->fresh();
        $this->assertSame(Capaian::STATUS_DIKEMBALIKAN, $capaian->status);

        // Kegiatan yang sudah disetujui sebelumnya TIDAK ikut berubah/terhapus.
        $this->assertSame(Kegiatan::STATUS_DISETUJUI, $data['kegiatan1']->fresh()->status_dokumen);
        $this->assertSame(Kegiatan::STATUS_DISETUJUI, $data['kegiatan2']->fresh()->status_dokumen);

        $riwayat = $capaian->riwayatStatus->first();
        $this->assertSame('dikembalikan', $riwayat->status);
        $this->assertSame($sakip->id, $riwayat->user_id);
        $this->assertSame('Ada kegiatan tambahan yang perlu dilaporkan', $riwayat->catatan);
    }

    public function test_buka_kembali_ditolak_bila_status_bukan_disetujui(): void
    {
        $sakip = $this->buatSakip();
        $this->actingAs($sakip);
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('bukaKembali');

        $this->assertDatabaseCount('riwayat_status_capaian', 0);
    }

    public function test_simpan_perubahan_bisa_dipanggil_pada_status_apa_pun(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        // Capaian sudah "diverifikasi" (bukan "diajukan") — verifikasiSelesai()/
        // kembalikanKeKetuaTim() tidak lagi bisa dipanggil, tapi simpanPerubahan()
        // tetap harus bisa menyimpan Analisis Capaian & Target/Realisasi Triwulanan.
        $data['capaian']->update(['status' => Capaian::STATUS_DIVERIFIKASI]);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->set('analisis_capaian', 'Analisis disunting Tim SAKIP')
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $capaian = $data['capaian']->fresh();
        $this->assertSame('Analisis disunting Tim SAKIP', $capaian->analisis_capaian);
        $this->assertSame('diverifikasi', $capaian->status);

        $capaianTahunan = CapaianTahunan::where('iku_id', $data['iku']->id)->where('tahun', 2026)->first();
        $this->assertEquals(50, $capaianTahunan->alokasi_tw3);
        $this->assertEquals(45, $capaianTahunan->realisasi_tw3);
    }

    /**
     * Baik ketika Kepala mengembalikan langsung ke Ketua Tim (App\Services\NotulaService::
     * kembalikanIsian()) MAUPUN ketika Tim SAKIP sendiri yang mengembalikan
     * (kembalikanKeKetuaTim()), Capaian::status berakhir sama-sama "dikembalikan" —
     * di kedua kasus itu Tim SAKIP harus tetap bisa membetulkan uraian kegiatan lewat
     * simpanPerubahan(), sama seperti saat "diajukan"/"sedang ditangani". Sebelumnya
     * kegiatanBisaDikoreksi() salah mengunci lewat bisaDiverifikasi() (hanya true untuk
     * diajukan/sedang ditangani), padahal banner di puncak halaman verifikasi sudah
     * menjanjikan field ini "selalu bisa disunting Tim SAKIP ... kapan pun".
     */
    public function test_koreksi_uraian_kegiatan_tetap_tersimpan_saat_capaian_dikembalikan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        $data['capaian']->update(['status' => Capaian::STATUS_DIKEMBALIKAN]);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->set("koreksiKegiatan.{$data['kegiatan1']->id}", 'Kegiatan pertama (dibetulkan Tim SAKIP saat dikembalikan)')
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $this->assertSame(
            'Kegiatan pertama (dibetulkan Tim SAKIP saat dikembalikan)',
            $data['kegiatan1']->fresh()->uraian_kegiatan
        );

        // Capaian::status tidak ikut berubah -- simpanPerubahan() murni koreksi teks,
        // bukan transisi alur kerja (itu tugas verifikasiSelesai()/kembalikanKeKetuaTim()).
        $this->assertSame(Capaian::STATUS_DIKEMBALIKAN, $data['capaian']->fresh()->status);
    }

    /**
     * Capaian yang sudah "disetujui" (masuk notula final) tetap bisa direvisi langsung
     * oleh Tim SAKIP tanpa bukaKembali() — revisinya ikut masuk ke notula: Bagian I
     * disusun ulang (isi lama dicadangkan) dan notula yang sudah disetujui dibuka
     * kembali sebagai versi baru untuk disetujui ulang Kepala.
     */
    public function test_revisi_iku_disetujui_tersimpan_dan_masuk_ke_notula_versi_baru(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        $data['capaian']->update(['status' => Capaian::STATUS_DISETUJUI]);
        $data['kegiatan1']->update(['status_dokumen' => Kegiatan::STATUS_DISETUJUI]);

        $notula = app(NotulaService::class)->untukTriwulan(2026, 3);
        $notula->update([
            'status' => Notula::STATUS_DISETUJUI,
            'bagian1_html' => '<p>Bagian I lama</p>',
            'bagian2_html' => '<p>Bagian II</p>',
            'bagian3_html' => '<p>Bagian III</p>',
        ]);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->set("koreksiKegiatan.{$data['kegiatan1']->id}", 'Kegiatan pertama (direvisi setelah disetujui)')
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $this->assertSame('Kegiatan pertama (direvisi setelah disetujui)', $data['kegiatan1']->fresh()->uraian_kegiatan);
        $this->assertSame(Capaian::STATUS_DISETUJUI, $data['capaian']->fresh()->status);

        $notula->refresh();
        $this->assertSame(Notula::STATUS_DRAFT, $notula->status);
        $this->assertSame(2, $notula->versiSaatIni());
        $this->assertSame('<p>Bagian I lama</p>', $notula->bagian1_html_cadangan);
        $this->assertNotSame('<p>Bagian I lama</p>', $notula->bagian1_html);
    }

    /**
     * Menyimpan tanpa perubahan apa pun tidak boleh membuka notula yang sudah
     * disetujui sebagai versi baru.
     */
    public function test_simpan_tanpa_perubahan_tidak_membuka_notula_disetujui(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $data['capaian']->update(['status' => Capaian::STATUS_DISETUJUI]);

        $notula = app(NotulaService::class)->untukTriwulan(2026, 3);
        $notula->update(['status' => Notula::STATUS_DISETUJUI, 'bagian1_html' => '<p>Bagian I</p>']);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $this->assertSame(Notula::STATUS_DISETUJUI, $notula->fresh()->status);
        $this->assertSame(1, $notula->fresh()->versiSaatIni());
    }

    public function test_simpan_perubahan_iku_rasio_menyimpan_kolom_x_y_bukan_alokasi_langsung(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $data['iku']->update(['metode_capaian' => 'rasio']);

        // Realisasi X kini SELALU berasal dari jumlah item Rincian N yang dicentang
        // (App\Models\RincianN, otomatis aktif untuk semua IKU rasio) — bukan lagi
        // angka manual, lihat App\Livewire\VerifikasiCapaian::syncRincianN().
        $item = RincianN::create(['iku_id' => $data['iku']->id, 'tahun' => 2026, 'uraian' => 'Item A']);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->set('x_alokasi_tw3', 2)
            ->set('y_alokasi_tw3', 3)
            ->set("rincianNPilih.{$item->id}", true)
            ->set('y_realisasi_tw3', 3)
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $capaianTahunan = CapaianTahunan::where('iku_id', $data['iku']->id)->where('tahun', 2026)->first();
        $this->assertEquals(2, $capaianTahunan->x_alokasi_tw3);
        $this->assertEquals(3, $capaianTahunan->y_alokasi_tw3);
        $this->assertNull($capaianTahunan->alokasi_tw3);

        // TW III kumulatif: X=2,Y=3 -> 66.67% alokasi; X=1,Y=3 -> 33.33% realisasi.
        $this->assertEqualsWithDelta(66.67, $capaianTahunan->alokasiKumulatif(3), 0.01);
        $this->assertEqualsWithDelta(33.33, $capaianTahunan->realisasiKumulatif(3), 0.01);
    }

    /**
     * Baris "Realisasi Kumulatif (%)" (X kumulatif 2 ÷ Y 3 × 100) tampil 66,66667 --
     * tepat 5 desimal -- di Verifikasi Capaian DAN di kolom Realisasi notula, dari
     * nilai yang sama; nilai perhitungan capaian tetap 66.67.
     */
    public function test_realisasi_kumulatif_lima_desimal_sama_di_verifikasi_dan_notula(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $data['iku']->update(['metode_capaian' => 'rasio', 'satuan' => 'Persen', 'sasaran' => 'Sasaran Uji Lima Desimal']);

        RincianN::create(['iku_id' => $data['iku']->id, 'tahun' => 2026, 'uraian' => 'Item TW I', 'triwulan_realisasi' => 1]);
        RincianN::create(['iku_id' => $data['iku']->id, 'tahun' => 2026, 'uraian' => 'Item TW II', 'triwulan_realisasi' => 2]);
        CapaianTahunan::create([
            'iku_id' => $data['iku']->id, 'tahun' => 2026,
            'x_realisasi_tw1' => 1, 'x_realisasi_tw2' => 1,
            'y_realisasi_tw1' => 3, 'y_realisasi_tw2' => 3, 'y_realisasi_tw3' => 3, 'y_realisasi_tw4' => 3,
            'x_alokasi_tw3' => 2, 'y_alokasi_tw3' => 3, 'y_alokasi_tw4' => 3,
        ]);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->assertSeeHtml('<td style="text-align:center">66,66667</td>');

        $capaianTahunan = CapaianTahunan::where('iku_id', $data['iku']->id)->first();
        $this->assertSame(66.67, $capaianTahunan->realisasiKumulatif(3));

        $data['capaian']->update(['status' => Capaian::STATUS_DIVERIFIKASI]);
        $notula = app(NotulaService::class)->untukTriwulan(2026, 3);
        $path = tempnam(sys_get_temp_dir(), 'lima-desimal').'.docx';

        try {
            app(\App\Services\NotulaBagian1DocxService::class)->generate($notula, app(NotulaService::class)->kumpulkanDataBagianSatu($notula), $path);
            $zip = new \ZipArchive;
            $zip->open($path);
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            @unlink($path);
        }

        // Kolom Target (Alokasi Kumulatif) tetap 66.67 apa adanya -- hanya kolom
        // Realisasi yang memakai format 5 desimal.
        $this->assertStringContainsString('>66,66667<', $xml);
        $this->assertStringContainsString('>66.67<', $xml);
    }

    /**
     * IKU 'langsung': Realisasi (kumulatif s.d. TW ini) yang diketik 5,55556 tersimpan
     * utuh (bukan terpotong 5,56), tampil 5 desimal di input Verifikasi dan di kolom
     * Realisasi notula.
     */
    public function test_realisasi_langsung_tersimpan_dan_tampil_lima_desimal(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $data['iku']->update(['metode_capaian' => 'langsung', 'satuan' => 'Poin', 'sasaran' => 'Sasaran Uji Langsung']);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->set('alokasi_tw3', 10)
            ->set('realisasi_tw3', '5.55556')
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $capaianTahunan = CapaianTahunan::where('iku_id', $data['iku']->id)->first();
        $this->assertSame('5.55556', $capaianTahunan->realisasi_tw3);
        $this->assertSame(5.55556, $capaianTahunan->realisasiKumulatif(3));

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->assertSet('realisasi_tw3', '5.55556')
            ->assertSeeHtml('step="0.00001"');

        $data['capaian']->update(['status' => Capaian::STATUS_DIVERIFIKASI]);
        $notula = app(NotulaService::class)->untukTriwulan(2026, 3);
        $path = tempnam(sys_get_temp_dir(), 'lima-desimal-langsung').'.docx';

        try {
            app(\App\Services\NotulaBagian1DocxService::class)->generate($notula, app(NotulaService::class)->kumpulkanDataBagianSatu($notula), $path);
            $zip = new \ZipArchive;
            $zip->open($path);
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            @unlink($path);
        }

        $this->assertStringContainsString('>5,55556<', $xml);
    }

    public function test_memilih_rincian_n_menentukan_realisasi_x_otomatis(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $data['iku']->update(['metode_capaian' => 'rasio']);

        // Item lama sudah direalisasikan TW I (sesi bulan lain sebelumnya) -- harus
        // terkunci, tidak boleh ikut dipilih/berubah dari sesi TW III (periode ini).
        $terkunci = RincianN::create(['iku_id' => $data['iku']->id, 'tahun' => 2026, 'uraian' => 'Sudah TW I', 'triwulan_realisasi' => 1]);
        $itemA = RincianN::create(['iku_id' => $data['iku']->id, 'tahun' => 2026, 'uraian' => 'Item A']);
        $itemB = RincianN::create(['iku_id' => $data['iku']->id, 'tahun' => 2026, 'uraian' => 'Item B']);

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()]);

        $this->assertCount(2, $component->instance()->rincianNBisaDipilih());
        $this->assertCount(1, $component->instance()->rincianNTerkunci());

        $component->set("rincianNPilih.{$itemA->id}", true)
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $this->assertEquals(3, $itemA->fresh()->triwulan_realisasi);
        $this->assertNull($itemB->fresh()->triwulan_realisasi);
        $this->assertEquals(1, $terkunci->fresh()->triwulan_realisasi);

        $capaianTahunan = CapaianTahunan::where('iku_id', $data['iku']->id)->where('tahun', 2026)->first();
        $this->assertEquals(1, $capaianTahunan->x_realisasi_tw3);
    }

    public function test_rincian_n_bisa_disusulkan_ke_tw_sebelumnya_dari_sesi_tw_berjalan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $data['iku']->update(['metode_capaian' => 'rasio']);

        // Periode Capaian ini sendiri triwulan III (lihat siapkanIkuDenganDuaKegiatan()) --
        // item TERLEWAT dicentang saat sesi TW I/TW II, disusulkan dari sini.
        $itemTerlewatTw1 = RincianN::create(['iku_id' => $data['iku']->id, 'tahun' => 2026, 'uraian' => 'Terlewat TW I']);
        $itemTerlewatTw2 = RincianN::create(['iku_id' => $data['iku']->id, 'tahun' => 2026, 'uraian' => 'Terlewat TW II']);
        $itemTw3 = RincianN::create(['iku_id' => $data['iku']->id, 'tahun' => 2026, 'uraian' => 'Item TW III']);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->set("rincianNPilih.{$itemTerlewatTw1->id}", true)
            ->set("rincianNTw.{$itemTerlewatTw1->id}", 1)
            ->set("rincianNPilih.{$itemTerlewatTw2->id}", true)
            ->set("rincianNTw.{$itemTerlewatTw2->id}", 2)
            ->set("rincianNPilih.{$itemTw3->id}", true)
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $this->assertEquals(1, $itemTerlewatTw1->fresh()->triwulan_realisasi);
        $this->assertEquals(2, $itemTerlewatTw2->fresh()->triwulan_realisasi);
        $this->assertEquals(3, $itemTw3->fresh()->triwulan_realisasi);

        $capaianTahunan = CapaianTahunan::where('iku_id', $data['iku']->id)->where('tahun', 2026)->first();
        $this->assertEquals(1, $capaianTahunan->x_realisasi_tw1);
        $this->assertEquals(1, $capaianTahunan->x_realisasi_tw2);
        $this->assertEquals(1, $capaianTahunan->x_realisasi_tw3);

        // Setelah tersimpan, item yang disusulkan ke TW I/II langsung terkunci --
        // tidak lagi muncul di daftar yang bisa dipilih pada render berikutnya, dari
        // sesi TW III manapun (matching Excel: sekali diketik, tidak diketik ulang).
        // itemTw3 TETAP bisa dipilih (boleh diamend selama masih dalam sesi TW III
        // ini sendiri, sama seperti perilaku lama).
        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()]);
        $this->assertCount(1, $component->instance()->rincianNBisaDipilih());
        $this->assertCount(2, $component->instance()->rincianNTerkunci());
    }

    public function test_edit_pilihan_rincian_n_membuka_kunci_item_tw_sebelumnya(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $data['iku']->update(['metode_capaian' => 'rasio']);

        // Periode triwulan III -- item salah centang di TW I & TW II dikoreksi dari sini.
        $salahTw1 = RincianN::create(['iku_id' => $data['iku']->id, 'tahun' => 2026, 'uraian' => 'Salah TW I', 'triwulan_realisasi' => 1]);
        $pindahTw2 = RincianN::create(['iku_id' => $data['iku']->id, 'tahun' => 2026, 'uraian' => 'Pindah TW II', 'triwulan_realisasi' => 2]);

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()]);
        $this->assertCount(2, $component->instance()->rincianNTerkunci());

        $component->call('mulaiEditRincianN')
            ->assertSet('editRincianN', true)
            ->assertSet("rincianNPilih.{$salahTw1->id}", true);
        $this->assertCount(0, $component->instance()->rincianNTerkunci());

        $component->call('toggleRincianN', $salahTw1->id, 1)
            ->call('toggleRincianN', $pindahTw2->id, 1)
            ->call('simpanPerubahan')
            ->assertHasNoErrors()
            ->assertSet('editRincianN', false);

        $this->assertNull($salahTw1->fresh()->triwulan_realisasi);
        $this->assertEquals(1, $pindahTw2->fresh()->triwulan_realisasi);
    }

    public function test_toggle_rincian_n_mengklaim_dan_memindah_klaim_antar_kolom_tw(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $data['iku']->update(['metode_capaian' => 'rasio']);

        // Periode Capaian ini triwulan III -- item diklaim langsung dari kolom TW I
        // (klik checkbox di kolom TW I, bukan lewat dropdown lagi), lalu klaimnya
        // dipindah ke kolom TW II, lalu dilepas sepenuhnya dari kolom TW II.
        $item = RincianN::create(['iku_id' => $data['iku']->id, 'tahun' => 2026, 'uraian' => 'Item']);

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()]);

        $component->call('toggleRincianN', $item->id, 1);
        $this->assertTrue($component->get('rincianNPilih')[$item->id]);
        $this->assertEquals(1, $component->get('rincianNTw')[$item->id]);

        // Klik lagi di kolom TW II -- klaim PINDAH ke TW II, bukan menambah entri baru.
        $component->call('toggleRincianN', $item->id, 2);
        $this->assertTrue($component->get('rincianNPilih')[$item->id]);
        $this->assertEquals(2, $component->get('rincianNTw')[$item->id]);

        // Klik lagi di kolom TW II yang SAMA (kolom klaim saat ini) -- terlepas.
        $component->call('toggleRincianN', $item->id, 2);
        $this->assertFalse($component->get('rincianNPilih')[$item->id]);

        // Belum pernah disimpan -- DB tetap kosong.
        $this->assertNull($item->fresh()->triwulan_realisasi);
    }

    public function test_item_yang_diklaim_ikut_tampil_kumulatif_di_kolom_tw_sesudahnya(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $data['iku']->update(['metode_capaian' => 'rasio']);

        // Periode Capaian ini triwulan III -- satu item diklaim TW I lewat sesi lain
        // (langsung ke DB, sudah terkunci -- masuk rincianNTerkunci()), satu lagi
        // diklaim TW II dari SESI INI (belum disimpan, masih rincianNBisaDipilih()).
        // Keduanya harus tetap tampil (read-only, ditandai "sejak TW ...") di kolom
        // TW III -- bukan menghilang begitu lewat dari kolom asalnya.
        $terkunciTw1 = RincianN::create(['iku_id' => $data['iku']->id, 'tahun' => 2026, 'uraian' => 'Terkunci TW I', 'triwulan_realisasi' => 1]);
        $diklaimTw2 = RincianN::create(['iku_id' => $data['iku']->id, 'tahun' => 2026, 'uraian' => 'Diklaim TW II']);

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()]);
        $component->call('toggleRincianN', $diklaimTw2->id, 2);

        $component->assertSeeInOrder(['Terkunci TW I', '(sejak TW I)']);
        $component->assertSeeInOrder(['Diklaim TW II', '(sejak TW II)']);
    }

    public function test_rincian_n_tidak_bisa_disusulkan_ke_tw_yang_belum_berjalan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $data['iku']->update(['metode_capaian' => 'rasio']);

        // Periode Capaian ini triwulan III -- TW IV belum berjalan, tidak boleh
        // disusulkan ke sana walau payload dimanipulasi (mis. dari console browser).
        $item = RincianN::create(['iku_id' => $data['iku']->id, 'tahun' => 2026, 'uraian' => 'Item']);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->set("rincianNPilih.{$item->id}", true)
            ->set("rincianNTw.{$item->id}", 4)
            ->call('simpanPerubahan')
            ->assertHasErrors(['rincianNTw.'.$item->id]);
    }

    public function test_simpan_perubahan_alokasi_tw_periode_ini_tidak_mengubah_tw_sesi_lain(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $data['iku']->update(['metode_capaian' => 'rasio']);

        // TW I sudah diisi lewat sesi verifikasi BULAN LAIN sebelumnya (langsung lewat
        // DB di sini, bukan lewat komponen ini) — periode Capaian ini sendiri triwulan
        // III (lihat siapkanIkuDenganDuaKegiatan()).
        CapaianTahunan::create([
            'iku_id' => $data['iku']->id,
            'tahun' => 2026,
            'x_alokasi_tw1' => 1,
            'y_alokasi_tw1' => 3,
        ]);

        // Alokasi X/Y TW III diisi Tim SAKIP sebagai angka KUMULATIF langsung TW I
        // s.d. TW III (lihat App\Models\CapaianTahunan::alokasiKumulatif(), dibaca
        // apa adanya, TIDAK dijumlahkan lagi dengan TW I) -> 2/3=66.67%, TW I (sesi
        // lain) TETAP 1/3=33.33%, tidak ikut tersentuh/berubah oleh simpanan ini.
        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->set('x_alokasi_tw3', 2)
            ->set('y_alokasi_tw3', 3)
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $capaianTahunan = CapaianTahunan::where('iku_id', $data['iku']->id)->where('tahun', 2026)->first();

        $this->assertEqualsWithDelta(33.33, $capaianTahunan->alokasiKumulatif(1), 0.01);
        $this->assertEqualsWithDelta(66.67, $capaianTahunan->alokasiKumulatif(3), 0.01);
    }

    public function test_simpan_perubahan_tidak_bisa_menimpa_tw_di_luar_periode_capaian_ini(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $data['iku']->update(['metode_capaian' => 'rasio']);

        // TW I sudah diisi lewat sesi bulan lain (langsung lewat DB) — periode Capaian
        // ini sendiri triwulan III.
        CapaianTahunan::create([
            'iku_id' => $data['iku']->id,
            'tahun' => 2026,
            'x_alokasi_tw1' => 1,
            'y_alokasi_tw1' => 3,
        ]);

        // Coba "kirim" perubahan TW I dari sesi verifikasi TW III ini (menyimulasikan
        // payload yang dimanipulasi — kolom TW I sudah dikunci read-only di tampilan
        // blade) — HARUS diabaikan, hanya TW III (triwulan periode ini) yang tersimpan.
        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->set('x_alokasi_tw1', 99)
            ->set('y_alokasi_tw1', 99)
            ->set('x_alokasi_tw3', 2)
            ->set('y_alokasi_tw3', 3)
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $capaianTahunan = CapaianTahunan::where('iku_id', $data['iku']->id)->where('tahun', 2026)->first();

        $this->assertEquals(1, $capaianTahunan->x_alokasi_tw1);
        $this->assertEquals(3, $capaianTahunan->y_alokasi_tw1);
        $this->assertEquals(2, $capaianTahunan->x_alokasi_tw3);
        $this->assertEquals(3, $capaianTahunan->y_alokasi_tw3);
    }

    public function test_realisasi_volume_ro_progres_dan_catatan_tersimpan_saat_verifikasi_selesai(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']]);

        // mount() TIDAK menyiapkan baris RO default (tidak semua IKU punya RO).
        $this->assertSame([], $component->get('rincianOutput'));

        $component->call('tambahRo');
        $kunciRo = array_key_first($component->get('rincianOutput'));

        $component
            ->set("rincianOutput.{$kunciRo}.uraian", 'Publikasi/Laporan Statistik')
            ->set("rincianOutput.{$kunciRo}.volume_ro", '2 publikasi')
            ->set("rincianOutput.{$kunciRo}.progres_persen", 80)
            ->set('catatan', 'Penjelasan tambahan dari Tim SAKIP')
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan1']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan2']->id)
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('verifikasiSelesai')
            ->assertHasNoErrors();

        $ro = RincianOutput::where('kegiatan_id', $data['kegiatan1']->id)->first();
        $this->assertNotNull($ro);
        $this->assertSame('Publikasi/Laporan Statistik', $ro->uraian);
        $this->assertSame('2 publikasi', $ro->volume_ro);
        $this->assertEquals(80, $ro->progres_persen);
        $this->assertSame('Penjelasan tambahan dari Tim SAKIP', $data['capaian']->fresh()->catatan);
    }

    public function test_tambah_dan_hapus_ro_bekerja_untuk_lebih_dari_satu_ro(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $kegiatanId = $data['kegiatan1']->id;

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tambahRo')
            ->call('tambahRo');

        // 2 baris dari tambahRo() di atas -- mount() tidak menyiapkan baris default.
        // RO tidak dikelompokkan per Kegiatan lagi -- daftar datar lintas IKU (lihat
        // komentar di properti $rincianOutput), tapi kegiatan_id-nya tetap otomatis
        // terisi Kegiatan pertama yang bisa dikoreksi (kegiatan1 di sini).
        $this->assertCount(2, $component->get('rincianOutput'));

        $kunci = array_keys($component->get('rincianOutput'));

        $component
            ->set("rincianOutput.{$kunci[0]}.uraian", 'RO Pertama')
            ->set("rincianOutput.{$kunci[0]}.volume_ro", '1 publikasi')
            ->set("rincianOutput.{$kunci[1]}.uraian", 'RO Kedua')
            ->set("rincianOutput.{$kunci[1]}.volume_ro", '2 publikasi')
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $this->assertCount(2, RincianOutput::where('kegiatan_id', $kegiatanId)->get());

        // Hapus salah satu baris yang SUDAH tersimpan -- harus langsung hilang dari DB.
        $component->call('hapusRo', $kunci[0]);

        $this->assertCount(1, RincianOutput::where('kegiatan_id', $kegiatanId)->get());
        $this->assertSame('RO Kedua', RincianOutput::where('kegiatan_id', $kegiatanId)->first()->uraian);
    }

    public function test_tambah_simpan_dan_hapus_indikator_proksi_per_triwulan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $capaian = $data['capaian']->load('periode');

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian]);
        $this->assertSame([], $component->get('indikatorProksi'));

        $component->call('tambahProksi')->call('tambahProksi')->call('tambahProksi');
        $kunci = array_keys($component->get('indikatorProksi'));

        $component
            ->set("indikatorProksi.{$kunci[0]}.uraian", 'Proksi Pertama')
            ->set("indikatorProksi.{$kunci[0]}.target", '100%')
            ->set("indikatorProksi.{$kunci[0]}.realisasi", '80%')
            ->set("indikatorProksi.{$kunci[1]}.uraian", 'Proksi Kedua')
            // Baris ketiga dibiarkan kosong -- tidak boleh tersimpan.
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $tersimpan = IndikatorProksi::where('iku_id', $capaian->iku_id)->orderBy('id')->get();
        $this->assertCount(2, $tersimpan);
        $this->assertSame($capaian->periode->tahun, $tersimpan[0]->tahun);
        $this->assertSame($capaian->periode->triwulan, $tersimpan[0]->triwulan);
        $this->assertSame('80%', $tersimpan[0]->realisasi);

        // Dibuka ulang: proksi tersimpan dimuat kembali.
        $ulang = Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian->fresh()]);
        $this->assertCount(2, $ulang->get('indikatorProksi'));

        $ulang->call('hapusProksi', (string) $tersimpan[0]->id);
        $this->assertSame(['Proksi Kedua'], IndikatorProksi::where('iku_id', $capaian->iku_id)->pluck('uraian')->all());
    }

    public function test_route_verifikasi_ditolak_untuk_peran_selain_tim_sakip(): void
    {
        $peranKetuaTim = Role::create(['nama' => 'Ketua Tim']);
        $ketuaTim = User::create([
            'nama' => 'Ketua Tim Uji',
            'username' => 'ketua-uji@example.test', 'email' => 'ketua-uji@example.test',
            'password' => 'password',
            'role_id' => $peranKetuaTim->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $this->actingAs($ketuaTim);
        $data = $this->siapkanIkuDenganDuaKegiatan();

        $this->get(route('verifikasi.show', $data['capaian']))->assertForbidden();
    }

    public function test_tim_sakip_bisa_menambah_dan_menghapus_kendala_solusi_saat_verifikasi(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $lama = KendalaSolusi::create(['iku_id' => $data['iku']->id, 'periode_id' => $data['periode']->id, 'kendala' => 'Kendala lama']);

        $komponen = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('hapusKendala', $lama->id)
            ->call('tambahKendala');

        $kunci = array_key_first($komponen->get('kendalaBaru'));

        $komponen->set("kendalaBaru.{$kunci}.kendala", 'Kendala dari SAKIP')
            ->set("kendalaBaru.{$kunci}.solusi", 'Solusi dari SAKIP')
            ->call('simpanSementara')
            ->assertHasNoErrors()
            ->assertSet('kendalaBaru', []);

        $this->assertNull($lama->fresh());
        $baru = KendalaSolusi::where('iku_id', $data['iku']->id)->sole();
        $this->assertSame('Kendala dari SAKIP', $baru->kendala);
        $this->assertSame('Solusi dari SAKIP', $baru->solusi);
        $this->assertSame('terverifikasi', $baru->status_verifikasi);
    }

    public function test_tim_sakip_bisa_menambah_poin_rtl_berikutnya_di_bulan_terakhir_triwulan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $data['periode']->update(['bulan' => 9, 'bulan_ke' => 3]);

        $komponen = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->call('tambahRtlBerikutnya');

        $kunci = array_key_first($komponen->get('rtlBerikutnyaTambahan'));

        $komponen->set("rtlBerikutnyaTambahan.{$kunci}", 'Rencana dari SAKIP')
            ->call('simpanSementara')
            ->assertHasNoErrors();

        $rtl = RtlEvaluasi::where('iku_id', $data['iku']->id)->sole();
        $this->assertSame('Rencana dari SAKIP', $rtl->rtl_teks);
        $this->assertSame('terverifikasi', $rtl->status_verifikasi);
        $this->assertSame('Uji', $rtl->pic);
        $this->assertSame('2026-12-31', $rtl->batas_waktu->toDateString());
        $this->assertSame(4, (int) $rtl->periode->triwulan);
    }

    public function test_poin_rtl_berikutnya_tidak_bisa_ditambah_sebelum_bulan_terakhir_triwulan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tambahRtlBerikutnya')
            ->assertSet('rtlBerikutnyaTambahan', []);
    }

    /**
     * Tombol navigasi bagian (bilah lengket) diambil dari HTML: urutan data-bagian, dan
     * tiap tombol harus menunjuk ke elemen id yang benar-benar dirender.
     *
     * @return array<int, string>
     */
    protected function navigasiBagian(string $html): array
    {
        preg_match_all('/data-bagian="([^"]+)"/', $html, $cocok);

        foreach ($cocok[1] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, "Tombol navigasi {$id} tidak punya bagian tujuan.");
        }

        return $cocok[1];
    }

    public function test_navigasi_bagian_verifikasi_mengikuti_bagian_yang_ada_di_pengajuan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $poin = $this->siapkanBagianKustomPoin($data);

        $html = $this->get(route('verifikasi.show', $data['capaian']))
            ->assertOk()
            ->assertSee('Periode Pengajuan')
            ->assertSee('class="bilah-lengket"', false)
            ->getContent();

        // Bulan 8 bukan akhir triwulan & tidak ada RTL triwulan lalu: bagian RTL tidak tampil.
        $this->assertSame([
            'bagian-iku',
            'bagian-kegiatan',
            'bagian-kendala',
            'bagian-kustom-'.$poin->bagian_kustom_id,
            'bagian-tindakan',
        ], $this->navigasiBagian($html));
    }

    public function test_navigasi_bagian_verifikasi_menyertakan_rtl_dan_menyembunyikan_bagian_kosong(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $data['capaian']->update(['status' => Capaian::STATUS_DISETUJUI]);
        // Poin RTL yang ditetapkan triwulan lalu untuk dilaksanakan triwulan INI (periode sasaran).
        RtlEvaluasi::create([
            'iku_id' => $data['iku']->id, 'periode_id' => $data['periode']->id,
            'rtl_teks' => 'RTL triwulan lalu', 'berlaku_bulan' => 'Juli, Agustus, dan September', 'pic' => 'PIC', 'batas_waktu' => '2026-09-30',
        ]);

        $nav = $this->navigasiBagian(Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])->html());

        $this->assertContains('bagian-evaluasi-rtl', $nav);
        // Tidak bisa diverifikasi & tidak ada kendala/bagian kustom: tombolnya tidak tampil.
        $this->assertNotContains('bagian-kendala', $nav);
        $this->assertEmpty(array_filter($nav, fn ($id) => str_starts_with($id, 'bagian-kustom-')));
    }
}
