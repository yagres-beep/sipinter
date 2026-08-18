<?php

namespace Tests\Feature;

use App\Livewire\AkunAktif;
use App\Livewire\PenugasanIku;
use App\Models\IkuPengecualian;
use App\Models\IkuPenugasan;
use App\Models\MasterIku;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KeanggotaanDanPenugasanTest extends TestCase
{
    use RefreshDatabase;

    protected function loginSebagaiSakip(): void
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip@example.test', 'email' => 'sakip@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));
    }

    protected function buatKetua(string $nama, string $email): User
    {
        $peran = Role::firstOrCreate(['nama' => 'Ketua Tim']);

        return User::create([
            'nama' => $nama, 'username' => $email, 'email' => $email, 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);
    }

    public function test_tambah_dan_hapus_keanggotaan_tim(): void
    {
        $this->loginSebagaiSakip();
        $ketua = $this->buatKetua('Andi Saputra', 'andi@example.test');

        Livewire::test(AkunAktif::class)
            ->set("timBaru.{$ketua->id}", 'Statistik Sosial')
            ->call('tambahTim', $ketua->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('user_tim', ['user_id' => $ketua->id, 'tim' => 'Statistik Sosial']);

        $userTim = UserTim::where('user_id', $ketua->id)->first();

        Livewire::test(AkunAktif::class)->call('hapusTim', $userTim->id);

        $this->assertDatabaseMissing('user_tim', ['id' => $userTim->id]);
    }

    public function test_penanggung_jawab_otomatis_mengikuti_keanggotaan_tim(): void
    {
        $this->loginSebagaiSakip();
        $ketua = $this->buatKetua('Rina Marlina', 'rina@example.test');

        $iku = MasterIku::create([
            'kode' => 'IKU-X', 'indikator' => 'Indikator X', 'tim' => 'Statistik Produksi', 'penanggung_jawab' => 'PJ X',
        ]);

        $this->assertCount(0, $iku->penanggungJawabOtomatis());

        UserTim::create(['user_id' => $ketua->id, 'tim' => 'Statistik Produksi']);

        $this->assertCount(1, $iku->penanggungJawabOtomatis());
        $this->assertSame('Rina Marlina', $iku->penanggungJawabOtomatis()->first()->nama);
    }

    public function test_tambah_dan_hapus_penugasan_manual(): void
    {
        $this->loginSebagaiSakip();
        $ketua = $this->buatKetua('Muhamad Ridwan', 'ridwan@example.test');

        $iku = MasterIku::create([
            'kode' => 'IKU-Y', 'indikator' => 'Indikator Y', 'tim' => 'Pembina Statistik Sektoral', 'penanggung_jawab' => 'PJ Y',
        ]);

        Livewire::test(PenugasanIku::class)
            ->set("orangBaru.{$iku->id}", [(string) $ketua->id])
            ->call('tambahManual', $iku->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('iku_penugasan', ['iku_id' => $iku->id, 'user_id' => $ketua->id]);

        $penugasan = IkuPenugasan::where('iku_id', $iku->id)->first();

        Livewire::test(PenugasanIku::class)->call('hapusManual', $penugasan->id);

        $this->assertDatabaseMissing('iku_penugasan', ['id' => $penugasan->id]);
    }

    public function test_tambah_penugasan_manual_bisa_pilih_lebih_dari_satu_orang_sekaligus(): void
    {
        $this->loginSebagaiSakip();
        $ketuaSatu = $this->buatKetua('Dewi Lestari', 'dewi@example.test');
        $ketuaDua = $this->buatKetua('Fajar Nugroho', 'fajar@example.test');

        $iku = MasterIku::create([
            'kode' => 'IKU-W', 'indikator' => 'Indikator W', 'tim' => 'Tim W', 'penanggung_jawab' => 'PJ W',
        ]);

        Livewire::test(PenugasanIku::class)
            ->set("orangBaru.{$iku->id}", [(string) $ketuaSatu->id, (string) $ketuaDua->id])
            ->call('tambahManual', $iku->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('iku_penugasan', ['iku_id' => $iku->id, 'user_id' => $ketuaSatu->id]);
        $this->assertDatabaseHas('iku_penugasan', ['iku_id' => $iku->id, 'user_id' => $ketuaDua->id]);
    }

    public function test_pencarian_menyaring_daftar_iku_penugasan(): void
    {
        $this->loginSebagaiSakip();

        MasterIku::create(['kode' => 'ALPHA-1', 'indikator' => 'Indikator Alpha', 'tim' => 'Tim A', 'penanggung_jawab' => 'PJ A']);
        MasterIku::create(['kode' => 'BETA-2', 'indikator' => 'Indikator Beta', 'tim' => 'Tim B', 'penanggung_jawab' => 'PJ B']);

        Livewire::test(PenugasanIku::class)
            ->set('cari', 'ALPHA')
            ->assertSee('Indikator Alpha')
            ->assertDontSee('Indikator Beta');
    }

    public function test_kecualikan_otomatis_menyembunyikan_dari_pj_tanpa_mengeluarkan_dari_tim(): void
    {
        $this->loginSebagaiSakip();
        $ketuaB = $this->buatKetua('Budi', 'budi@example.test');
        $ketuaC = $this->buatKetua('Cici', 'cici@example.test');
        $ketuaD = $this->buatKetua('Dedi', 'dedi@example.test');

        $iku = MasterIku::create([
            'kode' => 'IKU-BCD', 'indikator' => 'Indikator BCD', 'tim' => 'Tim BCD', 'penanggung_jawab' => 'PJ BCD',
        ]);

        foreach ([$ketuaB, $ketuaC, $ketuaD] as $ketua) {
            UserTim::create(['user_id' => $ketua->id, 'tim' => 'Tim BCD']);
        }

        $this->assertCount(3, $iku->penanggungJawabOtomatis());

        Livewire::test(PenugasanIku::class)
            ->call('kecualikanOtomatis', $iku->id, $ketuaB->id)
            ->call('kecualikanOtomatis', $iku->id, $ketuaD->id)
            ->assertHasNoErrors();

        // Dikecualikan dari PJ otomatis IKU ini...
        $iku->refresh();
        $otomatis = $iku->penanggungJawabOtomatis();
        $this->assertCount(1, $otomatis);
        $this->assertSame('Cici', $otomatis->first()->nama);

        // ...tapi TETAP anggota tim (tidak terhapus dari user_tim).
        $this->assertDatabaseHas('user_tim', ['user_id' => $ketuaB->id, 'tim' => 'Tim BCD']);
        $this->assertDatabaseHas('user_tim', ['user_id' => $ketuaD->id, 'tim' => 'Tim BCD']);
    }

    public function test_sertakan_kembali_mengembalikan_pj_otomatis(): void
    {
        $this->loginSebagaiSakip();
        $ketua = $this->buatKetua('Eka Putra', 'eka@example.test');

        $iku = MasterIku::create([
            'kode' => 'IKU-E', 'indikator' => 'Indikator E', 'tim' => 'Tim E', 'penanggung_jawab' => 'PJ E',
        ]);

        UserTim::create(['user_id' => $ketua->id, 'tim' => 'Tim E']);

        $pengecualian = IkuPengecualian::create(['iku_id' => $iku->id, 'user_id' => $ketua->id]);
        $iku->refresh();
        $this->assertCount(0, $iku->penanggungJawabOtomatis());

        Livewire::test(PenugasanIku::class)->call('sertakanKembali', $pengecualian->id);

        $this->assertDatabaseMissing('iku_pengecualian', ['id' => $pengecualian->id]);
        $iku->refresh();
        $this->assertCount(1, $iku->penanggungJawabOtomatis());
    }

    public function test_pencarian_bisa_dari_nama_penanggung_jawab(): void
    {
        $this->loginSebagaiSakip();
        $ketua = $this->buatKetua('Gita Wulandari', 'gita@example.test');

        $iku = MasterIku::create([
            'kode' => 'IKU-G', 'indikator' => 'Indikator G', 'tim' => 'Tim G', 'penanggung_jawab' => 'PJ G',
        ]);
        MasterIku::create(['kode' => 'IKU-H', 'indikator' => 'Indikator H', 'tim' => 'Tim H', 'penanggung_jawab' => 'PJ H']);

        UserTim::create(['user_id' => $ketua->id, 'tim' => 'Tim G']);

        Livewire::test(PenugasanIku::class)
            ->set('cari', 'Gita')
            ->assertSee('Indikator G')
            ->assertDontSee('Indikator H');
    }

    public function test_filter_status_belum_ada_pj_menyaring_daftar(): void
    {
        $this->loginSebagaiSakip();
        $ketua = $this->buatKetua('Hasan Ali', 'hasan@example.test');

        MasterIku::create(['kode' => 'IKU-I', 'indikator' => 'Indikator Terisi', 'tim' => 'Tim I', 'penanggung_jawab' => 'PJ I']);
        MasterIku::create(['kode' => 'IKU-J', 'indikator' => 'Indikator Kosong', 'tim' => 'Tim J', 'penanggung_jawab' => 'PJ J']);

        UserTim::create(['user_id' => $ketua->id, 'tim' => 'Tim I']);

        Livewire::test(PenugasanIku::class)
            ->set('filterStatus', 'belum')
            ->assertSee('Indikator Kosong')
            ->assertDontSee('Indikator Terisi');
    }

    public function test_semua_penanggung_jawab_menggabungkan_otomatis_dan_manual_tanpa_duplikat(): void
    {
        $this->loginSebagaiSakip();
        $ketuaOtomatis = $this->buatKetua('Andi Saputra', 'andi2@example.test');
        $ketuaManual = $this->buatKetua('Bayu Manual', 'bayu@example.test');

        $iku = MasterIku::create([
            'kode' => 'IKU-Z', 'indikator' => 'Indikator Z', 'tim' => 'Statistik Sosial', 'penanggung_jawab' => 'PJ Z',
        ]);

        UserTim::create(['user_id' => $ketuaOtomatis->id, 'tim' => 'Statistik Sosial']);
        IkuPenugasan::create(['iku_id' => $iku->id, 'user_id' => $ketuaManual->id]);
        // Manual assignment untuk orang yang SAMA dengan otomatis tidak boleh dobel dihitung.
        IkuPenugasan::create(['iku_id' => $iku->id, 'user_id' => $ketuaOtomatis->id]);

        $semua = $iku->semuaPenanggungJawab();

        $this->assertCount(2, $semua);
    }
}
