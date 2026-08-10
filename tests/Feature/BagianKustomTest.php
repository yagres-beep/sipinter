<?php

namespace Tests\Feature;

use App\Livewire\BagianKustomManager;
use App\Livewire\PengisianKegiatan;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\MasterIku;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class BagianKustomTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsKetuaTim(): User
    {
        $peran = Role::firstOrCreate(['nama' => 'Ketua Tim']);

        $user = User::create([
            'nama' => 'Ketua Uji', 'email' => 'ketua-'.uniqid().'@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $this->actingAs($user);

        return $user;
    }

    protected function actingAsSakip(): User
    {
        $peran = Role::firstOrCreate(['nama' => 'Tim SAKIP']);

        $user = User::create([
            'nama' => 'SAKIP Uji', 'email' => 'sakip-'.uniqid().'@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $this->actingAs($user);

        return $user;
    }

    public function test_tim_sakip_bisa_menambah_bagian_kustom(): void
    {
        $this->actingAsSakip();

        Livewire::test(BagianKustomManager::class)
            ->set('namaBaru', 'Manajemen Risiko')
            ->set('deskripsiBaru', 'Catat risiko dan mitigasi tiap triwulan')
            ->set('wajibAkhirTriwulanBaru', true)
            ->call('tambah')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('bagian_kustom', [
            'nama' => 'Manajemen Risiko',
            'wajib_akhir_triwulan' => true,
            'aktif' => true,
        ]);
    }

    public function test_urutan_bagian_kustom_bisa_ditukar_naik_turun(): void
    {
        $this->actingAsSakip();

        $pertama = BagianKustom::create(['nama' => 'Bagian A', 'aktif' => true, 'urutan' => 0]);
        $kedua = BagianKustom::create(['nama' => 'Bagian B', 'aktif' => true, 'urutan' => 1]);

        // Urutan awal: A, B. Naikkan B -> harus jadi B, A.
        Livewire::test(BagianKustomManager::class)
            ->call('naikkan', $kedua->id);

        $urutanSekarang = BagianKustom::orderBy('urutan')->orderBy('id')->pluck('nama')->all();
        $this->assertSame(['Bagian B', 'Bagian A'], $urutanSekarang);

        // Daftar aktif yang dipakai Isian Kegiatan juga harus ikut urutan baru ini.
        BagianKustom::lupakanCache();
        $this->assertSame(['Bagian B', 'Bagian A'], BagianKustom::daftarAktif()->pluck('nama')->all());
    }

    public function test_nama_bagian_kustom_duplikat_ditolak(): void
    {
        $this->actingAsSakip();
        BagianKustom::create(['nama' => 'Manajemen Risiko', 'aktif' => true]);

        Livewire::test(BagianKustomManager::class)
            ->set('namaBaru', 'manajemen risiko')
            ->call('tambah')
            ->assertHasErrors(['namaBaru']);
    }

    public function test_bagian_kustom_dengan_poin_tidak_bisa_dihapus_permanen(): void
    {
        $this->actingAsSakip();
        $iku = MasterIku::create(['kode' => '1131', 'indikator' => 'Uji', 'tim' => 'Sosial', 'penanggung_jawab' => 'A']);
        $bagian = BagianKustom::create(['nama' => 'Manajemen Risiko', 'aktif' => true]);
        $periode = \App\Models\Periode::create(['tahun' => 2026, 'bulan' => 3, 'triwulan' => 1, 'bulan_ke' => 3, 'flag_bulan_terlewat' => true]);
        BagianKustomPoin::create(['bagian_kustom_id' => $bagian->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id, 'teks' => 'Risiko A']);

        Livewire::test(BagianKustomManager::class)
            ->call('hapus', $bagian->id)
            ->assertHasErrors(['hapus']);

        $this->assertDatabaseHas('bagian_kustom', ['id' => $bagian->id]);
    }

    public function test_bagian_kustom_nonaktif_tidak_muncul_di_isian_kegiatan(): void
    {
        $this->actingAsKetuaTim();
        BagianKustom::create(['nama' => 'Manajemen Risiko', 'aktif' => false]);

        $component = Livewire::test(PengisianKegiatan::class);

        $this->assertTrue($component->get('bagianKustomBlocks') === []);
    }

    public function test_poin_bagian_kustom_tanpa_bukti_ditolak_validasi(): void
    {
        $this->actingAsKetuaTim();
        $iku = MasterIku::create(['kode' => 'UJI-002', 'indikator' => 'Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $bagian = BagianKustom::create(['nama' => 'Manajemen Risiko', 'aktif' => true]);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 4) // bukan bulan terakhir triwulan — RTL Baru tidak menghalangi.
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Risiko keterlambatan data')
            ->call('ajukanIsian')
            ->assertHasErrors(["bagianKustomBlocks.{$bagian->id}.0.bukti"]);

        $this->assertDatabaseCount('bagian_kustom_poin', 0);
    }

    public function test_poin_bagian_kustom_dengan_bukti_tersimpan_dan_masuk_berkas(): void
    {
        $this->actingAsKetuaTim();
        $iku = MasterIku::create(['kode' => 'UJI-003', 'indikator' => 'Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        $bagian = BagianKustom::create(['nama' => 'Manajemen Risiko', 'aktif' => true]);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 4)
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set("bagianKustomBlocks.{$bagian->id}.0.teks", 'Risiko keterlambatan data')
            ->set("bagianKustomBlocks.{$bagian->id}.0.bukti", [UploadedFile::fake()->create('bukti-risiko.pdf', 100, 'application/pdf')])
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('bagian_kustom_poin', 1);

        $poin = BagianKustomPoin::first();
        $this->assertSame('Risiko keterlambatan data', $poin->teks);
        $this->assertSame($iku->id, $poin->iku_id);

        $berkas = Berkas::where('ref_type', BagianKustomPoin::class)->where('ref_id', $poin->id)->first();
        $this->assertNotNull($berkas);
        $this->assertSame('bagian_kustom', $berkas->kategori);
    }

    public function test_bagian_wajib_akhir_triwulan_menghalangi_pengajuan_tanpa_poin(): void
    {
        $this->actingAsKetuaTim();
        $iku = MasterIku::create(['kode' => 'UJI-004', 'indikator' => 'Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        BagianKustom::create(['nama' => 'Manajemen Risiko', 'wajib_akhir_triwulan' => true, 'aktif' => true]);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 9) // bulan terakhir Triwulan III.
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('kendalaBlocks.0.kendala', 'Kendala uji')
            ->set('rtlBaru.0.rtl_teks', 'RTL uji')
            ->set('rtlBaruPic', 'PIC Uji')
            ->call('ajukanIsian')
            ->assertHasErrors();

        $this->assertDatabaseCount('bagian_kustom_poin', 0);
    }

    public function test_bagian_wajib_akhir_triwulan_tidak_menghalangi_bulan_biasa(): void
    {
        $this->actingAsKetuaTim();
        $iku = MasterIku::create(['kode' => 'UJI-005', 'indikator' => 'Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji']);
        BagianKustom::create(['nama' => 'Manajemen Risiko', 'wajib_akhir_triwulan' => true, 'aktif' => true]);

        Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)
            ->set('bulan', 4) // bulan pertama triwulan — bagian wajib-akhir-triwulan tidak berlaku di sini.
            ->set('iku_id', $iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Kegiatan uji')
            ->set('blocks.0.jenis', 'bukan_survei_sensus')
            ->set('blocks.0.bukti', [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->set('kendalaBlocks.0.kendala', 'Kendala uji')
            ->call('ajukanIsian')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('bagian_kustom_poin', 0);
    }

    public function test_notula_bagian_satu_menyertakan_poin_bagian_kustom(): void
    {
        $iku = MasterIku::create(['kode' => 'UJI-006', 'indikator' => 'Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $bagian = BagianKustom::create(['nama' => 'Manajemen Risiko', 'aktif' => true]);
        $periode = \App\Models\Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
        BagianKustomPoin::create(['bagian_kustom_id' => $bagian->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id, 'teks' => 'Risiko keterlambatan data']);

        $notula = app(\App\Services\NotulaService::class)->untukTriwulan(2026, 2);
        $html = app(\App\Services\NotulaService::class)->susunBagianSatu($notula);

        $this->assertStringContainsString('Manajemen Risiko', $html);
        $this->assertStringContainsString('Risiko keterlambatan data', $html);
    }
}
