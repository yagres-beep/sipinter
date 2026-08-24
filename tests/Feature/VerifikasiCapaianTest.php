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

    public function test_kembalikan_ke_ketua_tim_bisa_dipicu_kendala_ditolak_tanpa_berkas_ditolak(): void
    {
        $this->actingAs($this->buatSakip());
        $data = $this->siapkanIkuDenganDuaKegiatan();
        $ks = KendalaSolusi::create(['iku_id' => $data['iku']->id, 'periode_id' => $data['periode']->id, 'kendala' => 'Kendala uji']);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']])
            ->call('tandaiSesuai', $data['berkas1']->id)
            ->call('tandaiSesuai', $data['berkas2']->id)
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

        $this->assertSame('diajukan', $data['capaian']->fresh()->status);
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
            ->set('alokasi_tw3', 50)
            ->set('realisasi_tw3', 45)
            ->call('verifikasiSelesai')
            ->assertHasNoErrors();

        // Dua kegiatan diverifikasi BERSAMAAN, tapi harus tercatat sebagai SATU poin
        // riwayat saja (bukan satu per kegiatan) karena keduanya berbagi satu Capaian.
        $this->assertDatabaseCount('riwayat_status_capaian', 1);

        $riwayat = $data['capaian']->fresh()->riwayatStatus->first();
        $this->assertSame('diverifikasi', $riwayat->status);
        $this->assertSame($sakip->id, $riwayat->user_id);
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
            ->call('kembalikanKeKetuaTim')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('riwayat_status_capaian', 1);

        $riwayat = $data['capaian']->fresh()->riwayatStatus->first();
        $this->assertSame('dikembalikan', $riwayat->status);
        $this->assertSame($sakip->id, $riwayat->user_id);
        $this->assertSame('Bukti tidak jelas', $riwayat->catatan);
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
            ->call('kembalikanKeKetuaTim')
            ->assertHasNoErrors();

        // Satu Capaian (IKU+bulan yang sama) menyimpan KEDUA riwayat itu tergabung
        // dalam satu timeline, bukan tersebar/terputus per batch pengajuan.
        $riwayat = $data['capaian']->fresh()->riwayatStatus;
        $this->assertCount(2, $riwayat);
        $this->assertSame('dikembalikan', $riwayat->first()->status);
        $this->assertSame('diverifikasi', $riwayat->last()->status);
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

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->set('x_alokasi_tw3', 2)
            ->set('y_alokasi_tw3', 3)
            ->set('x_realisasi_tw3', 1)
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

    public function test_simpan_perubahan_kumulatif_menjumlahkan_tw_dari_sesi_lain_dan_tw_periode_ini(): void
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

        // kumulatif TW III harus otomatis terjumlah dari TW I (sesi lain) + TW III
        // (sesi ini): X=1+2=3, Y=3+3=6 -> 50%, BUKAN dari X/Y TW III saja (2/3=66.67).
        Livewire::test(VerifikasiCapaian::class, ['capaian' => $data['capaian']->fresh()])
            ->set('x_alokasi_tw3', 2)
            ->set('y_alokasi_tw3', 3)
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $capaianTahunan = \App\Models\CapaianTahunan::where('iku_id', $data['iku']->id)->where('tahun', 2026)->first();

        $this->assertEqualsWithDelta(33.33, $capaianTahunan->alokasiKumulatif(1), 0.01);
        $this->assertEqualsWithDelta(50.0, $capaianTahunan->alokasiKumulatif(3), 0.01);
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
