<?php

namespace Tests\Feature;

use App\Livewire\LakinDetail;
use App\Livewire\LakinList;
use App\Models\Capaian;
use App\Models\Lakin;
use App\Models\LakinBaris;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Services\LakinBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LakinTest extends TestCase
{
    use RefreshDatabase;

    protected function loginSebagai(string $peranNama): User
    {
        $peran = Role::firstOrCreate(['nama' => $peranNama]);
        $user = User::create([
            'nama' => "$peranNama Uji", 'username' => strtolower(str_replace(' ', '', $peranNama)).'-'.uniqid(), 'email' => strtolower(str_replace(' ', '', $peranNama)).'-'.uniqid().'@example.test',
            'password' => 'password', 'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($user);

        return $user;
    }

    protected function buatCapaian(MasterIku $iku, int $tahun, int $bulan, int $triwulan, float $targetPk, float $realisasi): Capaian
    {
        $periode = Periode::firstOrCreate(
            ['tahun' => $tahun, 'bulan' => $bulan],
            ['triwulan' => $triwulan, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]
        );

        return Capaian::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'target_pk' => $targetPk, 'target_tw' => $targetPk / 4, 'realisasi' => $realisasi,
            'persentase_capaian' => $targetPk > 0 ? round($realisasi / $targetPk * 100, 2) : null,
        ]);
    }

    public function test_iku_tersedia_untuk_tahun_menghitung_kumulatif_dari_capaian(): void
    {
        $iku = MasterIku::create(['kode' => '1131', 'indikator' => 'Uji', 'tim' => 'Sosial', 'penanggung_jawab' => 'A', 'sasaran' => 'Sasaran Uji']);

        $this->buatCapaian($iku, 2026, 3, 1, 100, 25);
        $this->buatCapaian($iku, 2026, 6, 2, 100, 25);

        $tersedia = app(LakinBuilderService::class)->ikuTersediaUntukTahun(2026);

        $this->assertCount(1, $tersedia);
        $data = $tersedia->get($iku->id);
        $this->assertEquals(100, $data->target);
        $this->assertEquals(50, $data->realisasi); // 25 + 25 dari dua triwulan.
        $this->assertEquals(50, $data->capaian_persen);
    }

    public function test_tambah_dari_iku_hanya_memasukkan_yang_dipilih(): void
    {
        $ikuDipilih = MasterIku::create(['kode' => '1131', 'indikator' => 'Uji A', 'tim' => 'Sosial', 'penanggung_jawab' => 'A', 'sasaran' => 'Sasaran Uji']);
        $ikuTidakDipilih = MasterIku::create(['kode' => '1132', 'indikator' => 'Uji B', 'tim' => 'Sosial', 'penanggung_jawab' => 'A']);

        $this->buatCapaian($ikuDipilih, 2026, 3, 1, 100, 25);
        $this->buatCapaian($ikuTidakDipilih, 2026, 3, 1, 100, 40);

        $lakin = Lakin::create(['tahun' => 2026]);
        app(LakinBuilderService::class)->tambahDariIku($lakin, [$ikuDipilih->id]);

        $lakin->load('baris');
        $this->assertCount(1, $lakin->baris);
        $this->assertSame($ikuDipilih->id, $lakin->baris->first()->iku_id);
    }

    public function test_segarkan_angka_tidak_menimpa_baris_custom(): void
    {
        $iku = MasterIku::create(['kode' => '1131', 'indikator' => 'Uji', 'tim' => 'Sosial', 'penanggung_jawab' => 'A']);
        $this->buatCapaian($iku, 2026, 3, 1, 100, 25);

        $lakin = Lakin::create(['tahun' => 2026]);
        app(LakinBuilderService::class)->tambahDariIku($lakin, [$iku->id]);

        LakinBaris::create([
            'lakin_id' => $lakin->id, 'iku_id' => null, 'indikator' => 'Baris custom', 'urutan' => 99,
        ]);

        app(LakinBuilderService::class)->segarkanBaris($lakin);

        $this->assertDatabaseHas('lakin_baris', ['lakin_id' => $lakin->id, 'indikator' => 'Baris custom']);
        $this->assertDatabaseCount('lakin_baris', 2);
    }

    public function test_tim_sakip_bisa_membentuk_lakin(): void
    {
        $this->loginSebagai('Tim SAKIP');

        Livewire::test(LakinList::class)
            ->set('tahunBaru', '2026')
            ->call('bentukLakin')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('lakin', ['tahun' => 2026]);
    }

    public function test_ketua_tim_tidak_bisa_membentuk_lakin(): void
    {
        // abort_unless() di dalam method Livewire ditangkap Livewire sendiri jadi
        // metadata respons (bukan dilempar sebagai exception PHP biasa ke pemanggil
        // test), jadi yang diperiksa di sini adalah AKIBATNYA: dokumen LAKIN tidak
        // ikut terbentuk — bukan mekanisme penolakannya.
        $this->loginSebagai('Ketua Tim');

        try {
            Livewire::test(LakinList::class)
                ->set('tahunBaru', '2026')
                ->call('bentukLakin');
        } catch (HttpException $e) {
            // Boleh saja tetap terlempar di versi Livewire lain — abaikan, yang
            // penting dicek di bawah adalah tidak ada dokumen yang ikut terbentuk.
        }

        $this->assertDatabaseMissing('lakin', ['tahun' => 2026]);
    }

    public function test_semua_peran_bisa_melihat_daftar_lakin(): void
    {
        // Buat SEMUA role dulu sebelum login manapun — Role::semuaNama() di-cache
        // permanen begitu pertama dipanggil (lihat catatan di tests/TestCase.php),
        // jadi kalau baru dibuat satu-satu sambil login bergantian, role yang dibuat
        // belakangan tidak akan ikut termuat di cache yang sudah kadung terbentuk.
        foreach (['Ketua Tim', 'Tim SAKIP', 'Kepala'] as $peran) {
            \App\Models\Role::firstOrCreate(['nama' => $peran]);
        }

        foreach (['Ketua Tim', 'Tim SAKIP', 'Kepala'] as $peran) {
            $this->loginSebagai($peran);
            $this->get(route('lakin.index'))->assertOk();
        }
    }

    public function test_tim_sakip_bisa_menambahkan_iku_terpilih_lewat_checklist(): void
    {
        $this->loginSebagai('Tim SAKIP');
        $ikuDipilih = MasterIku::create(['kode' => '1131', 'indikator' => 'Uji A', 'tim' => 'Sosial', 'penanggung_jawab' => 'A']);
        $ikuTidakDipilih = MasterIku::create(['kode' => '1132', 'indikator' => 'Uji B', 'tim' => 'Sosial', 'penanggung_jawab' => 'A']);
        $this->buatCapaian($ikuDipilih, 2026, 3, 1, 100, 25);
        $this->buatCapaian($ikuTidakDipilih, 2026, 3, 1, 100, 40);

        $lakin = Lakin::create(['tahun' => 2026]);

        Livewire::test(LakinDetail::class, ['lakin' => $lakin])
            ->set('ikuTerpilihUntukTambah', [$ikuDipilih->id])
            ->call('tambahDariCapaian')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('lakin_baris', 1);
        $this->assertDatabaseHas('lakin_baris', ['lakin_id' => $lakin->id, 'iku_id' => $ikuDipilih->id]);
    }

    public function test_tambah_dari_capaian_menolak_bila_tidak_ada_yang_dicentang(): void
    {
        $this->loginSebagai('Tim SAKIP');
        $lakin = Lakin::create(['tahun' => 2026]);

        Livewire::test(LakinDetail::class, ['lakin' => $lakin])
            ->call('tambahDariCapaian')
            ->assertHasErrors(['ikuTerpilihUntukTambah']);

        $this->assertDatabaseCount('lakin_baris', 0);
    }

    public function test_tim_sakip_bisa_menambah_dan_menghapus_baris_custom(): void
    {
        $this->loginSebagai('Tim SAKIP');
        $lakin = Lakin::create(['tahun' => 2026]);

        $component = Livewire::test(LakinDetail::class, ['lakin' => $lakin])
            ->set('indikatorBaru', 'Indikator custom')
            ->set('targetBaru', '100')
            ->set('realisasiBaru', '80')
            ->call('tambahBaris')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('lakin_baris', ['lakin_id' => $lakin->id, 'indikator' => 'Indikator custom', 'capaian_persen' => 80]);

        $baris = LakinBaris::where('lakin_id', $lakin->id)->first();

        $component->call('hapusBaris', $baris->id);

        $this->assertDatabaseCount('lakin_baris', 0);
    }

    public function test_kepala_tidak_bisa_menambah_baris(): void
    {
        $this->loginSebagai('Kepala');
        $lakin = Lakin::create(['tahun' => 2026]);

        try {
            Livewire::test(LakinDetail::class, ['lakin' => $lakin])
                ->set('indikatorBaru', 'Indikator custom')
                ->call('tambahBaris');
        } catch (HttpException $e) {
            // Lihat catatan di test_ketua_tim_tidak_bisa_membentuk_lakin di atas.
        }

        $this->assertDatabaseCount('lakin_baris', 0);
    }

    public function test_unduh_excel_menghasilkan_response_berhasil(): void
    {
        $this->loginSebagai('Tim SAKIP');
        $lakin = Lakin::create(['tahun' => 2026]);
        LakinBaris::create(['lakin_id' => $lakin->id, 'indikator' => 'Indikator uji', 'target' => 100, 'realisasi' => 90, 'capaian_persen' => 90, 'urutan' => 0]);

        Livewire::test(LakinDetail::class, ['lakin' => $lakin])
            ->call('unduhExcel')
            ->assertFileDownloaded("lakin-{$lakin->tahun}.xlsx");
    }
}
