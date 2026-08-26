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

    public function test_isian_yang_sudah_disetujui_terkunci_total(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji Terkunci', 'username' => 'ketua-uji-terkunci@example.test', 'email' => 'ketua-uji-terkunci@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-TERKUNCI', 'indikator' => 'Indikator uji terkunci', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 9, 'triwulan' => 3, 'bulan_ke' => 3, 'flag_bulan_terlewat' => false]);

        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DISETUJUI]);

        Kegiatan::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan yang sudah disetujui', 'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DISETUJUI,
        ]);

        $this->actingAs($ketua);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id);

        $this->assertTrue($component->instance()->formTerkunciDisetujui());

        // addBlock() tidak boleh menambah blok baru sama sekali selagi terkunci.
        $jumlahBlokSebelum = count($component->get('blocks'));
        $component->call('addBlock');
        $this->assertCount($jumlahBlokSebelum, $component->get('blocks'));

        // Pertahanan berlapis: simpanDraft()/ajukanIsian() juga menolak, bukan cuma
        // disembunyikan di UI, kalau-kalau dipanggil langsung lewat request lain.
        $component->call('simpanDraft');
        $this->assertDatabaseCount('kegiatan', 1);
    }

    /**
     * Siapkan satu kegiatan "dikembalikan" dengan satu berkas "ditolak" (+ catatan)
     * — dipakai ketiga test hapusBuktiLama() di bawah.
     *
     * @return array{ketua: User, iku: MasterIku, kegiatan: Kegiatan, berkas: Berkas}
     */
    protected function siapkanKegiatanDikembalikanDenganBerkasDitolak(): array
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji Hapus Bukti', 'username' => 'ketua-uji-hapus@example.test', 'email' => 'ketua-uji-hapus@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-HAPUS', 'indikator' => 'Indikator uji hapus bukti', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIKEMBALIKAN]);

        $kegiatan = Kegiatan::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan dikembalikan', 'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIKEMBALIKAN,
        ]);

        $berkas = Berkas::create([
            'ref_id' => $kegiatan->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian',
            'nama_file' => 'bukti-ditolak.pdf', 'path' => 'bukti-capaian/bukti-ditolak.pdf',
            'status_verifikasi' => 'ditolak', 'catatan' => 'Tanggal tidak jelas',
        ]);

        return compact('ketua', 'iku', 'kegiatan', 'berkas');
    }

    public function test_hapus_bukti_lama_menghapus_berkas_yang_ditolak(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $data = $this->siapkanKegiatanDikembalikanDenganBerkasDitolak();
        \Illuminate\Support\Facades\Storage::disk('local')->put($data['berkas']->path, 'isi pdf palsu');

        $this->actingAs($data['ketua']);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id);

        $blockIndex = collect($component->get('blocks'))->search(fn ($b) => $b['id'] === $data['kegiatan']->id);
        $this->assertNotFalse($blockIndex);

        $component->call('hapusBuktiLama', $blockIndex, $data['berkas']->id);

        $this->assertDatabaseMissing('berkas', ['id' => $data['berkas']->id]);
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing($data['berkas']->path);
        $this->assertEmpty($component->get("blocks.{$blockIndex}.existing_bukti"));
    }

    public function test_hapus_bukti_lama_menolak_berkas_yang_belum_ditolak(): void
    {
        $data = $this->siapkanKegiatanDikembalikanDenganBerkasDitolak();
        $data['berkas']->update(['status_verifikasi' => 'terverifikasi']);

        $this->actingAs($data['ketua']);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id);

        $blockIndex = collect($component->get('blocks'))->search(fn ($b) => $b['id'] === $data['kegiatan']->id);

        $component->call('hapusBuktiLama', $blockIndex, $data['berkas']->id);

        $this->assertDatabaseHas('berkas', ['id' => $data['berkas']->id]);
    }

    public function test_hapus_bukti_lama_menolak_berkas_milik_kegiatan_lain(): void
    {
        $data = $this->siapkanKegiatanDikembalikanDenganBerkasDitolak();

        $kegiatanLain = Kegiatan::create([
            'iku_id' => $data['iku']->id, 'periode_id' => $data['kegiatan']->periode_id,
            'uraian_kegiatan' => 'Kegiatan lain', 'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DRAFT,
        ]);

        $this->actingAs($data['ketua']);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id);

        // blockIndex milik kegiatanLain (BUKAN pemilik $data['berkas']) — payload
        // dimanipulasi mencoba hapus berkas kegiatan lain lewat block yang salah.
        $blockIndexLain = collect($component->get('blocks'))->search(fn ($b) => $b['id'] === $kegiatanLain->id);
        $this->assertNotFalse($blockIndexLain);

        $component->call('hapusBuktiLama', $blockIndexLain, $data['berkas']->id);

        $this->assertDatabaseHas('berkas', ['id' => $data['berkas']->id]);
    }

    public function test_ketua_tim_bisa_membuka_pratinjau_berkas_miliknya(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $data = $this->siapkanKegiatanDikembalikanDenganBerkasDitolak();
        \Illuminate\Support\Facades\Storage::disk('local')->put($data['berkas']->path, 'isi pdf palsu');

        $this->actingAs($data['ketua']);

        $this->get(route('berkas.show', $data['berkas']))->assertOk();
    }

    public function test_kendala_ditolak_dimuat_ulang_ke_form_dan_diperbaiki_di_baris_yang_sama(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji Kendala', 'username' => 'ketua-uji-kendala@example.test', 'email' => 'ketua-uji-kendala@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create(['kode' => 'UJI-KENDALA', 'indikator' => 'Indikator uji kendala', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        // Capaian dibuat sejajar berstatus "dikembalikan" — di alur nyata, kendala
        // baru bisa berstatus "ditolak" setelah Tim SAKIP menyelesaikan pemeriksaan
        // lewat kembalikanKeKetuaTim() (lihat App\Livewire\VerifikasiCapaian), yang
        // SELALU menarik status Capaian ini bersamaan. Tanpa baris ini,
        // PengisianKegiatan::verifikasiTerlihat() menganggap pemeriksaan belum final
        // (Capaian::status null) dan menyamarkan catatan penolakannya.
        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIKEMBALIKAN]);

        $ks = KendalaSolusi::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'kendala' => 'Kendala lama', 'solusi' => 'Solusi lama',
            'status_verifikasi' => 'ditolak', 'catatan' => 'Solusi belum konkret',
        ]);

        $this->actingAs($ketua);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id);

        // Pasangan yang ditolak dimuat ke kendalaBlocks (bukan blok kosong), lengkap
        // dengan catatan penolakannya, siap diperbaiki.
        $component->assertSet('kendalaBlocks.0.id', $ks->id)
            ->assertSet('kendalaBlocks.0.kendala', 'Kendala lama')
            ->assertSet('kendalaBlocks.0.catatan', 'Solusi belum konkret');

        $component->set('blocks.0.uraian_kegiatan', 'Kegiatan uji kendala')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [\Illuminate\Http\UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('kendalaBlocks.0.kendala', 'Kendala sudah diperbaiki')
            ->set('rtlBaru.0.rtl_teks', 'RTL uji')
            ->set('rtlBaruPic', 'PIC Uji')
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        // Baris LAMA diperbarui di tempat (bukan duplikat) — tetap satu baris saja
        // untuk pasangan ini, statusnya kembali "menunggu" karena diajukan ulang.
        $this->assertDatabaseCount('kendala_solusi', 1);
        $ks->refresh();
        $this->assertSame('Kendala sudah diperbaiki', $ks->kendala);
        $this->assertSame('menunggu', $ks->status_verifikasi);
        $this->assertNull($ks->catatan);
    }

    public function test_kendala_diterima_terkunci_dari_form_edit_tapi_tampil_di_riwayat(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji Kendala Diterima', 'username' => 'ketua-uji-kendala-terima@example.test', 'email' => 'ketua-uji-kendala-terima@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create(['kode' => 'UJI-KENDALA-2', 'indikator' => 'Indikator uji kendala 2', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        // Sejajar dengan catatan di test sebelumnya — kendala baru berstatus
        // "terverifikasi" setelah Tim SAKIP menyelesaikan verifikasiSelesai(), yang
        // menarik Capaian ini ke "diverifikasi" bersamaan.
        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIVERIFIKASI]);

        KendalaSolusi::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'kendala' => 'Kendala sudah diterima', 'solusi' => 'Solusi sudah diterima',
            'status_verifikasi' => 'terverifikasi',
        ]);

        $this->actingAs($ketua);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id);

        // Pasangan yang sudah diterima TIDAK dimuat sebagai blok editable — form tetap
        // menampilkan satu blok kosong untuk pasangan BARU.
        $component->assertSet('kendalaBlocks.0.id', null)
            ->assertSet('kendalaBlocks.0.kendala', '');

        // ...tapi tetap terlihat (hanya-baca) di riwayat kumulatif triwulan berjalan.
        $riwayat = $component->viewData('riwayatKendala');
        $ditemukan = collect($riwayat->get(3))->contains(fn ($item) => $item->kendala === 'Kendala sudah diterima');
        $this->assertTrue($ditemukan);
    }

    /**
     * Siapkan satu poin bagian kustom milik periode BERJALAN (belum terkunci —
     * Capaian-nya "dikembalikan") — dipakai test edit-ulang & hapus-bukti-lama bagian
     * kustom di bawah. Pola sama seperti siapkanKegiatanDikembalikanDenganBerkasDitolak().
     *
     * @return array{ketua: User, iku: MasterIku, periode: Periode, bagian: \App\Models\BagianKustom, poin: \App\Models\BagianKustomPoin}
     */
    protected function siapkanBagianKustomDikembalikan(): array
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji Bagian Kustom', 'username' => 'ketua-uji-bagian@example.test', 'email' => 'ketua-uji-bagian@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create(['kode' => 'UJI-BAGIAN', 'indikator' => 'Indikator uji bagian kustom', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        $bagian = \App\Models\BagianKustom::create([
            'nama' => 'Manajemen Risiko', 'frekuensi_wajib' => \App\Models\BagianKustom::FREKUENSI_OPSIONAL,
            'bukti_wajib' => false, 'aktif' => true, 'urutan' => 1,
        ]);

        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIKEMBALIKAN]);

        $poin = \App\Models\BagianKustomPoin::create([
            'bagian_kustom_id' => $bagian->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'teks' => 'Risiko lama',
        ]);

        return compact('ketua', 'iku', 'periode', 'bagian', 'poin');
    }

    public function test_bagian_kustom_ditolak_dimuat_ulang_ke_form_dan_diperbaiki_di_baris_yang_sama(): void
    {
        $data = $this->siapkanBagianKustomDikembalikan();

        $this->actingAs($data['ketua']);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id);

        // Poin lama dimuat ke bagianKustomBlocks (bukan blok kosong), siap diperbaiki.
        $component->assertSet("bagianKustomBlocks.{$data['bagian']->id}.0.id", $data['poin']->id)
            ->assertSet("bagianKustomBlocks.{$data['bagian']->id}.0.teks", 'Risiko lama');

        $component->set('blocks.0.uraian_kegiatan', 'Kegiatan uji bagian kustom')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set("bagianKustomBlocks.{$data['bagian']->id}.0.teks", 'Risiko sudah diperbaiki')
            ->set('rtlBaru.0.rtl_teks', 'RTL uji')
            ->set('rtlBaruPic', 'PIC Uji')
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        // Baris LAMA diperbarui di tempat (bukan duplikat) — tetap satu baris saja.
        $this->assertDatabaseCount('bagian_kustom_poin', 1);
        $data['poin']->refresh();
        $this->assertSame('Risiko sudah diperbaiki', $data['poin']->teks);
    }

    public function test_bagian_kustom_disetujui_terkunci_dari_form_edit_tapi_tampil_di_riwayat(): void
    {
        $data = $this->siapkanBagianKustomDikembalikan();
        Capaian::where('iku_id', $data['iku']->id)->update(['status' => Capaian::STATUS_DISETUJUI]);

        $this->actingAs($data['ketua']);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id);

        // Periode sudah disetujui (terkunci) — poin lama TIDAK dimuat sebagai blok
        // editable, form tetap menampilkan satu blok kosong untuk poin BARU.
        $component->assertSet("bagianKustomBlocks.{$data['bagian']->id}.0.id", null)
            ->assertSet("bagianKustomBlocks.{$data['bagian']->id}.0.teks", '');

        // ...tapi tetap terlihat (hanya-baca) di riwayat kumulatif triwulan berjalan.
        $riwayat = $component->viewData('riwayatBagianKustom')[$data['bagian']->id];
        $ditemukan = collect($riwayat->get(3))->contains(fn ($item) => $item->teks === 'Risiko lama');
        $this->assertTrue($ditemukan);
    }

    public function test_hapus_bukti_lama_bagian_kustom_menghapus_berkas_yang_ditolak(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $data = $this->siapkanBagianKustomDikembalikan();

        $berkas = Berkas::create([
            'ref_id' => $data['poin']->id, 'ref_type' => \App\Models\BagianKustomPoin::class, 'kategori' => 'bagian_kustom',
            'nama_file' => 'bukti-ditolak.pdf', 'path' => 'bukti-bagian-kustom/bukti-ditolak.pdf',
            'status_verifikasi' => 'ditolak', 'catatan' => 'Belum relevan',
        ]);
        \Illuminate\Support\Facades\Storage::disk('local')->put($berkas->path, 'isi pdf palsu');

        $this->actingAs($data['ketua']);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id)
            ->call('hapusBuktiLamaBagianKustom', $data['poin']->id, $berkas->id);

        $this->assertDatabaseMissing('berkas', ['id' => $berkas->id]);
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing($berkas->path);
        $this->assertEmpty($component->get("bagianKustomBlocks.{$data['bagian']->id}.0.existing_bukti"));
    }

    public function test_hapus_bukti_lama_bagian_kustom_menolak_berkas_yang_belum_ditolak(): void
    {
        $data = $this->siapkanBagianKustomDikembalikan();

        $berkas = Berkas::create([
            'ref_id' => $data['poin']->id, 'ref_type' => \App\Models\BagianKustomPoin::class, 'kategori' => 'bagian_kustom',
            'nama_file' => 'bukti.pdf', 'path' => 'bukti-bagian-kustom/bukti.pdf', 'status_verifikasi' => 'menunggu',
        ]);

        $this->actingAs($data['ketua']);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id)
            ->call('hapusBuktiLamaBagianKustom', $data['poin']->id, $berkas->id);

        $this->assertDatabaseHas('berkas', ['id' => $berkas->id]);
    }

    public function test_hapus_bukti_lama_evaluasi_menghapus_berkas_yang_ditolak(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');

        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji Evaluasi', 'username' => 'ketua-uji-evaluasi@example.test', 'email' => 'ketua-uji-evaluasi@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create(['kode' => 'UJI-EVALUASI', 'indikator' => 'Indikator uji evaluasi', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);

        // RTL disimpan dengan periode TARGET-nya (triwulan III, ditetapkan saat mengisi
        // triwulan II) — sama seperti siapkanIkuDanRtlSebelumnya() di atas. Dievaluasi
        // saat mengisi Triwulan III (bulan 8 = Agustus, bulan berjalan form ini).
        $periodeRtl = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        $poin = RtlEvaluasi::create([
            'iku_id' => $iku->id, 'periode_id' => $periodeRtl->id,
            'rtl_teks' => 'Rencana uji evaluasi', 'berlaku_bulan' => 'RTL untuk Juli, Agustus, dan September',
            'pic' => 'PIC Uji', 'batas_waktu' => '2026-09-30',
        ]);

        $berkas = Berkas::create([
            'ref_id' => $poin->id, 'ref_type' => RtlEvaluasi::class, 'kategori' => 'evaluasi_rtl',
            'nama_file' => 'realisasi-ditolak.pdf', 'path' => 'bukti-evaluasi-rtl/realisasi-ditolak.pdf',
            'status_verifikasi' => 'ditolak', 'catatan' => 'Belum sesuai rencana',
        ]);
        \Illuminate\Support\Facades\Storage::disk('local')->put($berkas->path, 'isi pdf palsu');

        $this->actingAs($ketua);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id)
            ->call('hapusBuktiLamaEvaluasi', $poin->id, $berkas->id);

        $this->assertDatabaseMissing('berkas', ['id' => $berkas->id]);
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing($berkas->path);
    }
}
