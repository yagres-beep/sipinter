<?php

namespace Tests\Feature;

use App\Livewire\VerifikasiCapaian;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\RincianN;
use App\Models\RincianOutput;
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

        $capaianTahunan = \App\Models\CapaianTahunan::where('iku_id', $data['iku']->id)->where('tahun', 2026)->first();
        $this->assertEquals(50, $capaianTahunan->alokasi_tw3);
        $this->assertEquals(45, $capaianTahunan->realisasi_tw3);
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
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('verifikasiSelesai')
            ->assertHasErrors(['picRtlBerikutnya']);

        $this->assertNull($rtlBerikutnya->fresh()->pic);

        // Setelah Tim SAKIP mengisi PIC, verifikasi selesai baru boleh lanjut & PIC
        // tersimpan ke baris RTL berikutnya.
        $component->set('picRtlBerikutnya', 'Tim Uji Verifikasi')
            ->call('verifikasiSelesai')
            ->assertHasNoErrors();

        $this->assertSame('Tim Uji Verifikasi', $rtlBerikutnya->fresh()->pic);
        $this->assertSame('diverifikasi', $data['capaian']->fresh()->status);
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
     * memastikan kegiatan1/kegiatan2 yang SUDAH diverifikasi tidak ikut tersentuh
     * lagi hanya karena Capaian-nya kembali bisa diverifikasi.
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

    public function test_kegiatan_yang_sudah_diverifikasi_tidak_ikut_tersunting_saat_ada_kegiatan_tambahan(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanSkenarioKegiatanTambahan();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->assertSet("koreksiKegiatan.{$data['kegiatan1']->id}", 'Kegiatan pertama')
            // Dipaksa mengganti nilai lewat properti langsung (menyimulasikan payload
            // yang dimanipulasi) — textarea-nya sendiri sudah @readonly di tampilan.
            ->set("koreksiKegiatan.{$data['kegiatan1']->id}", 'Dicoba diubah paksa')
            ->set("koreksiKegiatan.{$data['kegiatan3']->id}", 'Kegiatan tambahan (dikoreksi)')
            ->set('catatanBerkas.'.$data['berkas3']->id, 'Bukti kegiatan tambahan tidak sesuai')
            ->call('tandaiTolak', $data['berkas3']->id)
            ->call('tandaiUraianSesuai', $data['kegiatan3']->id)
            ->call('kembalikanKeKetuaTim')
            ->assertHasNoErrors();

        // kegiatan1 (sudah diverifikasi sebelumnya) TIDAK ikut berubah...
        $this->assertSame('Kegiatan pertama', $data['kegiatan1']->fresh()->uraian_kegiatan);
        // ...tapi kegiatan3 (baru, masih "diajukan") tetap boleh dikoreksi.
        $this->assertSame('Kegiatan tambahan (dikoreksi)', $data['kegiatan3']->fresh()->uraian_kegiatan);
    }

    public function test_berkas_kegiatan_yang_sudah_diverifikasi_tidak_bisa_ditandai_ulang(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanSkenarioKegiatanTambahan();

        $component = Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()]);

        $this->assertFalse($component->instance()->berkasBisaDiverifikasi($data['berkas1']->id));

        $component->call('tandaiTolak', $data['berkas1']->id);

        // berkas1 milik kegiatan1 yang sudah diverifikasi sebelumnya — tetap
        // "terverifikasi", tidak ikut berubah jadi "ditolak".
        $this->assertSame('terverifikasi', $data['berkas1']->fresh()->status_verifikasi);
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

        $capaianTahunan = \App\Models\CapaianTahunan::where('iku_id', $data['iku']->id)->where('tahun', 2026)->first();
        $this->assertEquals(50, $capaianTahunan->alokasi_tw3);
        $this->assertEquals(45, $capaianTahunan->realisasi_tw3);
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

        $capaianTahunan = \App\Models\CapaianTahunan::where('iku_id', $data['iku']->id)->where('tahun', 2026)->first();
        $this->assertEquals(2, $capaianTahunan->x_alokasi_tw3);
        $this->assertEquals(3, $capaianTahunan->y_alokasi_tw3);
        $this->assertNull($capaianTahunan->alokasi_tw3);

        // TW III kumulatif: X=2,Y=3 -> 66.67% alokasi; X=1,Y=3 -> 33.33% realisasi.
        $this->assertEqualsWithDelta(66.67, $capaianTahunan->alokasiKumulatif(3), 0.01);
        $this->assertEqualsWithDelta(33.33, $capaianTahunan->realisasiKumulatif(3), 0.01);
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

        $capaianTahunan = \App\Models\CapaianTahunan::where('iku_id', $data['iku']->id)->where('tahun', 2026)->first();
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

        $capaianTahunan = \App\Models\CapaianTahunan::where('iku_id', $data['iku']->id)->where('tahun', 2026)->first();
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
        \App\Models\CapaianTahunan::create([
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

        $capaianTahunan = \App\Models\CapaianTahunan::where('iku_id', $data['iku']->id)->where('tahun', 2026)->first();

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
        \App\Models\CapaianTahunan::create([
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

        $capaianTahunan = \App\Models\CapaianTahunan::where('iku_id', $data['iku']->id)->where('tahun', 2026)->first();

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

        // mount() sudah menyiapkan satu baris kosong default (lihat komentar di
        // VerifikasiCapaian::mount()) -- tidak perlu tambahRo() manual di sini.
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

        // 3, bukan 2: mount() sudah menyiapkan satu baris kosong default (lihat
        // komentar di VerifikasiCapaian::mount()), ditambah 2 baris dari tambahRo()
        // di atas. RO tidak dikelompokkan per Kegiatan lagi -- daftar datar lintas
        // IKU (lihat komentar di properti $rincianOutput), tapi kegiatan_id-nya tetap
        // otomatis terisi Kegiatan pertama yang bisa dikoreksi (kegiatan1 di sini).
        $this->assertCount(3, $component->get('rincianOutput'));

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
}
