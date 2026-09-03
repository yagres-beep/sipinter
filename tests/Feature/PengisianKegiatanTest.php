<?php

namespace Tests\Feature;

use App\Livewire\PengisianKegiatan;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\RiwayatStatusCapaian;
use App\Models\RtlEvaluasi;
use App\Models\User;
use App\Models\UserTim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        // PIC SELALU nama tim IKU ini ('Uji'), bukan nilai bebas yang diketik —
        // lihat "PIC nya harus nama tim bukan nama orang" di ajukanIsian().
        $this->assertSame('Uji', $rtlBaru->pic);
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
            ->set('kendalaBlocks.0.kendala', 'Kendala uji riwayat')
            ->set('kendalaBlocks.0.solusi', '')
            ->set('rtlBaru.0.rtl_teks', 'RTL uji riwayat')
            ->set('rtlBaruPicTerpilih', ['PIC Uji Riwayat'])
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('riwayat_status_capaian', 1);

        $capaian = Capaian::first();
        $riwayat = $capaian->riwayatStatus->first();
        $this->assertSame('diajukan', $riwayat->status);
        $this->assertSame($ketua->id, $riwayat->user_id);
    }

    /**
     * Pesan sukses menyebutkan IKU + triwulan/tahun secara eksplisit — Ketua Tim
     * biasanya mengisi beberapa IKU berturut-turut, jadi "berhasil diajukan" saja
     * tidak cukup jelas isian yang mana yang baru saja terkirim.
     */
    public function test_pesan_sukses_ajukan_menyebutkan_iku_triwulan_dan_tahun(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji Pesan', 'username' => 'ketua-uji-pesan@example.test', 'email' => 'ketua-uji-pesan@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-PESAN', 'indikator' => 'Indikator uji pesan sukses', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji',
        ]);

        $this->actingAs($ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji pesan')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('kendalaBlocks.0.kendala', 'Kendala uji pesan')
            ->set('rtlBaru.0.rtl_teks', 'RTL uji pesan')
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $pesan = session('status');
        $this->assertStringContainsString('UJI-PESAN', $pesan);
        $this->assertStringContainsString('Triwulan III 2026', $pesan);
        $this->assertStringContainsString('September', $pesan);
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
            ->set('rtlBaruPicTerpilih', ['PIC Uji 2'])
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('kendala_solusi', 0);
    }

    /**
     * Kendala & Solusi boleh dikosongkan bulan demi bulan (lihat
     * test_kendala_solusi_kosong_tidak_disimpan() di atas, bulan 8/bukan bulan
     * terakhir) — TAPI begitu sampai bulan TERAKHIR triwulan, minimal satu pasangan
     * wajib sudah tercatat untuk triwulan itu sebelum bisa diajukan ke Tim SAKIP.
     */
    public function test_kendala_solusi_wajib_minimal_satu_di_bulan_terakhir_triwulan(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);

        $ketua = User::create([
            'nama' => 'Ketua Uji', 'username' => 'ketua-uji-kendala-wajib@example.test', 'email' => 'ketua-uji-kendala-wajib@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create(['kode' => 'UJI-KENDALA-WAJIB', 'indikator' => 'Indikator uji kendala wajib', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);

        $this->actingAs($ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9) // bulan terakhir Triwulan III.
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan tanpa kendala')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('rtlBaru.0.rtl_teks', 'RTL tanpa kendala')
            ->set('rtlBaruPicTerpilih', ['PIC Uji'])
            ->call('ajukanIsian')
            ->assertHasErrors('kendalaBlocks');

        $this->assertDatabaseCount('kegiatan', 0);
        $this->assertDatabaseCount('kendala_solusi', 0);
    }

    /**
     * Syarat minimal satu pasangan di bulan terakhir triwulan boleh dipenuhi dari
     * bulan-bulan SEBELUMNYA di triwulan yang sama (tidak wajib diisi ulang di bulan
     * terakhir itu sendiri) — lihat riwayatKendalaSolusi() yang dipakai di
     * buatValidator().
     */
    public function test_kendala_solusi_dari_bulan_sebelumnya_triwulan_yang_sama_memenuhi_syarat_bulan_terakhir(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);

        $ketua = User::create([
            'nama' => 'Ketua Uji', 'username' => 'ketua-uji-kendala-riwayat@example.test', 'email' => 'ketua-uji-kendala-riwayat@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create(['kode' => 'UJI-KENDALA-RIWAYAT', 'indikator' => 'Indikator uji kendala riwayat', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);

        $periodeBulanPertama = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
        KendalaSolusi::create(['iku_id' => $iku->id, 'periode_id' => $periodeBulanPertama->id, 'kendala' => 'Kendala bulan pertama triwulan']);

        $this->actingAs($ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9) // bulan terakhir triwulan yang sama dengan periode di atas.
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan bulan terakhir')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('rtlBaru.0.rtl_teks', 'RTL bulan terakhir')
            ->set('rtlBaruPicTerpilih', ['PIC Uji'])
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('kegiatan', 1);
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
            ->set('rtlBaruPicTerpilih', ['PIC Uji 3'])
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

    /**
     * Regresi produksi: "Simpan Draft" hanya menyimpan kolom Kegiatan (uraian, jenis,
     * tahapan) — kendala & solusi, RTL berikutnya, dan bukti capaian yang sudah
     * diisi/diunggah TIDAK ikut tersimpan sama sekali (baru tersimpan saat "Ajukan ke
     * Tim SAKIP"), jadi menutup lalu membuka lagi form terasa seperti isian itu hilang.
     */
    public function test_simpan_draft_menyimpan_bukti_kendala_solusi_dan_rtl_juga(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji Draf Lengkap', 'username' => 'ketua-uji-draf-lengkap@example.test', 'email' => 'ketua-uji-draf-lengkap@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-DRAF', 'indikator' => 'Indikator uji draf lengkap', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji',
        ]);

        $this->actingAs($ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9) // bulan terakhir triwulan — RTL Baru boleh diisi.
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan draf lengkap')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti-draf.pdf', 100, 'application/pdf')])
            ->set('kendalaBlocks.0.kendala', 'Kendala draf')
            ->set('kendalaBlocks.0.solusi', 'Solusi draf')
            ->set('rtlBaru.0.rtl_teks', 'RTL draf berikutnya')
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('kegiatan', 1);
        $this->assertDatabaseCount('berkas', 1);
        $this->assertDatabaseCount('kendala_solusi', 1);
        $this->assertDatabaseCount('rtl_evaluasi', 1);

        $kegiatan = Kegiatan::first();
        $this->assertSame('draft', $kegiatan->status_dokumen);

        $capaian = Capaian::first();
        $this->assertNotSame('diajukan', $capaian->status);

        $kendalaSolusi = KendalaSolusi::first();
        $this->assertSame('Kendala draf', $kendalaSolusi->kendala);
        $this->assertSame('Solusi draf', $kendalaSolusi->solusi);

        $rtlBaru = RtlEvaluasi::first();
        $this->assertSame('RTL draf berikutnya', $rtlBaru->rtl_teks);
    }

    /**
     * Regresi produksi: begitu simpanDraft() mulai ikut menyimpan RTL Baru (supaya
     * tidak hilang saat form dibuka ulang, lihat
     * test_simpan_draft_menyimpan_bukti_kendala_solusi_dan_rtl_juga()), Bagian 5
     * langsung terkunci "Sudah ditetapkan" (hanya-baca) & tombol "Tambah Poin RTL"
     * hilang begitu draft pertama disimpan — padahal isian secara keseluruhan MASIH
     * berstatus draft (belum diajukan ke Tim SAKIP) dan Kegiatan/Kendala & Solusi
     * tetap bisa diedit bebas selama draft. RTL Baru draft harus tetap terbuka &
     * bisa dilanjutkan mengeditnya, persis seperti bagian lain, sampai benar-benar
     * diajukan.
     */
    public function test_rtl_baru_draft_tetap_bisa_diedit_dan_tombol_tambah_tetap_ada(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji RTL Draf', 'username' => 'ketua-uji-rtl-draf@example.test', 'email' => 'ketua-uji-rtl-draf@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-RTLDRAF', 'indikator' => 'Indikator uji RTL draf', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji',
        ]);

        $this->actingAs($ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan RTL draf')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('rtlBaru.0.rtl_teks', 'RTL draf belum diajukan')
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $rtl = RtlEvaluasi::first();
        $this->assertSame('draft', $rtl->status_dokumen);

        // Buka ulang komponen (mis. pengguna membuka lagi formnya) — RTL Baru draft
        // ini harus tetap tampil sebagai isian yang BISA DIEDIT (bukan "Sudah
        // ditetapkan" hanya-baca), lengkap dengan tombol "Tambah Poin RTL".
        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id);

        $component->assertDontSee('Sudah ditetapkan')
            ->assertSet('rtlBaru.0.id', $rtl->id)
            ->assertSet('rtlBaru.0.rtl_teks', 'RTL draf belum diajukan')
            ->assertSee('Tambah Poin RTL');

        // Melanjutkan & benar-benar mengajukan HARUS mengubah baris yang SAMA (bukan
        // duplikat) jadi "diajukan" — baru di titik ini terkunci "Sudah ditetapkan".
        $component->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti-rtl-draf.pdf', 100, 'application/pdf')])
            ->set('kendalaBlocks.0.kendala', 'Kendala RTL draf')
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('rtl_evaluasi', 1);
        $rtl->refresh();
        $this->assertSame('diajukan', $rtl->status_dokumen);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->assertSee('Sudah ditetapkan');
    }

    /**
     * Regresi: PIC Tindak Lanjut yang sudah dipilih & disimpan (draft/ditolak) hilang
     * tertimpa bawaan nama tim IKU begitu form dibuka ulang — pilihPicOtomatis()
     * dipanggil SETELAH muatRtlBaruBlocks() memuat draft, jadi PIC kustom yang baru
     * saja disimpan tidak pernah ikut termuat. Lihat muatPicTersimpan().
     */
    public function test_pic_tindak_lanjut_draf_tetap_tersimpan_saat_form_dibuka_ulang(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji PIC Draf', 'username' => 'ketua-uji-pic-draf@example.test', 'email' => 'ketua-uji-pic-draf@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-PICDRAF', 'indikator' => 'Indikator uji PIC draf', 'tim' => 'Tim Bawaan', 'penanggung_jawab' => 'Ketua Uji',
        ]);

        $this->actingAs($ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan PIC draf')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('rtlBaru.0.rtl_teks', 'RTL PIC draf')
            ->set('rtlBaruPicTerpilih', ['Tim Kustom'])
            ->call('simpanDraft')
            ->assertHasNoErrors();

        $rtl = RtlEvaluasi::first();
        $this->assertSame('Tim Kustom', $rtl->pic);

        // Buka ulang komponen — PIC kustom yang sudah disimpan harus tetap tampil,
        // BUKAN tertimpa balik ke bawaan nama tim IKU ("Tim Bawaan").
        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->assertSet('rtlBaruPicTerpilih', ['Tim Kustom']);
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
            ->set('kendalaBlocks.0.kendala', 'Kendala lengkap')
            ->set('rtlBaru.0.rtl_teks', 'RTL lengkap')
            ->set('rtlBaruPicTerpilih', ['PIC Lengkap']);

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

    /**
     * Regresi produksi: browser yang sudah membuka form SEBELUM key 'id' ditambahkan
     * ke emptyRtlBlock() (lihat riwayat) masih mengirim snapshot Livewire lama —
     * blok rtlBaru TANPA key 'id' sama sekali — ke kode server yang baru. Blade
     * SEMPAT mengakses $blok['id'] langsung tanpa `?? null`, menyebabkan "Undefined
     * array key" (ErrorException) begitu form Bagian 5 dirender dengan >1 poin.
     */
    public function test_form_tidak_error_bila_rtl_baru_kiriman_lama_tanpa_key_id(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji Snapshot Lama', 'username' => 'ketua-uji-snapshot@example.test', 'email' => 'ketua-uji-snapshot@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create(['kode' => 'UJI-SNAPSHOT', 'indikator' => 'Indikator uji snapshot lama', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);

        $this->actingAs($ketua);

        // set() sendiri sudah menjalankan satu siklus render penuh di server — kalau
        // blade masih mengakses $blok['id'] langsung tanpa `?? null`, baris di bawah
        // ini SENDIRI yang akan melempar ErrorException "Undefined array key" (persis
        // error produksinya), tanpa perlu assertSee apa pun sesudahnya.
        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            // Simulasikan snapshot lama: array RTL tanpa key 'id' sama sekali (lebih
            // dari satu blok, supaya tombol hapus yang dulu memicu bug ini dievaluasi).
            ->set('rtlBaru', [
                ['rtl_teks' => 'Poin lama tanpa id 1'],
                ['rtl_teks' => 'Poin lama tanpa id 2'],
            ])
            ->assertOk();
    }

    public function test_ajukan_diblokir_jika_jumlah_kegiatan_kurang_dari_jumlah_rtl_berjalan(): void
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

        // Dua poin RTL diajukan triwulan sebelumnya, tapi Ketua Tim cuma mengisi SATU
        // kegiatan triwulan ini — jumlahnya kurang dari jumlah RTL, jadi tetap diblokir
        // walau kegiatan yang diisi sudah punya bukti capaian.
        RtlEvaluasi::create([
            'iku_id' => $iku->id,
            'periode_id' => $periodeBerjalan->id,
            'rtl_teks' => 'Rencana yang belum dilaksanakan',
            'berlaku_bulan' => 'Juli, Agustus, dan September',
            'pic' => 'PIC Rencana',
            'batas_waktu' => '2026-09-30',
        ]);

        RtlEvaluasi::create([
            'iku_id' => $iku->id,
            'periode_id' => $periodeBerjalan->id,
            'rtl_teks' => 'Rencana kedua yang belum dilaksanakan',
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
            ->set('rtlBaruPicTerpilih', ['PIC Uji 9'])
            ->call('ajukanIsian')
            ->assertHasErrors(['blocks']);

        $this->assertDatabaseCount('kegiatan', 0);
    }

    public function test_ajukan_boleh_dengan_kegiatan_di_luar_rtl_asal_jumlah_cukup_dan_bukti_eval_lengkap(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'username' => 'ketua-uji9b@example.test', 'email' => 'ketua-uji9b@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-009B',
            'indikator' => 'Indikator uji coba 9b',
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

        // Uraian kegiatan SENGAJA tidak sama dengan teks RTL (tidak dipilih lewat
        // dropdown RTL) — tetap boleh diajukan karena jumlah kegiatan (1) sudah sama
        // banyak dengan jumlah RTL (1) dan bukti evaluasi RTL-nya sudah lengkap.
        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan lain yang tidak terkait RTL')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set("evaluasi.{$poinRtl->id}.bukti", [UploadedFile::fake()->create('realisasi.pdf', 100, 'application/pdf')])
            ->set('kendalaBlocks.0.kendala', 'Kendala uji 9b')
            ->set('rtlBaru.0.rtl_teks', 'RTL baru')
            ->set('rtlBaruPicTerpilih', ['PIC Uji 9b'])
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $kegiatan = Kegiatan::first();
        $this->assertNotNull($kegiatan);
        $this->assertNull($kegiatan->rtl_evaluasi_id);
    }

    public function test_pilih_rtl_untuk_block_menaut_dan_mengisi_uraian_lalu_tetap_tertaut_setelah_diedit(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'username' => 'ketua-uji9c@example.test', 'email' => 'ketua-uji9c@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-009C',
            'indikator' => 'Indikator uji coba 9c',
            'tim' => 'Uji',
            'penanggung_jawab' => 'Ketua Uji',
        ]);

        $periodeBerjalan = Periode::create([
            'tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false,
        ]);

        $poin1 = RtlEvaluasi::create([
            'iku_id' => $iku->id, 'periode_id' => $periodeBerjalan->id,
            'rtl_teks' => 'Poin pertama', 'berlaku_bulan' => 'Juli, Agustus, dan September', 'pic' => 'PIC', 'batas_waktu' => '2026-09-30',
        ]);

        $poin2 = RtlEvaluasi::create([
            'iku_id' => $iku->id, 'periode_id' => $periodeBerjalan->id,
            'rtl_teks' => 'Poin kedua', 'berlaku_bulan' => 'Juli, Agustus, dan September', 'pic' => 'PIC', 'batas_waktu' => '2026-09-30',
        ]);

        $this->actingAs($ketua);

        $test = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->call('pilihRtlUntukBlock', 0, $poin1->id);

        $test->assertSet('blocks.0.rtl_evaluasi_id', $poin1->id)
            ->assertSet('blocks.0.uraian_kegiatan', 'Poin pertama');

        // Blok kedua tidak boleh lagi menawarkan poin1 (sudah dipakai blok pertama)
        // sebagai opsi terpilihnya sendiri — hanya poin2 yang masih bebas dipilih.
        $test->call('addBlock')
            ->call('pilihRtlUntukBlock', 1, $poin2->id)
            ->assertSet('blocks.1.rtl_evaluasi_id', $poin2->id)
            ->assertSet('blocks.1.uraian_kegiatan', 'Poin kedua');

        // Menyunting teks setelah dipilih TIDAK melepas tautannya.
        $test->set('blocks.0.uraian_kegiatan', 'Poin pertama (disunting Ketua Tim)')
            ->assertSet('blocks.0.rtl_evaluasi_id', $poin1->id);

        // Mengembalikan dropdown ke "Ketik bebas" melepas tautannya.
        $test->call('pilihRtlUntukBlock', 0, '')
            ->assertSet('blocks.0.rtl_evaluasi_id', null);
    }

    protected function siapkanRtlBerikutnyaDitolak(): array
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji RTL Berikutnya', 'username' => 'ketua-uji-rtl-berikutnya@example.test', 'email' => 'ketua-uji-rtl-berikutnya@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create(['kode' => 'UJI-RTLB', 'indikator' => 'Indikator uji RTL berikutnya', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);

        $periodeIni = Periode::create(['tahun' => 2026, 'bulan' => 9, 'triwulan' => 3, 'bulan_ke' => 3, 'flag_bulan_terlewat' => false]);
        $periodeBerikutnya = Periode::create(['tahun' => 2026, 'bulan' => 10, 'triwulan' => 4, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        // Capaian triwulan INI harus "dikembalikan" (salah satu status verifikasiTerlihat())
        // supaya penolakan RTL berikutnya di bawah dianggap FINAL — lihat
        // PengisianKegiatan::rtlBerikutnyaDitolak().
        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periodeIni->id, 'status' => Capaian::STATUS_DIKEMBALIKAN]);

        $rtl = RtlEvaluasi::create([
            'iku_id' => $iku->id, 'periode_id' => $periodeBerikutnya->id,
            'rtl_teks' => 'Rencana lama yang ditolak', 'berlaku_bulan' => 'RTL untuk Oktober, November, dan Desember',
            'pic' => 'Uji', 'batas_waktu' => '2026-12-31',
            'status_verifikasi' => 'ditolak', 'catatan' => 'Rencana belum jelas PIC-nya',
        ]);

        return compact('ketua', 'iku', 'periodeIni', 'periodeBerikutnya', 'rtl');
    }

    public function test_rtl_berikutnya_ditolak_dimuat_ulang_ke_form_dan_diperbaiki_di_baris_yang_sama(): void
    {
        $data = $this->siapkanRtlBerikutnyaDitolak();

        $this->actingAs($data['ketua']);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $data['iku']->id);

        // Bagian 5 terbuka lagi (BUKAN "Sudah ditetapkan" hanya-baca) — poin lama
        // dimuat lengkap dengan id-nya, siap diperbaiki, beserta banner alasannya.
        $component->assertSet('rtlBaru.0.id', $data['rtl']->id)
            ->assertSet('rtlBaru.0.rtl_teks', 'Rencana lama yang ditolak')
            ->assertDontSee('Sudah ditetapkan')
            ->assertSee('Tim SAKIP mengembalikan rencana')
            ->assertSee('Rencana belum jelas PIC-nya');

        $component->set('blocks.0.uraian_kegiatan', 'Kegiatan uji RTL berikutnya')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('kendalaBlocks.0.kendala', 'Kendala uji RTL berikutnya')
            ->set('rtlBaru.0.rtl_teks', 'Rencana sudah diperbaiki')
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        // Baris LAMA diperbarui di tempat (bukan duplikat) & status balik "menunggu".
        $this->assertDatabaseCount('rtl_evaluasi', 1);
        $data['rtl']->refresh();
        $this->assertSame('Rencana sudah diperbaiki', $data['rtl']->rtl_teks);
        $this->assertSame('menunggu', $data['rtl']->status_verifikasi);
        $this->assertNull($data['rtl']->catatan);
    }

    public function test_halaman_pengisian_penuh_bisa_dibuka_lewat_deep_link_bulan_terakhir_triwulan(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji Deep Link', 'username' => 'ketua-uji-deeplink@example.test', 'email' => 'ketua-uji-deeplink@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create(['kode' => 'UJI-DEEPLINK', 'indikator' => 'Indikator uji deep link', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);

        $this->actingAs($ketua);

        $this->get("/pengisian?iku_id={$iku->id}&tahun=2026&bulan=9")->assertOk();
    }

    public function test_halaman_pengisian_penuh_bisa_dibuka_dengan_rtl_berikutnya_sudah_ditetapkan(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji Deep Link 2', 'username' => 'ketua-uji-deeplink2@example.test', 'email' => 'ketua-uji-deeplink2@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create(['kode' => 'UJI-DEEPLINK2', 'indikator' => 'Indikator uji deep link 2', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $periodeBerikutnya = Periode::create(['tahun' => 2026, 'bulan' => 10, 'triwulan' => 4, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        RtlEvaluasi::create([
            'iku_id' => $iku->id, 'periode_id' => $periodeBerikutnya->id,
            'rtl_teks' => 'Rencana sudah ada', 'berlaku_bulan' => 'RTL untuk Oktober, November, dan Desember',
            'pic' => 'Uji', 'batas_waktu' => '2026-12-31', 'status_verifikasi' => 'menunggu',
        ]);

        $this->actingAs($ketua);

        $this->get("/pengisian?iku_id={$iku->id}&tahun=2026&bulan=9")->assertOk();
    }

    public function test_rtl_berikutnya_sudah_ditetapkan_menampilkan_isi_yang_pernah_diisi(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji RTL Aktif', 'username' => 'ketua-uji-rtl-aktif@example.test', 'email' => 'ketua-uji-rtl-aktif@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create(['kode' => 'UJI-RTLA', 'indikator' => 'Indikator uji RTL aktif', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $periodeBerikutnya = Periode::create(['tahun' => 2026, 'bulan' => 10, 'triwulan' => 4, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        RtlEvaluasi::create([
            'iku_id' => $iku->id, 'periode_id' => $periodeBerikutnya->id,
            'rtl_teks' => 'Rencana yang sudah ditetapkan sebelumnya', 'berlaku_bulan' => 'RTL untuk Oktober, November, dan Desember',
            'pic' => 'Uji Aktif', 'batas_waktu' => '2026-12-31', 'status_verifikasi' => 'menunggu',
        ]);

        $this->actingAs($ketua);

        // Beda dari sebelumnya yang cuma menampilkan badge "Sudah ditetapkan" tanpa
        // detail — sekarang isian yang sudah diisi Ketua Tim (rencana, PIC, batas
        // waktu) tetap terlihat, konsisten dengan bagian-bagian lain di form ini.
        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id)
            ->assertSee('Sudah ditetapkan')
            ->assertSee('Rencana yang sudah ditetapkan sebelumnya')
            ->assertSee('Uji Aktif')
            ->assertSee('Menunggu Verifikasi Tim SAKIP');
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
            ->set('kendalaBlocks.0.kendala', 'Kendala uji 10')
            ->set('rtlBaru.0.rtl_teks', 'RTL baru')
            ->set('rtlBaruPicTerpilih', ['PIC Uji 10'])
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
            ->set('rtlBaruPicTerpilih', ['PIC Uji'])
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
            ->set('kendalaBlocks.0.kendala', 'Kendala uji evaluasi')
            ->set('rtlBaru.0.rtl_teks', 'RTL uji coba triwulan berikutnya')
            ->set('rtlBaruPicTerpilih', ['PIC Uji'])
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('kegiatan', 3);
        $this->assertTrue($poin1->refresh()->sudahDievaluasi());
        $this->assertTrue($poin2->refresh()->sudahDievaluasi());
    }

    public function test_pic_tindak_lanjut_terisi_otomatis_dari_nama_tim_iku(): void
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
        ]);

        UserTim::create(['user_id' => $ketua->id, 'tim' => 'Uji Otomatis']);

        $this->actingAs($ketua);

        $component = Livewire::test(PengisianKegiatan::class);
        $component->set('iku_id', $iku->id);

        // PIC Tindak Lanjut bawaan mengikuti tim penanggung jawab IKU
        // (App\Models\MasterIku::namaTimList()), BUKAN nama orang — konsisten dengan
        // yang dipakai di notula (NotulaBagian1DocxService::isiSatuIku()). Boleh
        // ditambah/dihapus bebas Ketua Tim lewat chip, lihat
        // test_ajukan_isian_berhasil_meski_pic_tindak_lanjut_kosong().
        $this->assertSame(['Uji Otomatis'], $component->get('rtlBaruPicTerpilih'));
    }

    /**
     * RF baru: PIC Tindak Lanjut boleh diisi lebih dari satu tim — ditambah/dihapus
     * satu per satu lewat chip (tambahRtlBaruPic()/hapusRtlBaruPic()), boleh dari
     * saran daftarTimPic() (database tim) atau nama tim baru yang diketik bebas.
     */
    public function test_pic_tindak_lanjut_bisa_ditambah_lebih_dari_satu_tim_lewat_chip(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji',
            'username' => 'ketua-uji-multi-pic@example.test', 'email' => 'ketua-uji-multi-pic@example.test',
            'password' => 'password',
            'role_id' => $peranKetua->id,
            'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-MULTI-PIC',
            'indikator' => 'Indikator uji multi PIC',
            'tim' => 'Tim Bawaan',
        ]);

        $this->actingAs($ketua);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('iku_id', $iku->id)
            ->assertSet('rtlBaruPicTerpilih', ['Tim Bawaan']);

        // Tambah satu tim baru yang tidak ada di database sama sekali (diketik bebas).
        $component->set('rtlBaruPicBaru', 'Tim Tambahan')
            ->call('tambahRtlBaruPic')
            ->assertSet('rtlBaruPicTerpilih', ['Tim Bawaan', 'Tim Tambahan'])
            ->assertSet('rtlBaruPicBaru', '');

        // Hapus tim bawaan, sisakan tim tambahan saja.
        $component->call('hapusRtlBaruPic', 'Tim Bawaan')
            ->assertSet('rtlBaruPicTerpilih', ['Tim Tambahan']);
    }

    /**
     * Regresi: PIC Tindak Lanjut dulu WAJIB diisi tapi field-nya dikunci hanya-baca
     * & terisi otomatis dari MasterIku::tim — begitu IKU belum dikonfigurasi tim-nya
     * (tim kosong), rtlBaruPicTerpilih tidak akan pernah terisi dan tombol "Ajukan ke
     * Tim SAKIP" tidak akan pernah aktif sama sekali. PIC sekarang opsional bagi
     * Ketua Tim (wajib diisi Tim SAKIP saat verifikasi, lihat VerifikasiCapaianTest).
     */
    public function test_ajukan_isian_berhasil_meski_pic_tindak_lanjut_kosong(): void
    {
        $peranKetua = Role::firstOrCreate(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji', 'username' => 'ketua-uji-pic-kosong@example.test',
            'email' => 'ketua-uji-pic-kosong@example.test', 'password' => 'password',
            'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create(['kode' => 'UJI-PICKOSONG', 'indikator' => 'Indikator uji PIC kosong']);

        $this->actingAs($ketua);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9) // bulan terakhir triwulan — RTL Baru & PIC-nya digerbang aktif di sini.
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji PIC kosong')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('kendalaBlocks.0.kendala', 'Kendala uji PIC kosong')
            ->set('rtlBaru.0.rtl_teks', 'RTL uji coba tanpa PIC');

        $this->assertSame([], $component->get('rtlBaruPicTerpilih'));
        $this->assertTrue($component->instance()->formLengkap());

        $component->call('ajukanIsian')->assertHasNoErrors();

        $this->assertDatabaseCount('kegiatan', 1);
        $this->assertDatabaseHas('rtl_evaluasi', ['rtl_teks' => 'RTL uji coba tanpa PIC', 'pic' => null]);
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
            ->set('kendalaBlocks.0.kendala', 'Kendala cache uji')
            ->set('rtlBaru.0.rtl_teks', 'RTL cache uji')
            ->set('rtlBaruPicTerpilih', ['PIC Uji 13'])
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

        DB::flushQueryLog();
        DB::enableQueryLog();

        $component->call('addBlock');

        $this->assertCount(0, DB::getQueryLog());
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

    public function test_isian_yang_sedang_ditangani_tim_sakip_terkunci_hanya_baca_bagi_ketua_tim(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji Ditangani', 'username' => 'ketua-uji-ditangani@example.test', 'email' => 'ketua-uji-ditangani@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-DITANGANI', 'indikator' => 'Indikator uji ditangani', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 9, 'triwulan' => 3, 'bulan_ke' => 3, 'flag_bulan_terlewat' => false]);

        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_SEDANG_DITANGANI]);

        Kegiatan::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan yang sedang ditangani', 'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIAJUKAN,
        ]);

        $this->actingAs($ketua);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9)
            ->set('iku_id', $iku->id);

        $this->assertTrue($component->instance()->formTerkunciSedangDitangani());

        // addBlock()/addKendalaBlock()/addRtlBlock()/addBagianKustomBlock() tidak boleh
        // menambah apa pun selagi Tim SAKIP sedang menangani — supaya data yang sedang
        // dipegang Tim SAKIP (VerifikasiCapaian::kegiatanList() dkk., di-cache per
        // request) tidak berubah di bawahnya.
        $jumlahBlokSebelum = count($component->get('blocks'));
        $component->call('addBlock');
        $this->assertCount($jumlahBlokSebelum, $component->get('blocks'));

        $jumlahKendalaSebelum = count($component->get('kendalaBlocks'));
        $component->call('addKendalaBlock');
        $this->assertCount($jumlahKendalaSebelum, $component->get('kendalaBlocks'));

        $jumlahRtlSebelum = count($component->get('rtlBaru'));
        $component->call('addRtlBlock');
        $this->assertCount($jumlahRtlSebelum, $component->get('rtlBaru'));

        // Pertahanan berlapis: simpanDraft()/ajukanIsian() juga menolak, bukan cuma
        // disembunyikan di UI, kalau-kalau dipanggil langsung lewat request lain.
        $component->call('simpanDraft');
        $this->assertDatabaseCount('kegiatan', 1);

        $component->call('ajukanIsian');
        $this->assertSame('sedang_ditangani', Capaian::where('iku_id', $iku->id)->value('status'));
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
        Storage::fake('local');
        $data = $this->siapkanKegiatanDikembalikanDenganBerkasDitolak();
        Storage::disk('local')->put($data['berkas']->path, 'isi pdf palsu');

        $this->actingAs($data['ketua']);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id);

        $blockIndex = collect($component->get('blocks'))->search(fn ($b) => $b['id'] === $data['kegiatan']->id);
        $this->assertNotFalse($blockIndex);

        $component->call('hapusBuktiLama', $blockIndex, $data['berkas']->id);

        $this->assertDatabaseMissing('berkas', ['id' => $data['berkas']->id]);
        Storage::disk('local')->assertMissing($data['berkas']->path);
        $this->assertEmpty($component->get("blocks.{$blockIndex}.existing_bukti"));

        // Catatan penolakan ("Tanggal tidak jelas", lihat fixture) tersalin ke Kegiatan
        // itu sendiri sebelum berkasnya lenyap — supaya Tim SAKIP masih ingat apa yang
        // salah sebelumnya saat memeriksa bukti pengganti.
        $data['kegiatan']->refresh();
        $this->assertSame('Tanggal tidak jelas', $data['kegiatan']->catatan_bukti_dihapus);
        $this->assertSame('Tanggal tidak jelas', $component->get("blocks.{$blockIndex}.catatan_bukti_dihapus"));
    }

    /**
     * Pasangan dari test di atas — begitu Tim SAKIP menandai Kegiatan ini
     * "diverifikasi" (lewat Kegiatan::verifikasi()), pengingat catatan_bukti_dihapus
     * sudah tidak relevan lagi dan harus ikut kosong.
     */
    public function test_verifikasi_kegiatan_mengosongkan_catatan_bukti_dihapus(): void
    {
        $data = $this->siapkanKegiatanDikembalikanDenganBerkasDitolak();
        $data['kegiatan']->update(['status_dokumen' => Kegiatan::STATUS_DIAJUKAN, 'catatan_bukti_dihapus' => 'Tanggal tidak jelas']);

        $data['kegiatan']->verifikasi();

        $this->assertNull($data['kegiatan']->fresh()->catatan_bukti_dihapus);
    }

    /**
     * Selagi Kegiatan-nya masih editable (draft/dikembalikan), Ketua Tim boleh
     * membuang bukti yang belum "terverifikasi" (mis. masih "menunggu" — belum
     * sempat diperiksa Tim SAKIP) — supaya bukti yang salah unggah sendiri bisa
     * diperbaiki sebelum diajukan ulang.
     */
    public function test_hapus_bukti_lama_menghapus_berkas_yang_belum_ditolak_selagi_masih_bisa_diedit(): void
    {
        Storage::fake('local');
        $data = $this->siapkanKegiatanDikembalikanDenganBerkasDitolak();
        $data['berkas']->update(['status_verifikasi' => 'menunggu']);
        Storage::disk('local')->put($data['berkas']->path, 'isi pdf palsu');

        $this->actingAs($data['ketua']);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id);

        $blockIndex = collect($component->get('blocks'))->search(fn ($b) => $b['id'] === $data['kegiatan']->id);

        $component->call('hapusBuktiLama', $blockIndex, $data['berkas']->id);

        $this->assertDatabaseMissing('berkas', ['id' => $data['berkas']->id]);
    }

    /**
     * Begitu Tim SAKIP menandai satu berkas "Sesuai" (terverifikasi), berkas itu
     * terkunci permanen bagi Ketua Tim — TIDAK boleh dihapus/diganti lagi lewat
     * sini, walau Kegiatan induknya sendiri masih terbuka untuk berkas lain yang
     * ditolak (skenario "Rincian N": status per-berkas, bukan per-kegiatan).
     * Defense in depth — tombol hapusnya sendiri sudah disembunyikan di blade.
     */
    public function test_hapus_bukti_lama_menolak_berkas_yang_sudah_terverifikasi(): void
    {
        $data = $this->siapkanKegiatanDikembalikanDenganBerkasDitolak();
        $data['berkas']->update(['status_verifikasi' => 'terverifikasi', 'catatan' => null]);

        $this->actingAs($data['ketua']);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id);

        $blockIndex = collect($component->get('blocks'))->search(fn ($b) => $b['id'] === $data['kegiatan']->id);

        $component->call('hapusBuktiLama', $blockIndex, $data['berkas']->id);

        $this->assertDatabaseHas('berkas', ['id' => $data['berkas']->id]);
    }

    /**
     * Begitu Kegiatan-nya sudah terkunci (mis. sudah diajukan/diverifikasi/disetujui),
     * hapusBuktiLama() menolak permintaan sama sekali — defense in depth, bukan cuma
     * disembunyikan tombolnya di blade.
     */
    public function test_hapus_bukti_lama_menolak_saat_kegiatan_sudah_terkunci(): void
    {
        $data = $this->siapkanKegiatanDikembalikanDenganBerkasDitolak();
        $data['kegiatan']->update(['status_dokumen' => Kegiatan::STATUS_DIAJUKAN]);

        $this->actingAs($data['ketua']);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id);

        $blockIndex = collect($component->get('blocks'))->search(fn ($b) => $b['id'] === $data['kegiatan']->id);
        $this->assertNotFalse($blockIndex);

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
        Storage::fake('local');
        $data = $this->siapkanKegiatanDikembalikanDenganBerkasDitolak();
        Storage::disk('local')->put($data['berkas']->path, 'isi pdf palsu');

        $this->actingAs($data['ketua']);

        $this->get(route('berkas.show', $data['berkas']))->assertOk();
    }

    public function test_banner_dikembalikan_menyebut_kepala_dan_catatannya_saat_kepala_yang_mengembalikan(): void
    {
        $data = $this->siapkanKegiatanDikembalikanDenganBerkasDitolak();

        $kepala = User::create([
            'nama' => 'Kepala Uji Banner', 'username' => 'kepala-uji-banner@example.test', 'email' => 'kepala-uji-banner@example.test',
            'password' => 'password', 'role_id' => Role::create(['nama' => 'Kepala'])->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $capaian = Capaian::where('iku_id', $data['iku']->id)->firstOrFail();

        // Meniru NotulaService::kembalikanIsian() (Kepala mengembalikan LANGSUNG dari
        // halaman Persetujuan Notula, lihat App\Livewire\PersetujuanNotula) — satu baris
        // riwayat dengan user Kepala & catatan bebas ketik, TANPA menyentuh catatan
        // per-berkas/kendala sama sekali.
        RiwayatStatusCapaian::create([
            'capaian_id' => $capaian->id, 'status' => Capaian::STATUS_DIKEMBALIKAN,
            'user_id' => $kepala->id, 'catatan' => 'Realisasi belum sesuai bukti dukung',
        ]);

        $this->actingAs($data['ketua']);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id)
            ->assertSee('dikembalikan oleh Kepala')
            ->assertSee('Realisasi belum sesuai bukti dukung')
            ->assertDontSee('dikembalikan oleh Tim SAKIP');
    }

    public function test_banner_dikembalikan_tetap_menyebut_tim_sakip_saat_tim_sakip_yang_mengembalikan(): void
    {
        $data = $this->siapkanKegiatanDikembalikanDenganBerkasDitolak();

        $timSakip = User::create([
            'nama' => 'Tim SAKIP Uji Banner', 'username' => 'sakip-uji-banner@example.test', 'email' => 'sakip-uji-banner@example.test',
            'password' => 'password', 'role_id' => Role::create(['nama' => 'Tim SAKIP'])->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $capaian = Capaian::where('iku_id', $data['iku']->id)->firstOrFail();

        RiwayatStatusCapaian::create([
            'capaian_id' => $capaian->id, 'status' => Capaian::STATUS_DIKEMBALIKAN,
            'user_id' => $timSakip->id, 'catatan' => 'Tanggal tidak jelas',
        ]);

        $this->actingAs($data['ketua']);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id)
            ->assertSee('dikembalikan oleh Tim SAKIP')
            ->assertDontSee('dikembalikan oleh Kepala');
    }

    public function test_ketua_tim_bisa_menghapus_permanen_kegiatan_yang_dikembalikan(): void
    {
        Storage::fake('local');
        $data = $this->siapkanKegiatanDikembalikanDenganBerkasDitolak();
        Storage::disk('local')->put($data['berkas']->path, 'isi pdf palsu');

        $this->actingAs($data['ketua']);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id);

        $blockIndex = collect($component->get('blocks'))->search(fn ($b) => $b['id'] === $data['kegiatan']->id);
        $this->assertNotFalse($blockIndex);

        $component->call('removeBlock', $blockIndex);

        // Baris Kegiatan & berkas buktinya benar-benar hilang dari DB (bukan cuma
        // dibuang dari array in-memory) -- lihat catatan di removeBlock(): kalau
        // hanya dibuang dari array, muatBlocksKegiatan() akan memuatnya lagi begitu
        // form dibuka ulang karena baris Kegiatan masih ada di DB.
        $this->assertDatabaseMissing('kegiatan', ['id' => $data['kegiatan']->id]);
        $this->assertDatabaseMissing('berkas', ['id' => $data['berkas']->id]);
        Storage::disk('local')->assertMissing($data['berkas']->path);

        // Satu blok kosong bawaan menggantikan blok yang dihapus, form tidak "amblas".
        $component->assertSet('blocks', [[
            'id' => null,
            'status_dokumen' => null,
            'rtl_evaluasi_id' => null,
            'uraian_kegiatan' => '',
            'jenis' => '',
            'tahapan_survei' => '',
            'bukti' => [],
            'existing_bukti' => [],
            'catatan_bukti_dihapus' => null,
        ]]);
    }

    public function test_ketua_tim_tidak_bisa_menghapus_permanen_kegiatan_yang_sudah_diverifikasi(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji Hapus Terkunci', 'username' => 'ketua-uji-hapus-terkunci@example.test', 'email' => 'ketua-uji-hapus-terkunci@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create(['kode' => 'UJI-HAPUS-2', 'indikator' => 'Indikator uji hapus terkunci', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIVERIFIKASI]);

        $kegiatan = Kegiatan::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan sudah diverifikasi', 'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        $this->actingAs($ketua);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id);

        $blockIndex = collect($component->get('blocks'))->search(fn ($b) => $b['id'] === $kegiatan->id);
        $this->assertNotFalse($blockIndex);

        $component->call('removeBlock', $blockIndex);

        $this->assertDatabaseHas('kegiatan', ['id' => $kegiatan->id]);
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
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('kendalaBlocks.0.kendala', 'Kendala sudah diperbaiki')
            ->set('rtlBaru.0.rtl_teks', 'RTL uji')
            ->set('rtlBaruPicTerpilih', ['PIC Uji'])
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        // Baris LAMA diperbarui di tempat (bukan duplikat) — tetap satu baris saja
        // untuk pasangan ini. status_verifikasi & catatan penolakan LAMA SENGAJA
        // tetap "ditolak" (bukan ditarik balik ke "menunggu") — supaya Tim SAKIP masih
        // ingat apa yang salah sebelumnya saat memeriksa perbaikan ini; baru kosong
        // begitu mereka sendiri menandai "Sesuai" (lihat catatan di ajukanIsian()).
        $this->assertDatabaseCount('kendala_solusi', 1);
        $ks->refresh();
        $this->assertSame('Kendala sudah diperbaiki', $ks->kendala);
        $this->assertSame('ditolak', $ks->status_verifikasi);
        $this->assertSame('Solusi belum konkret', $ks->catatan);
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

    public function test_kendala_sudah_diajukan_terkunci_dari_form_edit_tapi_boleh_tambah_pasangan_baru(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji Kendala Diajukan', 'username' => 'ketua-uji-kendala-diajukan@example.test', 'email' => 'ketua-uji-kendala-diajukan@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create(['kode' => 'UJI-KENDALA-3', 'indikator' => 'Indikator uji kendala 3', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        // Capaian masih "diajukan" — Tim SAKIP belum menyelesaikan satu siklus
        // pemeriksaan apa pun (beda dari dua test sebelumnya yang sudah "dikembalikan"/
        // "diverifikasi"), jadi verifikasiTerlihat() masih false di sini.
        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);

        $ks = KendalaSolusi::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'kendala' => 'Kendala sudah diajukan', 'solusi' => 'Solusi sudah diajukan',
            'status_dokumen' => KendalaSolusi::STATUS_DIAJUKAN, 'status_verifikasi' => 'menunggu',
        ]);

        $this->actingAs($ketua);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id);

        // Pasangan yang sudah diajukan TIDAK dimuat sebagai blok editable (tidak bisa
        // diam-diam disunting Ketua Tim selagi menunggu Tim SAKIP) — tampil di
        // $kendalaAktif (terkunci hanya-baca), dan $kendalaBlocks dibiarkan kosong
        // (bukan satu blok kosong bawaan) supaya blade cukup menampilkan tombol
        // "+ Tambah Pasangan" saja.
        $component->assertSet('kendalaBlocks', []);
        $this->assertSame([$ks->id], $component->viewData('kendalaAktif')->pluck('id')->all());

        // Ketua Tim tetap bisa menambah pasangan BARU tanpa menunggu Tim SAKIP
        // memutuskan pasangan lama — sama seperti Kegiatan/Bagian Kustom/RTL berikutnya.
        // blocks.0 diisi seadanya karena simpanDraft() tetap mewajibkan uraian_kegiatan
        // & jenis (lihat validate() di sana), walau tidak relevan dengan kendala.
        $component->set('blocks.0.uraian_kegiatan', 'Kegiatan uji kendala diajukan')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->call('addKendalaBlock')
            ->set('kendalaBlocks.0.kendala', 'Kendala baru')
            ->set('kendalaBlocks.0.solusi', 'Solusi baru')
            ->call('simpanDraft');

        $this->assertDatabaseCount('kendala_solusi', 2);
        $baru = KendalaSolusi::where('kendala', 'Kendala baru')->firstOrFail();
        $this->assertSame(KendalaSolusi::STATUS_DRAFT, $baru->status_dokumen);

        // Pasangan lama tidak ikut tersunting oleh penambahan pasangan baru ini.
        $ks->refresh();
        $this->assertSame('Kendala sudah diajukan', $ks->kendala);
        $this->assertSame(KendalaSolusi::STATUS_DIAJUKAN, $ks->status_dokumen);
    }

    /**
     * Siapkan satu poin bagian kustom milik periode BERJALAN (belum terkunci —
     * Capaian-nya "dikembalikan") — dipakai test edit-ulang & hapus-bukti-lama bagian
     * kustom di bawah. Pola sama seperti siapkanKegiatanDikembalikanDenganBerkasDitolak().
     *
     * @return array{ketua: User, iku: MasterIku, periode: Periode, bagian: BagianKustom, poin: BagianKustomPoin}
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

        $bagian = BagianKustom::create([
            'nama' => 'Manajemen Risiko', 'frekuensi_wajib' => BagianKustom::FREKUENSI_OPSIONAL,
            'bukti_wajib' => false, 'aktif' => true, 'urutan' => 1,
        ]);

        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIKEMBALIKAN]);

        $poin = BagianKustomPoin::create([
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
            ->set('rtlBaruPicTerpilih', ['PIC Uji'])
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

    public function test_bagian_kustom_terkunci_saat_capaian_sedang_ditangani(): void
    {
        $data = $this->siapkanBagianKustomDikembalikan();
        Capaian::where('iku_id', $data['iku']->id)->update(['status' => Capaian::STATUS_SEDANG_DITANGANI]);

        $this->actingAs($data['ketua']);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id);

        // Tim SAKIP sedang menangani (belum selesai) — poin lama TIDAK boleh
        // dimuat sebagai blok editable, supaya Ketua Tim tidak bisa menimpa
        // data yang sedang diproses.
        $component->assertSet("bagianKustomBlocks.{$data['bagian']->id}.0.id", null)
            ->assertSet("bagianKustomBlocks.{$data['bagian']->id}.0.teks", '');

        $riwayat = $component->viewData('riwayatBagianKustom')[$data['bagian']->id];
        $ditemukan = collect($riwayat->get(3))->contains(fn ($item) => $item->teks === 'Risiko lama');
        $this->assertTrue($ditemukan);
    }

    public function test_hapus_bukti_lama_bagian_kustom_menghapus_berkas_yang_ditolak(): void
    {
        Storage::fake('local');
        $data = $this->siapkanBagianKustomDikembalikan();

        $berkas = Berkas::create([
            'ref_id' => $data['poin']->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom',
            'nama_file' => 'bukti-ditolak.pdf', 'path' => 'bukti-bagian-kustom/bukti-ditolak.pdf',
            'status_verifikasi' => 'ditolak', 'catatan' => 'Belum relevan',
        ]);
        Storage::disk('local')->put($berkas->path, 'isi pdf palsu');

        $this->actingAs($data['ketua']);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id)
            ->call('hapusBuktiLamaBagianKustom', $data['poin']->id, $berkas->id);

        $this->assertDatabaseMissing('berkas', ['id' => $berkas->id]);
        Storage::disk('local')->assertMissing($berkas->path);
        $this->assertEmpty($component->get("bagianKustomBlocks.{$data['bagian']->id}.0.existing_bukti"));

        // Catatan penolakan tersalin ke poin itu sendiri sebelum berkasnya lenyap —
        // sama seperti hapusBuktiLama() untuk Kegiatan.
        $this->assertSame('Belum relevan', $data['poin']->fresh()->catatan_bukti_dihapus);
        $this->assertSame('Belum relevan', $component->get("bagianKustomBlocks.{$data['bagian']->id}.0.catatan_bukti_dihapus"));
    }

    /**
     * Selagi Capaian-nya masih editable (draft/dikembalikan), Ketua Tim boleh
     * membuang bukti bagian kustom APAPUN status_verifikasi-nya — sama seperti
     * hapusBuktiLama() untuk Kegiatan di atas.
     */
    public function test_hapus_bukti_lama_bagian_kustom_menghapus_berkas_yang_belum_ditolak_selagi_masih_bisa_diedit(): void
    {
        Storage::fake('local');
        $data = $this->siapkanBagianKustomDikembalikan();

        $berkas = Berkas::create([
            'ref_id' => $data['poin']->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom',
            'nama_file' => 'bukti.pdf', 'path' => 'bukti-bagian-kustom/bukti.pdf', 'status_verifikasi' => 'menunggu',
        ]);
        Storage::disk('local')->put($berkas->path, 'isi pdf palsu');

        $this->actingAs($data['ketua']);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id)
            ->call('hapusBuktiLamaBagianKustom', $data['poin']->id, $berkas->id);

        $this->assertDatabaseMissing('berkas', ['id' => $berkas->id]);
    }

    /**
     * Sama seperti hapusBuktiLama() untuk Kegiatan — begitu berkas bagian kustom
     * ditandai "Sesuai" (terverifikasi) oleh Tim SAKIP, hapusBuktiLamaBagianKustom()
     * menolak menghapusnya, walau poin/Capaian induknya sendiri masih editable.
     */
    public function test_hapus_bukti_lama_bagian_kustom_menolak_berkas_yang_sudah_terverifikasi(): void
    {
        $data = $this->siapkanBagianKustomDikembalikan();

        $berkas = Berkas::create([
            'ref_id' => $data['poin']->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom',
            'nama_file' => 'bukti-sesuai.pdf', 'path' => 'bukti-bagian-kustom/bukti-sesuai.pdf', 'status_verifikasi' => 'terverifikasi',
        ]);

        $this->actingAs($data['ketua']);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id)
            ->call('hapusBuktiLamaBagianKustom', $data['poin']->id, $berkas->id);

        $this->assertDatabaseHas('berkas', ['id' => $berkas->id]);
    }

    /**
     * Begitu Capaian-nya sedang ditangani Tim SAKIP, hapusBuktiLamaBagianKustom()
     * menolak permintaan — defense in depth, sama seperti hapusBuktiLama() untuk Kegiatan.
     */
    public function test_hapus_bukti_lama_bagian_kustom_menolak_saat_capaian_sedang_ditangani(): void
    {
        $data = $this->siapkanBagianKustomDikembalikan();
        Capaian::where('iku_id', $data['iku']->id)->update(['status' => Capaian::STATUS_SEDANG_DITANGANI]);

        $berkas = Berkas::create([
            'ref_id' => $data['poin']->id, 'ref_type' => BagianKustomPoin::class, 'kategori' => 'bagian_kustom',
            'nama_file' => 'bukti.pdf', 'path' => 'bukti-bagian-kustom/bukti.pdf', 'status_verifikasi' => 'ditolak', 'catatan' => 'Belum relevan',
        ]);

        $this->actingAs($data['ketua']);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $data['iku']->id)
            ->call('hapusBuktiLamaBagianKustom', $data['poin']->id, $berkas->id);

        $this->assertDatabaseHas('berkas', ['id' => $berkas->id]);
    }

    /**
     * Regresi: Capaian "dikembalikan" KARENA realisasi RTL ditolak (Kegiatan-nya
     * sendiri sudah "diverifikasi", tidak berubah sama sekali di request ini).
     * Sebelum diperbaiki, ajukanIsian() hanya memindahkan Capaian::status ke
     * "diajukan" bila ADA Kegiatan yang ikut berpindah status — jadi mengunggah
     * ulang bukti evaluasi RTL SENDIRIAN (tanpa menyentuh Kegiatan apa pun) tidak
     * pernah memindahkan isian keluar dari "dikembalikan" sama sekali.
     */
    public function test_ajukan_ulang_setelah_perbaiki_bukti_rtl_memindahkan_status_ke_diajukan(): void
    {
        Storage::fake('local');

        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji RTL Ditolak', 'username' => 'ketua-uji-rtl-ditolak@example.test', 'email' => 'ketua-uji-rtl-ditolak@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create([
            'kode' => 'UJI-RTL-DITOLAK', 'indikator' => 'Indikator uji RTL ditolak', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $periodeRtl = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIKEMBALIKAN]);

        // Kegiatan-nya SENDIRI sudah "diverifikasi" (terkunci, tidak akan ikut berubah
        // di request ini) — satu-satunya penyebab "dikembalikan" adalah RTL di bawah.
        Kegiatan::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan sudah diverifikasi', 'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        $poin = RtlEvaluasi::create([
            'iku_id' => $iku->id, 'periode_id' => $periodeRtl->id,
            'rtl_teks' => 'Rencana uji RTL ditolak', 'berlaku_bulan' => 'RTL untuk Juli, Agustus, dan September',
            'pic' => 'PIC Uji', 'batas_waktu' => '2026-09-30', 'realisasi' => 'Sudah dilaksanakan',
            'status_verifikasi' => 'ditolak', 'catatan' => 'Bukti belum sesuai',
        ]);

        $this->actingAs($ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id)
            ->set("evaluasi.{$poin->id}.bukti", [UploadedFile::fake()->create('realisasi-baru.pdf', 100, 'application/pdf')])
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $this->assertSame(Capaian::STATUS_DIAJUKAN, Capaian::where('iku_id', $iku->id)->value('status'));

        // status_verifikasi & catatan penolakan LAMA tetap "ditolak" (bukan ditarik
        // balik ke "menunggu") — supaya Tim SAKIP masih ingat apa yang salah
        // sebelumnya saat memeriksa bukti baru ini; baru kosong begitu mereka
        // sendiri menandai "Sesuai".
        $poin->refresh();
        $this->assertSame('ditolak', $poin->status_verifikasi);
        $this->assertSame('Bukti belum sesuai', $poin->catatan);
    }

    public function test_hapus_bukti_lama_evaluasi_menghapus_berkas_yang_ditolak(): void
    {
        Storage::fake('local');

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
        Storage::disk('local')->put($berkas->path, 'isi pdf palsu');

        $this->actingAs($ketua);

        $component = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id)
            ->call('hapusBuktiLamaEvaluasi', $poin->id, $berkas->id);

        $this->assertDatabaseMissing('berkas', ['id' => $berkas->id]);
        Storage::disk('local')->assertMissing($berkas->path);

        // Catatan penolakan tersalin ke poin RTL itu sendiri sebelum berkasnya lenyap —
        // sama seperti hapusBuktiLama() untuk Kegiatan (lihat test di atas).
        $this->assertSame('Belum sesuai rencana', $poin->fresh()->catatan_bukti_dihapus);
    }

    /**
     * Sama seperti hapusBuktiLama() untuk Kegiatan — begitu berkas realisasi RTL
     * ditandai "Sesuai" (terverifikasi) oleh Tim SAKIP, hapusBuktiLamaEvaluasi()
     * menolak menghapusnya.
     */
    public function test_hapus_bukti_lama_evaluasi_menolak_berkas_yang_sudah_terverifikasi(): void
    {
        $peranKetua = Role::create(['nama' => 'Ketua Tim']);
        $ketua = User::create([
            'nama' => 'Ketua Uji Evaluasi Sesuai', 'username' => 'ketua-uji-evaluasi-sesuai@example.test', 'email' => 'ketua-uji-evaluasi-sesuai@example.test',
            'password' => 'password', 'role_id' => $peranKetua->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $iku = MasterIku::create(['kode' => 'UJI-EVALUASI-SESUAI', 'indikator' => 'Indikator uji evaluasi sesuai', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);

        $periodeRtl = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        $poin = RtlEvaluasi::create([
            'iku_id' => $iku->id, 'periode_id' => $periodeRtl->id,
            'rtl_teks' => 'Rencana uji evaluasi sesuai', 'berlaku_bulan' => 'RTL untuk Juli, Agustus, dan September',
            'pic' => 'PIC Uji', 'batas_waktu' => '2026-09-30',
        ]);

        $berkas = Berkas::create([
            'ref_id' => $poin->id, 'ref_type' => RtlEvaluasi::class, 'kategori' => 'evaluasi_rtl',
            'nama_file' => 'realisasi-sesuai.pdf', 'path' => 'bukti-evaluasi-rtl/realisasi-sesuai.pdf',
            'status_verifikasi' => 'terverifikasi',
        ]);

        $this->actingAs($ketua);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 8)
            ->set('iku_id', $iku->id)
            ->call('hapusBuktiLamaEvaluasi', $poin->id, $berkas->id);

        $this->assertDatabaseHas('berkas', ['id' => $berkas->id]);
    }
}
