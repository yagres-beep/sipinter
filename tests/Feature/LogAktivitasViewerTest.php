<?php

namespace Tests\Feature;

use App\Livewire\LogAktivitasViewer;
use App\Models\BagianKustom;
use App\Models\LogAktivitas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\PenjagaDatabaseUji;
use Tests\TestCase;

/**
 * Halaman baca Log Aktivitas: hanya Tim SAKIP, hanya baca, filter & paginasi bekerja,
 * terbaru di atas, rincian sebelum/sesudah tampil tanpa data teknis/rahasia.
 */
class LogAktivitasViewerTest extends TestCase
{
    use PenjagaDatabaseUji;
    use RefreshDatabase;

    protected User $sakip;

    protected User $ketua;

    protected MasterIku $ikuA;

    protected MasterIku $ikuB;

    protected Periode $april;

    protected Periode $mei;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pastikanDatabaseUji();

        foreach (['Ketua Tim', 'Tim SAKIP', 'Kepala'] as $nama) {
            Role::firstOrCreate(['nama' => $nama]);
        }

        $this->sakip = $this->pengguna('Tim SAKIP', 'Sari SAKIP');
        $this->ketua = $this->pengguna('Ketua Tim', 'Budi Ketua');
        $this->ikuA = MasterIku::create(['kode' => 'VW-A', 'indikator' => 'Indikator A', 'tim' => 'T', 'penanggung_jawab' => 'A']);
        $this->ikuB = MasterIku::create(['kode' => 'VW-B', 'indikator' => 'Indikator B', 'tim' => 'T', 'penanggung_jawab' => 'B']);
        $this->april = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->mei = Periode::create(['tahun' => 2026, 'bulan' => 5, 'triwulan' => 2, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
    }

    protected function pengguna(string $peran, string $nama): User
    {
        return User::create([
            'nama' => $nama, 'username' => 'u-'.uniqid(), 'email' => 'u-'.uniqid().'@example.test', 'password' => 'password',
            'role_id' => Role::where('nama', $peran)->value('id'), 'status_verifikasi' => 'terverifikasi',
        ]);
    }

    /** Catatan log sebagai $user pada waktu tertentu (UTC). */
    protected function log(User $user, string $aksi, string $ket, array $konteks = [], string $status = LogAktivitas::STATUS_BERHASIL, ?string $waktu = null): LogAktivitas
    {
        $this->actingAs($user);
        Carbon::setTestNow($waktu ? Carbon::parse($waktu, 'UTC') : null);

        $log = LogAktivitas::catat($aksi, $ket, $konteks, $status);
        Carbon::setTestNow();

        return $log;
    }

    public function test_hanya_tim_sakip_yang_bisa_membuka_halaman_log(): void
    {
        $this->withoutVite();

        $this->actingAs($this->sakip)->get(route('master-iku.index'))->assertOk()->assertSee('Log Aktivitas');
        Livewire::test(LogAktivitasViewer::class)->assertOk();

        foreach (['Ketua Tim', 'Kepala'] as $peran) {
            $user = $this->pengguna($peran, 'Lain '.$peran);
            $this->actingAs($user)->get(route('master-iku.index'))->assertForbidden();
            Livewire::test(LogAktivitasViewer::class)->assertForbidden();
        }
    }

    public function test_peristiwa_terbaru_di_atas_dan_paginasi_25_baris(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->log($this->ketua, 'bagian_kustom_poin.diubah', "Peristiwa {$i}", [], LogAktivitas::STATUS_BERHASIL, sprintf('2026-04-01 %02d:%02d:00', intdiv($i, 60), $i % 60));
        }
        $this->actingAs($this->sakip);

        $layar = Livewire::test(LogAktivitasViewer::class);
        $halaman1 = $layar->viewData('log');
        $this->assertCount(25, $halaman1);
        $this->assertSame('Peristiwa 30', $halaman1->first()->keterangan);
        $this->assertTrue($halaman1->hasMorePages());

        $halaman2 = $layar->call('nextPage')->viewData('log');
        $this->assertCount(5, $halaman2);
        $this->assertSame('Peristiwa 1', $halaman2->last()->keterangan);
    }

    public function test_filter_tanggal_pelaku_aksi_hasil_iku_periode_dan_bagian(): void
    {
        $bagian = BagianKustom::create(['nama' => 'Risiko', 'aktif' => true, 'peran' => ['ketua_tim'], 'penempatan' => ['pengisian'], 'kolom' => []]);
        $this->log($this->ketua, 'bagian_kustom_poin.dibuat', 'K-april', ['iku_id' => $this->ikuA->id, 'periode_id' => $this->april->id, 'bagian_kustom_id' => $bagian->id], waktu: '2026-04-10 02:00:00');
        $this->log($this->ketua, 'bagian_kustom_poin.simpan', 'K-tolak', ['iku_id' => $this->ikuB->id, 'periode_id' => $this->mei->id], LogAktivitas::STATUS_DITOLAK, '2026-05-10 02:00:00');
        $this->log($this->sakip, 'bagian_kustom_poin.ditandai_sesuai', 'S-mei', ['iku_id' => $this->ikuA->id, 'periode_id' => $this->mei->id], waktu: '2026-05-20 02:00:00');
        $this->actingAs($this->sakip);

        $ket = fn ($layar) => $layar->viewData('log')->pluck('keterangan')->all();
        $layar = Livewire::test(LogAktivitasViewer::class);

        $this->assertSame(['S-mei', 'K-tolak', 'K-april'], $ket($layar));
        $this->assertSame(['K-april'], $ket($layar->set('dari', '2026-04-01')->set('sampai', '2026-04-30')));
        $this->assertSame(['S-mei', 'K-tolak', 'K-april'], $ket($layar->call('resetFilter')));
        $this->assertSame(['K-tolak', 'K-april'], $ket($layar->set('pelaku', (string) $this->ketua->id)));
        $this->assertSame(['K-tolak'], $ket($layar->set('status', 'ditolak')));
        $this->assertSame(['S-mei'], $ket($layar->call('resetFilter')->set('aksi', 'bagian_kustom_poin.ditandai_sesuai')));
        $this->assertSame(['S-mei', 'K-april'], $ket($layar->call('resetFilter')->set('ikuId', (string) $this->ikuA->id)));
        $this->assertSame(['S-mei'], $ket($layar->set('tahun', '2026')->set('bulan', '5')));
        $this->assertSame(['K-april'], $ket($layar->call('resetFilter')->set('bagianId', (string) $bagian->id)));

        // Nilai filter tidak sah diabaikan, bukan galat.
        $this->assertCount(3, $ket($layar->call('resetFilter')->set('aksi', "x' OR 1=1")->set('dari', 'bukan-tanggal')->set('pelaku', 'abc')));
    }

    public function test_batas_tanggal_mengikuti_zona_wita(): void
    {
        // 2026-04-30 17:30 UTC = 1 Mei 01:30 WITA.
        $this->log($this->ketua, 'bagian_kustom_poin.dibuat', 'Lewat tengah malam WITA', waktu: '2026-04-30 17:30:00');
        $this->actingAs($this->sakip);

        $layar = Livewire::test(LogAktivitasViewer::class);
        $this->assertCount(0, $layar->set('dari', '2026-04-30')->set('sampai', '2026-04-30')->viewData('log'));
        $this->assertCount(1, $layar->set('dari', '2026-05-01')->set('sampai', '2026-05-01')->viewData('log'));
    }

    public function test_rincian_sebelum_sesudah_tampil_tanpa_data_teknis_atau_rahasia(): void
    {
        $this->log($this->ketua, 'bukti.diganti', 'Ganti bukti', ['perubahan' => [
            'sebelum' => ['nama_file' => 'lama.pdf', 'drive_file_id' => 'RAHASIA-DRIVE', 'path' => 'bukti/lama.pdf'],
            'sesudah' => ['nama_file' => 'baru.pdf', 'token_akses' => 'RAHASIA-TOKEN'],
        ]]);
        $this->log($this->ketua, 'bagian_kustom_poin.diubah', 'Ubah poin', ['perubahan' => [
            'teks' => ['sebelum' => 'Teks lama', 'sesudah' => 'Teks baru'],
            'nilai_kolom' => ['sebelum' => ['skor' => '1'], 'sesudah' => ['skor' => '2']],
        ]]);
        $this->actingAs($this->sakip);

        $html = Livewire::test(LogAktivitasViewer::class)->html();

        $this->assertStringContainsString('lama.pdf', $html);
        $this->assertStringContainsString('baru.pdf', $html);
        $this->assertStringContainsString('Teks lama', $html);
        $this->assertStringContainsString('nilai_kolom › skor', $html);
        $this->assertStringNotContainsString('RAHASIA-DRIVE', $html);
        $this->assertStringNotContainsString('RAHASIA-TOKEN', $html);
        $this->assertStringNotContainsString('bukti/lama.pdf', $html);
        $this->assertStringContainsString('Budi Ketua', $html);
    }

    public function test_halaman_hanya_baca_tidak_mengubah_log(): void
    {
        $log = $this->log($this->ketua, 'bagian_kustom_poin.dibuat', 'Asli');
        $this->actingAs($this->sakip);

        Livewire::test(LogAktivitasViewer::class)->call('resetFilter')->call('filterKorelasi', "'; DELETE FROM log_aktivitas; --")->call('nextPage');

        $this->assertSame('Asli', LogAktivitas::sole()->keterangan);
        $this->assertSame($log->id, LogAktivitas::sole()->id);
    }

    public function test_penolakan_identik_berulang_hanya_dicatat_sekali_per_menit(): void
    {
        $this->actingAs($this->ketua);

        LogAktivitas::catat('akses.ditolak', 'Ditolak', [], LogAktivitas::STATUS_DITOLAK);
        $this->assertNull(LogAktivitas::catat('akses.ditolak', 'Ditolak', [], LogAktivitas::STATUS_DITOLAK));
        $this->assertSame(1, LogAktivitas::count());

        // Penolakan berbeda tetap dicatat; catatan berhasil tidak pernah diredam.
        LogAktivitas::catat('akses.ditolak', 'Ditolak alasan lain', [], LogAktivitas::STATUS_DITOLAK);
        LogAktivitas::catat('bagian_kustom_poin.dibuat', 'Ok');
        LogAktivitas::catat('bagian_kustom_poin.dibuat', 'Ok');
        $this->assertSame(4, LogAktivitas::count());

        $this->travel(61)->seconds();
        LogAktivitas::catat('akses.ditolak', 'Ditolak', [], LogAktivitas::STATUS_DITOLAK);
        $this->assertSame(5, LogAktivitas::count());
    }

    public function test_indeks_untuk_saringan_halaman_log_tersedia(): void
    {
        $indeks = collect(\Illuminate\Support\Facades\Schema::getIndexes('log_aktivitas'))->map(fn ($i) => $i['columns'])->all();

        foreach ([['created_at'], ['user_id', 'created_at'], ['aksi', 'created_at'], ['status', 'created_at'], ['iku_id', 'periode_id'], ['bagian_kustom_id', 'created_at'], ['korelasi']] as $kolom) {
            $this->assertContains($kolom, $indeks, 'indeks '.implode(',', $kolom).' tidak ada');
        }
    }
}
