<?php

namespace Tests\Feature;

use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\MasterIku;
use App\Models\Periode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\PenjagaDatabaseUji;
use Tests\TestCase;

/**
 * Migrasi baru Bagian Kustom (konteks poin & log aktivitas) bisa turun-naik tanpa
 * menghilangkan atau mengubah data lama. Hanya di SQLite uji — tidak pernah produksi.
 */
class MigrasiKompatibilitasBagianKustomTest extends TestCase
{
    use PenjagaDatabaseUji;
    use RefreshDatabase;

    protected const MIGRASI_KONTEKS = 'database/migrations/2026_10_21_000002_tambah_konteks_pengisi_ke_bagian_kustom_poin.php';

    protected const MIGRASI_LOG = 'database/migrations/2026_10_22_100001_buat_tabel_log_aktivitas.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pastikanDatabaseUji();
    }

    public function test_migrasi_konteks_turun_naik_tidak_mengubah_poin_lama(): void
    {
        $iku = MasterIku::create(['kode' => 'MIG-1', 'indikator' => 'IKU', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
        $bagian = BagianKustom::create(['nama' => 'MR', 'aktif' => true, 'peran' => ['ketua_tim'], 'penempatan' => ['pengisian']]);

        Artisan::call('migrate:rollback', ['--path' => self::MIGRASI_KONTEKS, '--force' => true]);
        $this->assertFalse(Schema::hasColumn('bagian_kustom_poin', 'menu'));

        // Poin "lama" — dibuat sebelum kolom konteks ada.
        $id = DB::table('bagian_kustom_poin')->insertGetId([
            'bagian_kustom_id' => $bagian->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'teks' => 'Poin sebelum migrasi', 'nilai_kolom' => json_encode(['skor' => '4']),
            'status_verifikasi' => 'terverifikasi', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sebelum = (array) DB::table('bagian_kustom_poin')->find($id);

        Artisan::call('migrate', ['--path' => self::MIGRASI_KONTEKS, '--force' => true]);

        $sesudah = (array) DB::table('bagian_kustom_poin')->find($id);
        foreach ($sebelum as $kolom => $nilai) {
            $this->assertSame($nilai, $sesudah[$kolom], "Kolom {$kolom} berubah oleh migrasi");
        }

        // Tidak ada backfill: kolom baru tetap NULL, dan poin terbaca sebagai Isian Kegiatan.
        $this->assertNull($sesudah['menu']);
        $this->assertNull($sesudah['peran_pengisi']);
        $this->assertNull($sesudah['diisi_oleh']);
        $this->assertSame([$id], BagianKustomPoin::konteksIsianKegiatan()->pluck('id')->all());
        $this->assertSame(0, BagianKustomPoin::konteks('isian_bagian_kustom', 'tim_sakip')->count());
        $this->assertSame(1, BagianKustomPoin::count(), 'Poin lama tidak boleh muncul ganda');
    }

    public function test_skema_log_aktivitas_kolom_tipe_indeks_dan_tanpa_foreign_key(): void
    {
        $kolom = collect(Schema::getColumns('log_aktivitas'))->keyBy('name');

        foreach (['id', 'created_at', 'user_id', 'nama_pengguna', 'peran', 'aksi', 'status', 'objek_tipe', 'objek_id', 'menu',
            'bagian_kustom_id', 'iku_id', 'periode_id', 'keterangan', 'perubahan', 'korelasi'] as $nama) {
            $this->assertTrue($kolom->has($nama), "Kolom {$nama} tidak ada");
        }

        $this->assertNotContains('updated_at', $kolom->keys()->all());
        $this->assertFalse($kolom['aksi']['nullable']);
        $this->assertFalse($kolom['keterangan']['nullable']);
        $this->assertTrue($kolom['user_id']['nullable']);
        $this->assertTrue($kolom['perubahan']['nullable']);

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->assertSame('int8', $kolom['user_id']['type_name']);
            $this->assertSame('timestamp', $kolom['created_at']['type_name']);
            $this->assertSame('json', $kolom['perubahan']['type_name']);
            $this->assertSame('text', $kolom['keterangan']['type_name']);
            $this->assertStringContainsString('CURRENT_TIMESTAMP', strtoupper((string) $kolom['created_at']['default']));
        }

        $indeks = collect(Schema::getIndexes('log_aktivitas'))->map(fn ($i) => implode(',', $i['columns']))->all();
        foreach (['created_at', 'user_id,created_at', 'bagian_kustom_id,created_at', 'iku_id,periode_id', 'objek_tipe,objek_id', 'korelasi'] as $kolomIndeks) {
            $this->assertContains($kolomIndeks, $indeks, "Indeks ({$kolomIndeks}) tidak ada");
        }

        // Sengaja tanpa FK: jejak audit tidak ikut berubah/terhapus bersama objeknya.
        $this->assertSame([], Schema::getForeignKeys('log_aktivitas'));
    }

    public function test_foreign_key_konteks_poin_mengosongkan_pengisi_tanpa_menyentuh_log(): void
    {
        $fk = collect(Schema::getForeignKeys('bagian_kustom_poin'))->keyBy(fn ($f) => implode(',', $f['columns']));
        foreach (['diisi_oleh', 'diubah_oleh'] as $kolom) {
            $this->assertTrue($fk->has($kolom), "FK {$kolom} tidak ada");
            $this->assertSame('users', $fk[$kolom]['foreign_table']);
            $this->assertSame('set null', strtolower($fk[$kolom]['on_delete']));
        }

        $indeks = collect(Schema::getIndexes('bagian_kustom_poin'))->pluck('name')->all();
        $this->assertContains('bagian_kustom_poin_konteks_index', $indeks);

        $role = \App\Models\Role::firstOrCreate(['nama' => 'Tim SAKIP']);
        $user = \App\Models\User::create([
            'nama' => 'Akan dihapus', 'username' => 'hapus-'.uniqid(), 'email' => 'h-'.uniqid().'@example.test', 'password' => 'password',
            'role_id' => $role->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $iku = MasterIku::create(['kode' => 'MIG-2', 'indikator' => 'IKU', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 5, 'triwulan' => 2, 'bulan_ke' => 2, 'flag_bulan_terlewat' => true]);
        $bagian = BagianKustom::create(['nama' => 'MR2', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom']]);
        $poin = BagianKustomPoin::create([
            'bagian_kustom_id' => $bagian->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id, 'teks' => 'x',
            'menu' => 'isian_bagian_kustom', 'peran_pengisi' => 'tim_sakip', 'diisi_oleh' => $user->id, 'diubah_oleh' => $user->id,
        ]);

        $this->actingAs($user);
        $log = \App\Models\LogAktivitas::catat('uji.aksi', 'Uji', ['objek_tipe' => 'bagian_kustom_poin', 'objek_id' => $poin->id]);

        $user->delete();

        $poin->refresh();
        $this->assertNull($poin->diisi_oleh);
        $this->assertNull($poin->diubah_oleh);
        $this->assertSame('x', $poin->teks);

        $log = DB::table('log_aktivitas')->find($log->id);
        $this->assertSame($user->id, (int) $log->user_id);
        $this->assertSame('Akan dihapus', $log->nama_pengguna);
    }

    public function test_migrasi_log_aktivitas_hanya_menambah_tabel_baru(): void
    {
        $this->assertTrue(Schema::hasTable('log_aktivitas'));
        $tabelLain = collect(Schema::getTableListing())->reject(fn ($t) => str_ends_with($t, 'log_aktivitas'))->sort()->values()->all();

        Artisan::call('migrate:rollback', ['--path' => self::MIGRASI_LOG, '--force' => true]);
        $this->assertFalse(Schema::hasTable('log_aktivitas'));
        $this->assertSame($tabelLain, collect(Schema::getTableListing())->sort()->values()->all());

        Artisan::call('migrate', ['--path' => self::MIGRASI_LOG, '--force' => true]);
        $this->assertTrue(Schema::hasTable('log_aktivitas'));
    }
}
