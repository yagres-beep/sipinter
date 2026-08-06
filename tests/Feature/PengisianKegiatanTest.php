<?php

namespace Tests\Feature;

use App\Livewire\PengisianKegiatan;
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
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class PengisianKegiatanTest extends TestCase
{
    use RefreshDatabase;

    public function test_isian_kegiatan_gabungan_bisa_diajukan_sekaligus(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);

        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'email' => 'ketua-uji@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-001',
            'indikator' => 'Indikator uji coba',
            'tim' => 'Uji',
            'penanggung_jawab' => 'Ketua Uji',
        ]);

        $this->actingAs($ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9) // bulan terakhir Triwulan III — satu-satunya bulan RTL Baru boleh diisi.
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji coba')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('kendalaBlocks.0.kendala', 'Kendala uji coba')
            ->set('kendalaBlocks.0.solusi', '')
            ->set('rtlBaru.0.rtl_teks', 'RTL uji coba triwulan berikutnya')
            ->set('rtlBaruPic', 'PIC Uji')
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('kegiatan', 1);
        $this->assertDatabaseCount('capaian', 1);
        $this->assertDatabaseCount('berkas', 1);
        $this->assertDatabaseCount('kendala_solusi', 1);
        $this->assertDatabaseCount('rtl_evaluasi', 1);

        $kegiatan = Kegiatan::first();
        $this->assertSame('diajukan', $kegiatan->status_dokumen);
        $this->assertSame($iku->id, $kegiatan->iku_id);

        $capaian = Capaian::first();
        $this->assertSame($iku->id, $capaian->iku_id);
        $this->assertSame($kegiatan->periode_id, $capaian->periode_id);

        $berkas = Berkas::first();
        $this->assertSame($kegiatan->id, $berkas->ref_id);
        $this->assertSame(Kegiatan::class, $berkas->ref_type);

        $kendalaSolusi = KendalaSolusi::first();
        $this->assertSame('Kendala uji coba', $kendalaSolusi->kendala);
        $this->assertSame($iku->id, $kendalaSolusi->iku_id);

        $rtlBaru = RtlEvaluasi::first();
        $this->assertSame('RTL uji coba triwulan berikutnya', $rtlBaru->rtl_teks);
        $this->assertSame('PIC Uji', $rtlBaru->pic);
    }

    public function test_kendala_solusi_kosong_tidak_disimpan(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);

        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'email' => 'ketua-uji2@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-002',
            'indikator' => 'Indikator uji coba 2',
            'tim' => 'Uji',
            'penanggung_jawab' => 'Ketua Uji',
        ]);

        $this->actingAs($ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji coba 2')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('rtlBaru.0.rtl_teks', 'RTL uji coba 2')
            ->set('rtlBaruPic', 'PIC Uji 2')
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('kendala_solusi', 0);
    }

    public function test_solusi_tanpa_bukti_ditolak_validasi(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);

        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'email' => 'ketua-uji3@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-003',
            'indikator' => 'Indikator uji coba 3',
            'tim' => 'Uji',
            'penanggung_jawab' => 'Ketua Uji',
        ]);

        $this->actingAs($ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji coba 3')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('kendalaBlocks.0.kendala', 'Kendala tanpa bukti solusi')
            ->set('kendalaBlocks.0.solusi', 'Solusi tanpa bukti')
            ->set('rtlBaru.0.rtl_teks', 'RTL uji coba 3')
            ->set('rtlBaruPic', 'PIC Uji 3')
            ->call('ajukanIsian')
            ->assertHasErrors(['kendalaBlocks.0.bukti_solusi']);

        $this->assertDatabaseCount('kegiatan', 0);
    }

    public function test_simpan_draft_tidak_mewajibkan_bukti_dan_tidak_mengajukan(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);

        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'email' => 'ketua-uji4@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-004',
            'indikator' => 'Indikator uji coba 4',
            'tim' => 'Uji',
            'penanggung_jawab' => 'Ketua Uji',
        ]);

        $this->actingAs($ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan draf uji coba')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('kegiatan', 1);

        $kegiatan = Kegiatan::first();
        $this->assertSame('draft', $kegiatan->status_dokumen);
    }

    public function test_pratinjau_nama_folder_mengikuti_uraian_dan_tahapan(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);

        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'email' => 'ketua-uji5@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $this->actingAs($ketua);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('blocks.0.uraian_kegiatan', 'pencacahan Sakernas')
            ->set('blocks.0.jenis', 'survei_sensus')
            ->set('blocks.0.tahapan_survei', 'pelaksanaan');

        $this->assertSame('[Pelaksanaan] pencacahan Sakernas', $component->instance()->namaFolderPreview(0));
    }

    public function test_jenis_kegiatan_terkunci_sampai_uraian_kegiatan_diisi(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'email' => 'ketua-uji6@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $this->actingAs($ketua);

        $component = Livewire::test(PengisianKegiatan::class);

        $this->assertTrue($component->instance()->jenisTerkunci(0));
        $this->assertTrue($component->instance()->buktiTerkunci(0));

        $component->set('blocks.0.uraian_kegiatan', 'Kegiatan A');

        $this->assertFalse($component->instance()->jenisTerkunci(0));
        $this->assertTrue($component->instance()->buktiTerkunci(0));

        $component->set('blocks.0.jenis', 'bukan_survei_sensus');

        $this->assertFalse($component->instance()->buktiTerkunci(0));
    }

    public function test_form_lengkap_menentukan_aktif_tidaknya_tombol_ajukan(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'email' => 'ketua-uji7@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-007',
            'indikator' => 'Indikator uji coba 7',
            'tim' => 'Uji',
            'penanggung_jawab' => 'Ketua Uji',
        ]);

        $this->actingAs($ketua);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id);

        $this->assertFalse($component->instance()->formLengkap());

        $component->set('blocks.0.uraian_kegiatan', 'Kegiatan lengkap')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('rtlBaru.0.rtl_teks', 'RTL lengkap')
            ->set('rtlBaruPic', 'PIC Lengkap');

        $this->assertTrue($component->instance()->formLengkap());
    }

    public function test_rtl_baru_hanya_bisa_diisi_pada_bulan_terakhir_triwulan(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'email' => 'ketua-uji8@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $this->actingAs($ketua);

        $component = Livewire::test(PengisianKegiatan::class)->set('bulan', 7);
        $this->assertFalse($component->instance()->rtlBaruBisaDiisi());

        $component->set('bulan', 8);
        $this->assertFalse($component->instance()->rtlBaruBisaDiisi());

        $component->set('bulan', 9);
        $this->assertTrue($component->instance()->rtlBaruBisaDiisi());
    }

    public function test_ajukan_diblokir_jika_rtl_berjalan_belum_terlaksana(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'email' => 'ketua-uji9@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-009',
            'indikator' => 'Indikator uji coba 9',
            'tim' => 'Uji',
            'penanggung_jawab' => 'Ketua Uji',
        ]);

        $periodeBerjalan = Periode::create([
            'tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false,
        ]);

        RtlEvaluasi::create([
            'iku_id' => $iku->id,
            'periode_id' => $periodeBerjalan->id,
            'rtl_teks' => 'Rencana yang belum dilaksanakan',
            'berlaku_bulan' => 'Juli, Agustus, dan September',
            'pic' => 'PIC Rencana',
            'batas_waktu' => '2026-09-30',
        ]);

        $this->actingAs($ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan lain yang tidak terkait RTL')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('rtlBaru.0.rtl_teks', 'RTL baru')
            ->set('rtlBaruPic', 'PIC Uji 9')
            ->call('ajukanIsian')
            ->assertHasErrors(['blocks']);

        $this->assertDatabaseCount('kegiatan', 0);
    }

    public function test_kegiatan_yang_cocok_dengan_rtl_berjalan_menautkan_rtl_evaluasi_id(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'email' => 'ketua-uji10@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-010',
            'indikator' => 'Indikator uji coba 10',
            'tim' => 'Uji',
            'penanggung_jawab' => 'Ketua Uji',
        ]);

        $periodeBerjalan = Periode::create([
            'tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false,
        ]);

        $poinRtl = RtlEvaluasi::create([
            'iku_id' => $iku->id,
            'periode_id' => $periodeBerjalan->id,
            'rtl_teks' => 'Rencana yang belum dilaksanakan',
            'berlaku_bulan' => 'Juli, Agustus, dan September',
            'pic' => 'PIC Rencana',
            'batas_waktu' => '2026-09-30',
        ]);

        $this->actingAs($ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Rencana yang belum dilaksanakan')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('rtlBaru.0.rtl_teks', 'RTL baru')
            ->set('rtlBaruPic', 'PIC Uji 10')
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $kegiatan = Kegiatan::first();
        $this->assertSame($poinRtl->id, $kegiatan->rtl_evaluasi_id);
    }

    public function test_evaluasi_rtl_sebelumnya_minimal_satu_boleh_sebagian(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'email' => 'ketua-uji11@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-011',
            'indikator' => 'Indikator uji coba 11',
            'tim' => 'Uji',
            'penanggung_jawab' => 'Ketua Uji',
        ]);

        $periodeSebelumnya = Periode::create([
            'tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false,
        ]);

        $poin1 = RtlEvaluasi::create([
            'iku_id' => $iku->id, 'periode_id' => $periodeSebelumnya->id,
            'rtl_teks' => 'Poin 1', 'berlaku_bulan' => 'April, Mei, dan Juni', 'pic' => 'PIC', 'batas_waktu' => '2026-06-30',
        ]);

        $poin2 = RtlEvaluasi::create([
            'iku_id' => $iku->id, 'periode_id' => $periodeSebelumnya->id,
            'rtl_teks' => 'Poin 2', 'berlaku_bulan' => 'April, Mei, dan Juni', 'pic' => 'PIC', 'batas_waktu' => '2026-06-30',
        ]);

        $this->actingAs($ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 7) // bulan pertama TW III — RTL Baru belum wajib diisi di sini.
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji evaluasi')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set("evaluasi.{$poin1->id}.realisasi", 'Sudah dilaksanakan sebagian')
            ->set("evaluasi.{$poin1->id}.status_cocok", 'cocok')
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('kegiatan', 1);

        $poin1->refresh();
        $poin2->refresh();
        $this->assertTrue($poin1->sudahDievaluasi());
        $this->assertFalse($poin2->sudahDievaluasi());
    }

    public function test_pic_tindak_lanjut_terisi_otomatis_dari_penanggung_jawab_iku(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'email' => 'ketua-uji12@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-012',
            'indikator' => 'Indikator uji coba 12',
            'tim' => 'Uji Otomatis',
            'penanggung_jawab' => 'Ketua Uji',
        ]);

        \App\Models\UserTim::create(['user_id' => $ketua->id, 'tim' => 'Uji Otomatis']);

        $this->actingAs($ketua);

        $component = Livewire::test(PengisianKegiatan::class);
        $component->set('iku_id', $iku->id);

        $this->assertSame($ketua->nama, $component->get('rtlBaruPic'));
    }

    public function test_cache_lintas_request_tidak_menampilkan_data_basi_setelah_ajukan(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'email' => 'ketua-uji13@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-013',
            'indikator' => 'Indikator uji coba 13',
            'tim' => 'Uji',
            'penanggung_jawab' => 'Ketua Uji',
        ]);

        $this->actingAs($ketua);

        // Buka komponen sekali dulu di bulan terakhir TW III (2026-09) SEBELUM RTL
        // berikutnya ditetapkan — supaya cache "rtl-berikutnya-ada" sempat terisi false.
        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->assertDontSee('Sudah ditetapkan');

        // Ajukan sekaligus menetapkan RTL untuk TW IV — ini mengubah data yang tadi di-cache.
        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan cache uji')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('rtlBaru.0.rtl_teks', 'RTL cache uji')
            ->set('rtlBaruPic', 'PIC Uji 13')
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        // Komponen BARU (request "berikutnya") untuk IKU+periode yang sama harus melihat
        // RTL berikutnya SUDAH ada — bukan cache basi dari sebelum submit.
        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->assertSee('Sudah ditetapkan');
    }

    public function test_iku_id_tidak_valid_tetap_ditolak_tanpa_query_exists(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $this->actingAs(User::create([
            'nama' => 'Ketua Uji', 'email' => 'ketua-uji14@example.test', 'password' => 'password',
            'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        Livewire::test(PengisianKegiatan::class)
            ->set('iku_id', 999999)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->call('ajukanIsian')
            ->assertHasErrors(['iku_id']);

        $this->assertDatabaseCount('kegiatan', 0);
    }

    public function test_addblock_tidak_query_database_sama_sekali_saat_cache_hangat(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $this->actingAs(User::create([
            'nama' => 'Ketua Uji', 'email' => 'ketua-uji15@example.test', 'password' => 'password',
            'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $iku = MasterIku::create([
            'kode' => 'UJI-015', 'indikator' => 'Indikator uji coba 15', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji',
        ]);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('iku_id', $iku->id);

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $component->call('addBlock');

        $this->assertCount(0, \Illuminate\Support\Facades\DB::getQueryLog());
        $this->assertCount(2, $component->get('blocks'));
    }
}
