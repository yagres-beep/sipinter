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
            'username' => 'ketua-uji@example.test', 'email' => 'ketua-uji@example.test',
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
            ->set('bulan', 9) // bulan terakhir Triwulan III â€” satu-satunya bulan RTL Baru boleh diisi.
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

    public function test_ajukan_isian_mencatat_riwayat_status_diajukan(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);

        $ketua = User::create([
            'nama' => 'Ketua Uji Riwayat',
            'username' => 'ketua-uji-riwayat@example.test', 'email' => 'ketua-uji-riwayat@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-RIWAYAT',
            'indikator' => 'Indikator uji riwayat',
            'tim' => 'Uji',
            'penanggung_jawab' => 'Ketua Uji',
        ]);

        $this->actingAs($ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji riwayat')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('kendalaBlocks.0.kendala', '')
            ->set('kendalaBlocks.0.solusi', '')
            ->set('rtlBaru.0.rtl_teks', 'RTL uji riwayat')
            ->set('rtlBaruPic', 'PIC Uji Riwayat')
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('riwayat_status_capaian', 1);

        $capaian = Capaian::first();
        $riwayat = $capaian->riwayatStatus->first();
        $this->assertSame('diajukan', $riwayat->status);
        $this->assertSame($ketua->id, $riwayat->user_id);
    }

    public function test_kendala_solusi_kosong_tidak_disimpan(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);

        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'username' => 'ketua-uji2@example.test', 'email' => 'ketua-uji2@example.test',
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

    public function test_solusi_tanpa_bukti_tidak_ditolak_validasi(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);

        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'username' => 'ketua-uji3@example.test', 'email' => 'ketua-uji3@example.test',
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
            ->assertHasNoErrors();

        $this->assertDatabaseCount('kendala_solusi', 1);
    }

    public function test_simpan_draft_tidak_mewajibkan_bukti_dan_tidak_mengajukan(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);

        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'username' => 'ketua-uji4@example.test', 'email' => 'ketua-uji4@example.test',
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
            'username' => 'ketua-uji5@example.test', 'email' => 'ketua-uji5@example.test',
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
            'username' => 'ketua-uji6@example.test', 'email' => 'ketua-uji6@example.test',
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
            'username' => 'ketua-uji7@example.test', 'email' => 'ketua-uji7@example.test',
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
            'username' => 'ketua-uji8@example.test', 'email' => 'ketua-uji8@example.test',
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
            'username' => 'ketua-uji9@example.test', 'email' => 'ketua-uji9@example.test',
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
            'username' => 'ketua-uji10@example.test', 'email' => 'ketua-uji10@example.test',
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
            ->set("evaluasi.{$poinRtl->id}.bukti", [UploadedFile::fake()->create('realisasi.pdf', 100, 'application/pdf')])
            ->set('rtlBaru.0.rtl_teks', 'RTL baru')
            ->set('rtlBaruPic', 'PIC Uji 10')
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $kegiatan = Kegiatan::first();
        $this->assertSame($poinRtl->id, $kegiatan->rtl_evaluasi_id);
    }

    protected function siapkanIkuDanRtlSebelumnya(string $emailKetua): array
    {
        $peranKetua = Role::firstOrCreate(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji', 'username' => $emailKetua, 'email' => $emailKetua, 'password' => 'password',
            'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-'.uniqid(), 'indikator' => 'Indikator uji evaluasi', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji',
        ]);

        // RTL disimpan dengan periode TARGET-nya (triwulan III, ditetapkan saat mengisi
        // triwulan II) â€” sama seperti alur nyata "RTL Baru" di PengisianKegiatan::ajukanIsian().
        $periodeBerjalan = Periode::create([
            'tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false,
        ]);

        $poin1 = RtlEvaluasi::create([
            'iku_id' => $iku->id, 'periode_id' => $periodeBerjalan->id,
            'rtl_teks' => 'Poin 1', 'berlaku_bulan' => 'Juli, Agustus, dan September', 'pic' => 'PIC', 'batas_waktu' => '2026-09-30',
        ]);

        $poin2 = RtlEvaluasi::create([
            'iku_id' => $iku->id, 'periode_id' => $periodeBerjalan->id,
            'rtl_teks' => 'Poin 2', 'berlaku_bulan' => 'Juli, Agustus, dan September', 'pic' => 'PIC', 'batas_waktu' => '2026-09-30',
        ]);

        $this->actingAs($ketua);

        return [$iku, $poin1, $poin2];
    }

    public function test_bukti_evaluasi_rtl_opsional_di_bulan_biasa(): void
    {
        [$iku, $poin1, $poin2] = $this->siapkanIkuDanRtlSebelumnya('ketua-uji11@example.test');

        // Bulan pertama TW III (bukan bulan terakhir) â€” bukti evaluasi belum wajib sama
        // sekali, termasuk boleh SEMUA poin tanpa bukti.
        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 7)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji evaluasi')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set("evaluasi.{$poin1->id}.bukti", [UploadedFile::fake()->create('realisasi1.pdf', 100, 'application/pdf')])
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('kegiatan', 1);

        $poin1->refresh();
        $poin2->refresh();
        $this->assertTrue($poin1->sudahDievaluasi());
        $this->assertFalse($poin2->sudahDievaluasi()); // tidak diberi bukti, tapi tetap boleh diajukan.
    }

    public function test_bukti_evaluasi_rtl_wajib_semua_di_bulan_terakhir_triwulan(): void
    {
        [$iku, $poin1, $poin2] = $this->siapkanIkuDanRtlSebelumnya('ketua-uji11b@example.test');

        // Bulan terakhir TW III â€” SEMUA poin evaluasi wajib punya bukti; poin2 sengaja
        // tidak diberi bukti sama sekali, jadi pengajuan harus ditolak.
        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji evaluasi')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set("evaluasi.{$poin1->id}.bukti", [UploadedFile::fake()->create('realisasi1.pdf', 100, 'application/pdf')])
            ->set('rtlBaru.0.rtl_teks', 'RTL uji coba triwulan berikutnya')
            ->set('rtlBaruPic', 'PIC Uji')
            ->call('ajukanIsian')
            ->assertHasErrors('evaluasi');

        $this->assertDatabaseCount('kegiatan', 0);
    }

    public function test_bukti_evaluasi_rtl_lengkap_semua_boleh_diajukan_bulan_terakhir(): void
    {
        [$iku, $poin1, $poin2] = $this->siapkanIkuDanRtlSebelumnya('ketua-uji11c@example.test');

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            // addBlock() dulu supaya blocks.1/.2 punya struktur emptyBlock() lengkap
            // (jenis, tahapan_survei, bukti) sebelum di-set sebagian lewat dot-notation.
            ->call('addBlock')
            ->call('addBlock')
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji evaluasi')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            // Kedua poin RTL berjalan juga wajib sudah terlaksana sebagai kegiatan (bukan
            // hanya diberi bukti realisasi) sebelum bisa diajukan di bulan terakhir triwulan.
            ->set('blocks.1.uraian_kegiatan', $poin1->rtl_teks)
            ->set('blocks.1.jenis', 'bukan_survei_sensus')
            ->set('blocks.1.bukti', [UploadedFile::fake()->create('bukti1.pdf', 100, 'application/pdf')])
            ->set('blocks.2.uraian_kegiatan', $poin2->rtl_teks)
            ->set('blocks.2.jenis', 'bukan_survei_sensus')
            ->set('blocks.2.bukti', [UploadedFile::fake()->create('bukti2.pdf', 100, 'application/pdf')])
            ->set("evaluasi.{$poin1->id}.bukti", [UploadedFile::fake()->create('realisasi1.pdf', 100, 'application/pdf')])
            ->set("evaluasi.{$poin2->id}.bukti", [UploadedFile::fake()->create('realisasi2.pdf', 100, 'application/pdf')])
            ->set('rtlBaru.0.rtl_teks', 'RTL uji coba triwulan berikutnya')
            ->set('rtlBaruPic', 'PIC Uji')
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('kegiatan', 3);
        $this->assertTrue($poin1->refresh()->sudahDievaluasi());
        $this->assertTrue($poin2->refresh()->sudahDievaluasi());
    }

    public function test_pic_tindak_lanjut_terisi_otomatis_dari_penanggung_jawab_iku(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'username' => 'ketua-uji12@example.test', 'email' => 'ketua-uji12@example.test',
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

    public function test_riwayat_kendala_solusi_kumulatif_dari_triwulan_1_sampai_berjalan(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji', 'username' => 'ketua-uji-kumulatif@example.test', 'email' => 'ketua-uji-kumulatif@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $iku = MasterIku::create(['kode' => 'UJI-KUM', 'indikator' => 'Indikator kumulatif', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);

        $periodeTw1 = Periode::create(['tahun' => 2026, 'bulan' => 2, 'triwulan' => 1, 'bulan_ke' => 2, 'flag_bulan_terlewat' => true]);
        $periodeTw2 = Periode::create(['tahun' => 2026, 'bulan' => 5, 'triwulan' => 2, 'bulan_ke' => 2, 'flag_bulan_terlewat' => true]);

        KendalaSolusi::create(['iku_id' => $iku->id, 'periode_id' => $periodeTw1->id, 'kendala' => 'Kendala triwulan pertama']);
        KendalaSolusi::create(['iku_id' => $iku->id, 'periode_id' => $periodeTw2->id, 'kendala' => 'Kendala triwulan kedua']);

        $this->actingAs($ketua);

        // Sedang mengisi bulan 8 (Agustus) = Triwulan III â€” riwayat kumulatif harus
        // tetap menampilkan kendala dari TW I dan TW II, bukan cuma TW III.
        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id)
            ->assertSee('Kendala triwulan pertama')
            ->assertSee('Kendala triwulan kedua');
    }

    public function test_cache_lintas_request_tidak_menampilkan_data_basi_setelah_ajukan(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'username' => 'ketua-uji13@example.test', 'email' => 'ketua-uji13@example.test',
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
        // berikutnya ditetapkan â€” supaya cache "rtl-berikutnya-ada" sempat terisi false.
        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->assertDontSee('Sudah ditetapkan');

        // Ajukan sekaligus menetapkan RTL untuk TW IV â€” ini mengubah data yang tadi di-cache.
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
        // RTL berikutnya SUDAH ada â€” bukan cache basi dari sebelum submit.
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
            'nama' => 'Ketua Uji', 'username' => 'ketua-uji14@example.test', 'email' => 'ketua-uji14@example.test', 'password' => 'password',
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
            'nama' => 'Ketua Uji', 'username' => 'ketua-uji15@example.test', 'email' => 'ketua-uji15@example.test', 'password' => 'password',
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

    /**
     * Deep-link dari baris tabel dasbor (App\Livewire\DasborUtama::tautanSemuaBaris) —
     * iku_id/tahun/bulan di query string harus langsung mengisi properti komponen di mount(),
     * sama seperti pemilihan manual lewat dropdown IKU (updatedIkuId()).
     */
    public function test_deep_link_iku_id_dari_dasbor_langsung_terisi_di_mount(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $this->actingAs(User::create([
            'nama' => 'Ketua Uji', 'username' => 'ketua-uji16@example.test', 'email' => 'ketua-uji16@example.test', 'password' => 'password',
            'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $iku = MasterIku::create([
            'kode' => 'UJI-016', 'indikator' => 'Indikator uji coba 16', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji',
        ]);

        Livewire::withQueryParams(['iku_id' => $iku->id, 'tahun' => 2026, 'bulan' => 7])
            ->test(PengisianKegiatan::class)
            ->assertSet('iku_id', $iku->id)
            ->assertSet('tahun', 2026)
            ->assertSet('bulan', 7);
    }

    public function test_deep_link_iku_id_tidak_valid_diabaikan(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $this->actingAs(User::create([
            'nama' => 'Ketua Uji', 'username' => 'ketua-uji17@example.test', 'email' => 'ketua-uji17@example.test', 'password' => 'password',
            'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        Livewire::withQueryParams(['iku_id' => 999999])
            ->test(PengisianKegiatan::class)
            ->assertSet('iku_id', null);
    }
}
