<?php

namespace Tests\Feature;

use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\LogAktivitas;
use App\Models\MasterIku;
use App\Models\Periode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\PenjagaDatabaseUji;
use Tests\TestCase;

/**
 * Migrasi rilis ini (Rincian N, konteks poin, riwayat konfigurasi, log aktivitas, dan
 * perlindungan log) bisa turun-naik di SQLite maupun PostgreSQL uji tanpa mengubah
 * data lama. Tidak pernah dijalankan ke produksi.
 */
class MigrasiFinalisasiTest extends TestCase
{
    use PenjagaDatabaseUji;
    use RefreshDatabase;

    /** Urutan rilis (naik). Turun dijalankan terbalik. */
    protected const MIGRASI_RILIS = [
        'database/migrations/2026_10_21_000001_tambah_sumber_dan_triwulan_mulai_ke_rincian_n.php',
        'database/migrations/2026_10_21_000002_tambah_konteks_pengisi_ke_bagian_kustom_poin.php',
        'database/migrations/2026_10_22_000001_create_riwayat_konfigurasi_bagian_kustom_table.php',
        'database/migrations/2026_10_22_100001_buat_tabel_log_aktivitas.php',
        'database/migrations/2026_10_22_100002_lindungi_log_aktivitas_dari_ubah_hapus.php',
    ];

    protected const MIGRASI_PELINDUNG = 'database/migrations/2026_10_22_100002_lindungi_log_aktivitas_dari_ubah_hapus.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pastikanDatabaseUji();
    }

    protected function cobaUbahLog(int $id): ?string
    {
        try {
            DB::transaction(fn () => DB::table('log_aktivitas')->where('id', $id)->update(['keterangan' => 'diubah']));

            return null;
        } catch (QueryException $e) {
            return $e->getMessage();
        }
    }

    public function test_trigger_menolak_ubah_hapus_dan_truncate_log(): void
    {
        $log = LogAktivitas::catat('uji.aksi', 'Asli');

        $this->assertStringContainsString('log_aktivitas hanya boleh ditambah', (string) $this->cobaUbahLog($log->id));

        foreach ([
            fn () => DB::table('log_aktivitas')->delete(),
            fn () => DB::table('log_aktivitas')->truncate(),
            fn () => DB::statement('DELETE FROM log_aktivitas WHERE id = ?', [$log->id]),
        ] as $percobaan) {
            try {
                DB::transaction($percobaan);
                $this->fail('Penghapusan log seharusnya ditolak.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('log_aktivitas hanya boleh ditambah', $e->getMessage());
            }
        }

        // Menambah tetap bisa.
        LogAktivitas::catat('uji.aksi', 'Kedua');
        $this->assertSame(['Asli', 'Kedua'], LogAktivitas::orderBy('id')->pluck('keterangan')->all());
    }

    public function test_migrasi_pelindung_turun_naik_tidak_mengubah_log_yang_ada(): void
    {
        $log = LogAktivitas::catat('uji.aksi', 'Catatan lama');
        $sebelum = (array) DB::table('log_aktivitas')->find($log->id);

        Artisan::call('migrate:rollback', ['--path' => self::MIGRASI_PELINDUNG, '--force' => true]);
        $this->assertSame($sebelum, (array) DB::table('log_aktivitas')->find($log->id));

        Artisan::call('migrate', ['--path' => self::MIGRASI_PELINDUNG, '--force' => true]);
        $this->assertSame($sebelum, (array) DB::table('log_aktivitas')->find($log->id));
        $this->assertNotNull($this->cobaUbahLog($log->id), 'trigger aktif lagi setelah naik ulang');
    }

    public function test_seluruh_migrasi_rilis_turun_lalu_naik_tanpa_mengubah_data_lama(): void
    {
        $iku = MasterIku::create(['kode' => 'MIG-F', 'indikator' => 'IKU', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
        $bagian = BagianKustom::create(['nama' => 'MR', 'aktif' => true, 'peran' => ['ketua_tim'], 'penempatan' => ['pengisian'], 'kolom' => [['kunci' => 'skor', 'label' => 'Skor', 'tipe' => 'angka']]]);
        $poin = BagianKustomPoin::create(['bagian_kustom_id' => $bagian->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id, 'teks' => 'Poin lama', 'nilai_kolom' => ['skor' => '12.5'], 'status_verifikasi' => 'terverifikasi']);

        $tabelLama = ['master_iku', 'periode', 'bagian_kustom'];
        $isiLama = collect($tabelLama)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()]);
        $poinLama = DB::table('bagian_kustom_poin')->where('id', $poin->id)->first(['id', 'bagian_kustom_id', 'iku_id', 'periode_id', 'teks', 'nilai_kolom', 'status_verifikasi']);

        foreach (array_reverse(self::MIGRASI_RILIS) as $migrasi) {
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => $migrasi, '--force' => true]), "Gagal turun: {$migrasi}");
        }

        $this->assertFalse(Schema::hasTable('log_aktivitas'));
        $this->assertFalse(Schema::hasTable('riwayat_konfigurasi_bagian_kustom'));
        $this->assertFalse(Schema::hasColumn('bagian_kustom_poin', 'menu'));

        foreach (self::MIGRASI_RILIS as $migrasi) {
            $this->assertSame(0, Artisan::call('migrate', ['--path' => $migrasi, '--force' => true]), "Gagal naik: {$migrasi}");
        }

        foreach ($tabelLama as $tabel) {
            $this->assertEquals($isiLama[$tabel], DB::table($tabel)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), "Isi {$tabel} berubah");
        }

        $this->assertEquals($poinLama, DB::table('bagian_kustom_poin')->where('id', $poin->id)->first(['id', 'bagian_kustom_id', 'iku_id', 'periode_id', 'teks', 'nilai_kolom', 'status_verifikasi']));
        $this->assertSame(1, BagianKustomPoin::count());
        $this->assertTrue(Schema::hasColumn('riwayat_konfigurasi_bagian_kustom', 'nama_pengguna'));
        $this->assertNotNull($this->cobaUbahLog(LogAktivitas::catat('uji.aksi', 'Setelah naik ulang')->id));
    }
}
