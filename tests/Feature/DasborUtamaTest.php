<?php

namespace Tests\Feature;

use App\Livewire\DasborUtama;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\RiwayatStatusCapaian;
use App\Models\Role;
use App\Models\RtlEvaluasi;
use App\Models\User;
use App\Models\UserTim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DasborUtamaTest extends TestCase
{
    use RefreshDatabase;

    protected function siapkanData(): void
    {
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        $ikuA = MasterIku::create(['kode' => 'ALPHA-1', 'indikator' => 'Indikator Alpha', 'tim' => 'Tim A', 'penanggung_jawab' => 'PJ A']);
        $ikuB = MasterIku::create(['kode' => 'BETA-2', 'indikator' => 'Indikator Beta', 'tim' => 'Tim B', 'penanggung_jawab' => 'PJ B']);

        Kegiatan::create(['iku_id' => $ikuA->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'K1', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'diajukan']);
        Kegiatan::create(['iku_id' => $ikuB->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'K2', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'diverifikasi']);
        // Status "diajukan" (bukan default "draft") — kedua isian ini SUDAH diajukan ke
        // Tim SAKIP di fixture ini (lihat status_dokumen Kegiatan di atas), jadi harus
        // tetap terlihat Tim SAKIP di dasbor (Capaian::status draft khusus isian yang
        // belum pernah diajukan sama sekali, lihat DasborUtama::daftarCapaian()).
        Capaian::create(['iku_id' => $ikuA->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);
        Capaian::create(['iku_id' => $ikuB->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);
    }

    public function test_ringkasan_menghitung_capaian_sedang_ditangani_sebagai_menunggu_verifikasi(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $iku = MasterIku::create(['kode' => 'DELTA-4', 'indikator' => 'Indikator Delta', 'tim' => 'Tim D', 'penanggung_jawab' => 'PJ D']);
        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_SEDANG_DITANGANI]);

        $ringkasan = Livewire::test(DasborUtama::class)->viewData('ringkasan');

        // Sebelum diperbaiki, Capaian berstatus "sedang_ditangani" tidak terhitung di
        // kedua sisi (bukan "menunggu_verifikasi" ataupun "sudah_diverifikasi") —
        // seolah hilang dari ringkasan dasbor.
        $this->assertSame(1, $ringkasan['menunggu_verifikasi']);
    }

    protected function loginSebagai(string $peranNama): User
    {
        $peran = Role::firstOrCreate(['nama' => $peranNama]);
        $user = User::create([
            'nama' => "$peranNama Uji", 'username' => strtolower(str_replace(' ', '', $peranNama)), 'email' => strtolower(str_replace(' ', '', $peranNama)).'@example.test',
            'password' => 'password', 'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_pencarian_menyaring_tabel_dasbor(): void
    {
        $this->loginSebagai('Tim SAKIP');
        $this->siapkanData();

        Livewire::test(DasborUtama::class)
            ->set('cari', 'ALPHA')
            ->assertSee('ALPHA-1')
            ->assertDontSee('BETA-2');
    }

    public function test_filter_triwulan_menyaring_kegiatan_di_luar_triwulan(): void
    {
        $this->loginSebagai('Ketua Tim');
        $this->siapkanData();

        Livewire::test(DasborUtama::class)
            ->set('filterTriwulan', '1')
            ->assertDontSee('ALPHA-1')
            ->assertDontSee('BETA-2');
    }

    public function test_memilih_bulan_langsung_menyesuaikan_filter_triwulan(): void
    {
        $this->loginSebagai('Ketua Tim');
        $this->siapkanData();

        $component = Livewire::test(DasborUtama::class)
            ->set('filterBulan', '4'); // April -> Triwulan II, tanpa memilih triwulan dulu.

        $this->assertSame('2', $component->get('filterTriwulan'));
    }

    public function test_peringatan_iku_belum_terisi_triwulan_berjalan(): void
    {
        Carbon::setTestNow('2026-08-15'); // Triwulan III 2026 — sama seperti fixture siapkanData().

        $this->loginSebagai('Tim SAKIP');
        $this->siapkanData();

        // IKU ketiga ini sengaja TIDAK diberi kegiatan sama sekali — harus muncul di peringatan.
        MasterIku::create(['kode' => 'GAMMA-3', 'indikator' => 'Indikator Gamma', 'tim' => 'Tim C', 'penanggung_jawab' => 'PJ C']);

        Livewire::test(DasborUtama::class)
            ->assertSee('1 IKU belum ada isian')
            ->assertSee('GAMMA-3');

        Carbon::setTestNow();
    }

    public function test_baris_tim_sakip_tertaut_ke_verifikasi_show(): void
    {
        $this->loginSebagai('Tim SAKIP');
        $this->siapkanData();

        $response = $this->get('/dashboard');
        $response->assertOk();
        $response->assertSee('verifikasi/', false);
    }

    public function test_baris_ketua_tim_tertaut_ke_pengisian_dengan_iku_terpilih(): void
    {
        $this->loginSebagai('Ketua Tim');
        $this->siapkanData();

        $ikuA = MasterIku::where('kode', 'ALPHA-1')->firstOrFail();

        $response = $this->get('/dashboard');
        $response->assertOk();
        $response->assertSee('iku_id='.$ikuA->id, false);
    }

    /**
     * Skenario: 1 IKU, 5 kegiatan (3 diverifikasi + 2 dikembalikan) — dasbor tetap
     * satu baris untuk IKU+bulan ini (bukan 5 baris terpisah seperti sebelum
     * Capaian::status ada), dengan rincian status kegiatannya terlihat di kolomnya sendiri.
     */
    public function test_rincian_status_kegiatan_tampil_saat_sebagian_dikembalikan(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $iku = MasterIku::create(['kode' => 'DELTA-4', 'indikator' => 'Indikator Delta', 'tim' => 'Tim D', 'penanggung_jawab' => 'PJ D']);

        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIKEMBALIKAN]);

        for ($i = 1; $i <= 3; $i++) {
            Kegiatan::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => "Kegiatan verif {$i}", 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'diverifikasi']);
        }
        for ($i = 1; $i <= 2; $i++) {
            Kegiatan::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => "Kegiatan kembali {$i}", 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'dikembalikan']);
        }

        Livewire::test(DasborUtama::class)
            ->assertSeeInOrder(['DELTA-4'])
            ->assertSee('3 Diverifikasi')
            ->assertSee('2 Dikembalikan');

        // Tetap SATU baris untuk IKU+bulan ini, bukan 5 baris.
        $this->assertDatabaseCount('capaian', 1);
    }

    public function test_capaian_draft_disembunyikan_dari_tim_sakip_tapi_tetap_terlihat_ketua_tim(): void
    {
        // Kedua role dibuat lebih dulu (SEBELUM render Livewire pertama) — Role::semuaNama()
        // di-cache PERMANEN sejak akses pertama (lihat App\Models\Role), jadi kalau role
        // "Ketua Tim" baru dibuat SETELAH dasbor Tim SAKIP sempat dirender, cache lama
        // (tanpa "Ketua Tim") akan bikin auth()->user()->namaRole() balik null.
        Role::firstOrCreate(['nama' => 'Tim SAKIP']);
        Role::firstOrCreate(['nama' => 'Ketua Tim']);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $iku = MasterIku::create(['kode' => 'EPSILON-5', 'indikator' => 'Indikator Epsilon', 'tim' => 'Tim E', 'penanggung_jawab' => 'PJ E']);
        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DRAFT]);
        // Kegiatan draft ini SENGAJA disertakan supaya EPSILON-5 tidak juga muncul di
        // peringatan "IKU belum ada isian triwulan ini" (lihat
        // DasborUtama::ikuBelumTerisiTriwulanIni(), tidak terkait fitur yang diuji di sini).
        Kegiatan::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'Draf K1', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'draft']);

        $this->loginSebagai('Tim SAKIP');
        Livewire::test(DasborUtama::class)->assertDontSee('EPSILON-5');

        $this->loginSebagai('Ketua Tim');
        Livewire::test(DasborUtama::class)->assertSee('EPSILON-5');
    }

    public function test_rincian_bukti_rtl_ditolak_tampil_di_dasbor(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $periodeRtl = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $periodeCapaian = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $iku = MasterIku::create(['kode' => 'ZETA-6', 'indikator' => 'Indikator Zeta', 'tim' => 'Tim Z', 'penanggung_jawab' => 'PJ Z']);

        // Capaian ini "Dikembalikan" KARENA bukti RTL ditolak — semua Kegiatan-nya
        // sendiri sudah "Diverifikasi", jadi tanpa rincian RTL, penyebabnya tidak
        // terlihat sama sekali di tabel dasbor (lihat RtlEvaluasi::rincianStatusVerifikasi()).
        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periodeCapaian->id, 'status' => Capaian::STATUS_DIKEMBALIKAN]);
        Kegiatan::create(['iku_id' => $iku->id, 'periode_id' => $periodeCapaian->id, 'uraian_kegiatan' => 'K1', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'diverifikasi']);

        RtlEvaluasi::create([
            'iku_id' => $iku->id, 'periode_id' => $periodeRtl->id, 'rtl_teks' => 'RTL 1',
            'realisasi' => 'Sudah dilaksanakan', 'status_verifikasi' => 'ditolak', 'catatan' => 'Belum sesuai',
        ]);

        Livewire::test(DasborUtama::class)
            ->assertSee('ZETA-6')
            ->assertSee('1 Diverifikasi')
            ->assertSee('RTL 1 Tidak Sesuai');
    }

    /**
     * Skenario dari laporan pengguna: 1 IKU, 2 kegiatan + 3 Kendala & Solusi + 3 RTL
     * (semuanya "diajukan"/"menunggu") — kolom "Item" harus menghitung SELURUH jenis
     * (2+3+3=8), bukan cuma Kegiatan seperti sebelumnya, supaya kalau salah satu
     * Kendala & Solusi nanti ditolak Tim SAKIP tetap kelihatan di rincian (bukan
     * cuma lewat badge status besar Capaian::status).
     */
    public function test_item_menghitung_kegiatan_kendala_solusi_dan_rtl_gabungan(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 9, 'triwulan' => 3, 'bulan_ke' => 3, 'flag_bulan_terlewat' => false]);
        $iku = MasterIku::create(['kode' => 'ETA-7', 'indikator' => 'Indikator Eta', 'tim' => 'Tim H', 'penanggung_jawab' => 'PJ H']);

        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);

        Kegiatan::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'K1', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'diajukan']);
        Kegiatan::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'K2', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'diajukan']);

        for ($i = 1; $i <= 3; $i++) {
            KendalaSolusi::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'kendala' => "Kendala {$i}", 'solusi' => "Solusi {$i}"]);
        }

        for ($i = 1; $i <= 3; $i++) {
            RtlEvaluasi::create([
                'iku_id' => $iku->id, 'periode_id' => $periode->id, 'rtl_teks' => "RTL {$i}",
                'realisasi' => "Sudah dilaksanakan {$i}",
            ]);
        }

        $capaian = Capaian::where('iku_id', $iku->id)->firstOrFail();

        $component = Livewire::test(DasborUtama::class)
            ->assertSee('ETA-7')
            ->assertSee('K&S 3 Menunggu');

        $this->assertSame(8, $component->viewData('jumlahItem')->get($capaian->id));
    }

    /**
     * Skenario: RTL BARU yang ditetapkan Ketua Tim triwulan ini untuk triwulan
     * BERIKUTNYA (Bagian 5) tersimpan di baris Periode triwulan berikutnya, bukan
     * periode_id Capaian triwulan berjalan ini — sebelum diperbaiki, poin ini sama
     * sekali tidak ikut terhitung di kolom "Item"/rincian, padahal Tim SAKIP sudah
     * bisa memverifikasinya (status_verifikasi) sejak diajukan.
     */
    public function test_item_menghitung_rtl_triwulan_berikutnya(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 9, 'triwulan' => 3, 'bulan_ke' => 3, 'flag_bulan_terlewat' => false]);
        $periodeBerikutnya = Periode::create(['tahun' => 2026, 'bulan' => 10, 'triwulan' => 4, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $iku = MasterIku::create(['kode' => 'IOTA-9', 'indikator' => 'Indikator Iota', 'tim' => 'Tim I', 'penanggung_jawab' => 'PJ I']);

        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);

        Kegiatan::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'K1', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => 'diajukan']);

        RtlEvaluasi::create([
            'iku_id' => $iku->id, 'periode_id' => $periodeBerikutnya->id, 'rtl_teks' => 'Rencana TW IV',
            'status_dokumen' => 'diajukan', 'status_verifikasi' => 'menunggu',
        ]);

        $capaian = Capaian::where('iku_id', $iku->id)->firstOrFail();

        Livewire::test(DasborUtama::class)
            ->assertSee('IOTA-9')
            ->assertSee('RTL Berikutnya 1 Menunggu');

        $component = Livewire::test(DasborUtama::class);
        $this->assertSame(2, $component->viewData('jumlahItem')->get($capaian->id));
    }

    /**
     * Skenario dari laporan pengguna: MasterIku::tim kosong (sejumlah IKU lama belum
     * pernah diisi kolom ini) tapi Ketua Tim yang mengajukan isian ini SUDAH terdaftar
     * di satu tim (App\Models\UserTim, diisi saat registrasi) — kolom "Tim" di dasbor
     * sebelumnya tetap tampil "—" walau info timnya sebenarnya sudah ada, sekarang
     * harus jatuh ke tim Ketua Tim pengaju.
     */
    public function test_kolom_tim_jatuh_ke_tim_ketua_tim_pengaju_saat_master_iku_tim_kosong(): void
    {
        $this->loginSebagai('Tim SAKIP');
        $ketuaTim = User::create([
            'nama' => 'Ketua Uji', 'username' => 'ketuauji', 'email' => 'ketuauji@example.test',
            'password' => 'password', 'role_id' => Role::firstOrCreate(['nama' => 'Ketua Tim'])->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        UserTim::create(['user_id' => $ketuaTim->id, 'tim' => 'Tim Statistik Sosial']);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 9, 'triwulan' => 3, 'bulan_ke' => 3, 'flag_bulan_terlewat' => false]);
        $iku = MasterIku::create(['kode' => 'THETA-8', 'indikator' => 'Indikator Theta', 'tim' => null, 'penanggung_jawab' => 'PJ H']);

        $capaian = Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);
        RiwayatStatusCapaian::create(['capaian_id' => $capaian->id, 'status' => Capaian::STATUS_DIAJUKAN, 'user_id' => $ketuaTim->id]);

        Livewire::test(DasborUtama::class)
            ->assertSee('THETA-8')
            ->assertSee('Tim Statistik Sosial');
    }
}
