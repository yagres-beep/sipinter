<?php

namespace Tests\Feature;

use App\Livewire\BagianKustomManager;
use App\Models\BagianKustom;
use App\Models\FolderConfig;
use App\Models\RiwayatKonfigurasiBagianKustom;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\PenjagaDatabaseUji;
use Tests\TestCase;

/**
 * Perubahan konfigurasi Bagian Kustom dan riwayatnya ditulis dalam SATU transaksi:
 * bila riwayat gagal dicatat, perubahan konfigurasi ikut batal; percobaan ulang setelah
 * gangguan hilang berhasil normal.
 */
class RiwayatKonfigurasiTransaksiTest extends TestCase
{
    use PenjagaDatabaseUji;
    use RefreshDatabase;

    protected User $sakip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pastikanDatabaseUji();

        foreach (['Ketua Tim', 'Tim SAKIP', 'Kepala'] as $nama) {
            Role::firstOrCreate(['nama' => $nama]);
        }

        $this->sakip = User::create([
            'nama' => 'Sari SAKIP', 'username' => 'sari', 'email' => 'sari@example.test', 'password' => 'password',
            'role_id' => Role::where('nama', 'Tim SAKIP')->value('id'), 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($this->sakip);
    }

    protected function bagian(string $nama = 'Manajemen Risiko', int $urutan = 1): BagianKustom
    {
        return BagianKustom::create(['nama' => $nama, 'aktif' => true, 'peran' => ['ketua_tim'], 'penempatan' => ['pengisian'], 'kolom' => [], 'urutan' => $urutan]);
    }

    /** Riwayat gagal ditulis selama $gagal bernilai true. */
    protected function riwayatGagal(bool &$gagal): void
    {
        RiwayatKonfigurasiBagianKustom::creating(function () use (&$gagal) {
            if ($gagal) {
                throw new RuntimeException('Penulisan riwayat gagal (tiruan)');
            }
        });
    }

    protected function harusGagal(callable $aksi): void
    {
        try {
            $aksi();
            $this->fail('Aksi seharusnya gagal karena riwayat tidak bisa dicatat.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('riwayat gagal', $e->getMessage());
        }
    }

    public function test_simpan_berhasil_menulis_perubahan_dan_riwayat_dengan_nama_pelaku(): void
    {
        $bagian = $this->bagian();

        Livewire::test(BagianKustomManager::class)->call('buka', $bagian->id)
            ->set("form.{$bagian->id}.nama", 'Risiko Utama')->call('simpan', $bagian->id)->assertHasNoErrors();

        $this->assertSame('Risiko Utama', $bagian->fresh()->nama);
        $riwayat = RiwayatKonfigurasiBagianKustom::sole();
        $this->assertSame('ubah', $riwayat->aksi);
        $this->assertSame('Sari SAKIP', $riwayat->nama_pengguna);
        $this->assertSame($this->sakip->id, $riwayat->user_id);
    }

    public function test_riwayat_gagal_membatalkan_simpan_lalu_percobaan_ulang_berhasil(): void
    {
        $bagian = $this->bagian();
        $gagal = true;
        $this->riwayatGagal($gagal);
        $layar = Livewire::test(BagianKustomManager::class)->call('buka', $bagian->id)->set("form.{$bagian->id}.nama", 'Nama baru');

        $this->harusGagal(fn () => $layar->call('simpan', $bagian->id));

        $this->assertSame('Manajemen Risiko', $bagian->fresh()->nama, 'konfigurasi tidak berubah tanpa jejak');
        $this->assertSame(0, RiwayatKonfigurasiBagianKustom::count());

        $gagal = false;
        $layar->call('simpan', $bagian->id)->assertHasNoErrors();

        $this->assertSame('Nama baru', $bagian->fresh()->nama);
        $this->assertSame(1, RiwayatKonfigurasiBagianKustom::count());
    }

    public function test_riwayat_gagal_membatalkan_tambah_beserta_kategori_folder(): void
    {
        $gagal = true;
        $this->riwayatGagal($gagal);
        $polaAwal = FolderConfig::current()->pola_json;

        $this->harusGagal(fn () => Livewire::test(BagianKustomManager::class)->set('namaBaru', 'Bagian Baru')->call('tambah'));

        $this->assertSame(0, BagianKustom::count());
        $this->assertEquals($polaAwal, FolderConfig::current()->fresh()->pola_json);

        $gagal = false;
        Livewire::test(BagianKustomManager::class)->set('namaBaru', 'Bagian Baru')->call('tambah')->assertHasNoErrors();
        $this->assertSame(1, BagianKustom::count());
        $this->assertSame('buat', RiwayatKonfigurasiBagianKustom::sole()->aksi);
    }

    public function test_riwayat_gagal_membatalkan_aktif_nonaktif_urutan_dan_hapus(): void
    {
        $satu = $this->bagian('Satu', 1);
        $dua = $this->bagian('Dua', 2);
        $gagal = true;
        $this->riwayatGagal($gagal);
        $layar = Livewire::test(BagianKustomManager::class);

        $this->harusGagal(fn () => $layar->call('toggleAktif', $satu->id, false));
        $this->assertTrue($satu->fresh()->aktif);

        $this->harusGagal(fn () => $layar->call('turunkan', $satu->id));
        $this->assertSame([1, 2], [$satu->fresh()->urutan, $dua->fresh()->urutan]);

        $this->harusGagal(fn () => $layar->call('confirmHapus', $dua->id)->call('hapus'));
        $this->assertNotNull($dua->fresh());

        $this->assertSame(0, RiwayatKonfigurasiBagianKustom::count());

        $gagal = false;
        $layar->call('toggleAktif', $satu->id, false)->call('turunkan', $satu->id)->call('confirmHapus', $dua->id)->call('hapus');

        $this->assertFalse($satu->fresh()->aktif);
        $this->assertSame(2, $satu->fresh()->urutan);
        $this->assertNull($dua->fresh());
        $this->assertSame(['nonaktifkan', 'urutan', 'hapus'], RiwayatKonfigurasiBagianKustom::orderBy('id')->pluck('aksi')->all());
    }

    public function test_riwayat_lama_tetap_utuh_dan_nama_pelaku_tetap_ada_setelah_akun_dihapus(): void
    {
        $bagian = $this->bagian();
        Livewire::test(BagianKustomManager::class)->call('buka', $bagian->id)
            ->set("form.{$bagian->id}.deskripsi", 'Deskripsi')->call('simpan', $bagian->id);

        $this->sakip->delete();

        $riwayat = RiwayatKonfigurasiBagianKustom::sole();
        $this->assertNull($riwayat->user_id);
        $this->assertSame('Sari SAKIP', $riwayat->nama_pengguna);
        $this->assertSame('ubah', $riwayat->aksi);
    }
}
