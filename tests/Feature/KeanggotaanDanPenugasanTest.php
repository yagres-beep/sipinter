<?php

namespace Tests\Feature;

use App\Livewire\KeanggotaanTim;
use App\Livewire\PenugasanIku;
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
            'nama' => 'SAKIP Uji', 'email' => 'sakip@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));
    }

    protected function buatKetua(string $nama, string $email): User
    {
        $peran = Role::firstOrCreate(['nama' => 'Ketua Tim']);

        return User::create([
            'nama' => $nama, 'email' => $email, 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);
    }

    public function test_tambah_dan_hapus_keanggotaan_tim(): void
    {
        $this->loginSebagaiSakip();
        $ketua = $this->buatKetua('Andi Saputra', 'andi@example.test');

        Livewire::test(KeanggotaanTim::class)
            ->set("timBaru.{$ketua->id}", 'Statistik Sosial')
            ->call('tambahTim', $ketua->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('user_tim', ['user_id' => $ketua->id, 'tim' => 'Statistik Sosial']);

        $userTim = UserTim::where('user_id', $ketua->id)->first();

        Livewire::test(KeanggotaanTim::class)->call('hapusTim', $userTim->id);

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
            ->set("orangBaru.{$iku->id}", (string) $ketua->id)
            ->call('tambahManual', $iku->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('iku_penugasan', ['iku_id' => $iku->id, 'user_id' => $ketua->id]);

        $penugasan = IkuPenugasan::where('iku_id', $iku->id)->first();

        Livewire::test(PenugasanIku::class)->call('hapusManual', $penugasan->id);

        $this->assertDatabaseMissing('iku_penugasan', ['id' => $penugasan->id]);
    }

    public function test_pencarian_menyaring_daftar_iku_penugasan(): void
    {
        $this->loginSebagaiSakip();

        MasterIku::create(['kode' => 'ALPHA-1', 'indikator' => 'Indikator Alpha', 'tim' => 'Tim A', 'penanggung_jawab' => 'PJ A']);
        MasterIku::create(['kode' => 'BETA-2', 'indikator' => 'Indikator Beta', 'tim' => 'Tim B', 'penanggung_jawab' => 'PJ B']);

        Livewire::test(PenugasanIku::class)
            ->set('cari', 'ALPHA')
            ->assertSee('ALPHA-1')
            ->assertDontSee('BETA-2');
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
