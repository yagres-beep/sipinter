<?php

namespace Tests\Feature;

use App\Livewire\TargetTahunan;
use App\Livewire\VerifikasiCapaian;
use App\Models\Capaian;
use App\Models\CapaianTahunan;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\RincianN;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sinkronisasi N (Target Tahunan) dengan n (Verifikasi) tanpa kehilangan rincian
 * tambahan n, dan aturan triwulan mulai berlaku rincian tambahan.
 */
class RincianTambahanNTest extends TestCase
{
    use RefreshDatabase;

    private MasterIku $iku;

    protected function setUp(): void
    {
        parent::setUp();

        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'username' => 'sakip-n@example.test', 'email' => 'sakip-n@example.test',
            'password' => 'password', 'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        $this->iku = MasterIku::create([
            'kode' => 'UJI-N', 'indikator' => 'IKU % uji rincian', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'metode_capaian' => MasterIku::METODE_RASIO,
        ]);
    }

    private function capaianTw(int $tw): Capaian
    {
        $periode = Periode::create([
            'tahun' => 2026, 'bulan' => $tw * 3, 'triwulan' => $tw, 'bulan_ke' => 3, 'flag_bulan_terlewat' => false,
        ]);

        return Capaian::create(['iku_id' => $this->iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);
    }

    /** Buat $jumlah rincian target (N) langsung di DB. */
    private function buatTarget(int $jumlah, ?int $twRealisasi = null, int $mulaiDari = 1): array
    {
        $hasil = [];
        for ($i = 0; $i < $jumlah; $i++) {
            $hasil[] = RincianN::create([
                'iku_id' => $this->iku->id, 'tahun' => 2026, 'uraian' => 'Target '.($mulaiDari + $i),
                'triwulan_realisasi' => $twRealisasi,
            ]);
        }

        return $hasil;
    }

    /** Tambah rincian tambahan n lewat halaman Verifikasi lalu simpan. */
    private function tambahLewatVerifikasi(Capaian $capaian, array $uraianTerealisasi): void
    {
        $komponen = Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian->fresh()]);

        foreach ($uraianTerealisasi as $uraian => $terealisasi) {
            $komponen->call('tambahRincianTambahan');
            $kunci = array_key_last($komponen->get('rincianTambahanBaru'));
            $komponen->set("rincianTambahanBaru.{$kunci}.uraian", $uraian)
                ->set("rincianTambahanBaru.{$kunci}.terealisasi", $terealisasi);
        }

        $komponen->call('simpanPerubahan')->assertHasNoErrors();
    }

    private function capaianTahunan(): CapaianTahunan
    {
        return CapaianTahunan::where('iku_id', $this->iku->id)->where('tahun', 2026)->firstOrFail();
    }

    /** n kumulatif TW I s.d. $tw dari kolom tersimpan. */
    private function nKumulatif(int $tw): float
    {
        $ct = $this->capaianTahunan();

        return collect(range(1, $tw))->sum(fn ($i) => (float) $ct->{"x_realisasi_tw{$i}"});
    }

    private function tambahTargetLewatTargetTahunan(int $jumlah): void
    {
        $komponen = Livewire::test(TargetTahunan::class)->set('tahun', 2026);
        $sebelum = array_keys($komponen->get('rincianN')[$this->iku->id] ?? []);

        for ($i = 0; $i < $jumlah; $i++) {
            $komponen->call('tambahN', $this->iku->id);
        }

        $baru = array_diff(array_keys($komponen->get('rincianN')[$this->iku->id]), $sebelum);
        foreach (array_values($baru) as $i => $kunci) {
            $komponen->set("rincianN.{$this->iku->id}.{$kunci}.uraian", 'Target baru '.($i + 1));
        }

        $komponen->call('simpan')->assertHasNoErrors();
    }

    public function test_contoh_wajib_n_berubah_rincian_tambahan_tetap_ada_tanpa_duplikasi(): void
    {
        $this->buatTarget(10, twRealisasi: 1);
        $this->tambahTargetLewatTargetTahunan(0); // simpan awal: N = 10
        $this->assertEquals(10, $this->capaianTahunan()->y_alokasi_tw4);

        $this->tambahLewatVerifikasi($this->capaianTw(3), ['Tambahan A' => true, 'Tambahan B' => true]);

        // N tetap 10, daftar n = 12 rincian.
        $this->assertEquals(10, RincianN::jumlahPenyebut($this->iku->id, 2026));
        $this->assertSame(12, RincianN::milik($this->iku->id, 2026)->count());

        $this->tambahTargetLewatTargetTahunan(5);

        $ct = $this->capaianTahunan();
        $this->assertEquals(15, $ct->y_alokasi_tw4);
        $this->assertEquals(15, $ct->y_realisasi_tw4, 'angka total n mengikuti N');
        $this->assertSame(15, RincianN::milik($this->iku->id, 2026)->where('sumber', 'target')->count());
        $this->assertSame(2, RincianN::milik($this->iku->id, 2026)->where('sumber', 'verifikasi')->count());
        $this->assertSame(17, RincianN::milik($this->iku->id, 2026)->count());

        // Simpan ulang berkali-kali tidak menggandakan apa pun.
        $this->tambahTargetLewatTargetTahunan(0);
        $this->tambahTargetLewatTargetTahunan(0);
        $this->assertSame(17, RincianN::milik($this->iku->id, 2026)->count());
        $this->assertEquals(15, $this->capaianTahunan()->y_alokasi_tw4);
    }

    public function test_target_tahunan_tidak_memuat_maupun_mengubah_rincian_tambahan(): void
    {
        $this->buatTarget(2);
        $tambahan = RincianN::create([
            'iku_id' => $this->iku->id, 'tahun' => 2026, 'uraian' => 'Tambahan',
            'sumber' => RincianN::SUMBER_VERIFIKASI, 'triwulan_mulai_berlaku' => 3, 'triwulan_realisasi' => 3,
        ]);

        $komponen = Livewire::test(TargetTahunan::class)->set('tahun', 2026);
        $this->assertArrayNotHasKey((string) $tambahan->id, $komponen->get('rincianN')[$this->iku->id]);

        // Payload dimanipulasi: id rincian tambahan dikirim sebagai hapus/ubah.
        $komponen->set("rincianNDihapus.{$this->iku->id}", [$tambahan->id])->call('simpan')->assertHasNoErrors();

        $this->assertDatabaseHas('rincian_n', ['id' => $tambahan->id, 'uraian' => 'Tambahan', 'triwulan_mulai_berlaku' => 3]);
        $this->assertEquals(2, $this->capaianTahunan()->y_alokasi_tw4);
    }

    public function test_hapus_rincian_n_terealisasi_dilepas_bukan_dihapus_dan_baru_diproses_saat_simpan(): void
    {
        [$terealisasi, $belum] = [
            RincianN::create(['iku_id' => $this->iku->id, 'tahun' => 2026, 'uraian' => 'Sudah', 'triwulan_realisasi' => 1]),
            RincianN::create(['iku_id' => $this->iku->id, 'tahun' => 2026, 'uraian' => 'Belum']),
        ];
        $this->buatTarget(1);

        $komponen = Livewire::test(TargetTahunan::class)->set('tahun', 2026)
            ->call('hapusN', $this->iku->id, (string) $terealisasi->id)
            ->call('hapusN', $this->iku->id, (string) $belum->id);

        // Belum Simpan: belum ada yang berubah di DB.
        $this->assertNull($terealisasi->fresh()->dilepas_dari_target_at);
        $this->assertNotNull($belum->fresh());

        $komponen->call('simpan')->assertHasNoErrors();

        $this->assertNotNull($terealisasi->fresh()->dilepas_dari_target_at, 'realisasi tetap tercatat');
        $this->assertSame(1, $terealisasi->fresh()->triwulan_realisasi);
        $this->assertNull($belum->fresh(), 'yang belum terealisasi boleh dihapus');

        // N = 1 (hanya target tersisa), realisasi TW I tetap dihitung di n.
        $this->assertEquals(1, $this->capaianTahunan()->y_alokasi_tw4);
        $this->assertSame([1 => 1, 2 => 0, 3 => 0, 4 => 0], RincianN::jumlahRealisasiPerTw($this->iku->id, 2026));
    }

    public function test_contoh_wajib_rincian_tambahan_tw_tiga_dihitung_mulai_tw_tiga(): void
    {
        $this->buatTarget(2, twRealisasi: 1);
        $this->buatTarget(8, mulaiDari: 3);
        $this->tambahTargetLewatTargetTahunan(0); // N = 10

        $this->tambahLewatVerifikasi($this->capaianTw(3), ['Baru 1' => true, 'Baru 2' => true]);

        foreach (RincianN::where('sumber', RincianN::SUMBER_VERIFIKASI)->get() as $n) {
            $this->assertSame(3, $n->triwulan_mulai_berlaku);
            $this->assertSame(3, $n->triwulan_realisasi);
        }

        $this->assertEquals(2, $this->nKumulatif(1));
        $this->assertEquals(2, $this->nKumulatif(2));
        $this->assertEquals(4, $this->nKumulatif(3));
        $this->assertEquals(4, $this->nKumulatif(4));

        // Penyebut tetap N = 10: 4/10 = 40%.
        $ct = $this->capaianTahunan();
        $this->assertEquals(10, $ct->y_realisasi_tw3);
        $this->assertEqualsWithDelta(40.0, $ct->realisasiKumulatif(3), 0.01);
        $this->assertEqualsWithDelta(20.0, $ct->realisasiKumulatif(2), 0.01);
    }

    public function test_rincian_tambahan_tw_tiga_baru_terealisasi_tw_empat_hanya_dihitung_mulai_tw_empat(): void
    {
        $this->buatTarget(10);
        $this->tambahTargetLewatTargetTahunan(0);

        $this->tambahLewatVerifikasi($this->capaianTw(3), ['Belum terealisasi' => false]);
        $tambahan = RincianN::where('sumber', RincianN::SUMBER_VERIFIKASI)->sole();
        $this->assertNull($tambahan->triwulan_realisasi, 'ditambahkan tidak sama dengan terealisasi');
        $this->assertEquals(0, $this->nKumulatif(3));

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(4)])
            ->call('toggleRincianN', $tambahan->id, 4)
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $this->assertSame(4, $tambahan->fresh()->triwulan_realisasi);
        $this->assertSame(3, $tambahan->fresh()->triwulan_mulai_berlaku);
        $this->assertEquals(0, $this->nKumulatif(3));
        $this->assertEquals(1, $this->nKumulatif(4));
    }

    public function test_rincian_tambahan_tidak_bisa_disusulkan_sebelum_triwulan_mulai_berlaku(): void
    {
        $this->buatTarget(3);
        $tambahan = RincianN::create([
            'iku_id' => $this->iku->id, 'tahun' => 2026, 'uraian' => 'Tambahan TW III',
            'sumber' => RincianN::SUMBER_VERIFIKASI, 'triwulan_mulai_berlaku' => 3,
        ]);

        // Dari sesi TW IV: klik kolom TW I/II diabaikan, rincianNTw yang dimanipulasi
        // ke TW II dibulatkan naik ke TW III.
        $komponen = Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(4)])
            ->call('toggleRincianN', $tambahan->id, 1)
            ->call('toggleRincianN', $tambahan->id, 2);
        $this->assertFalse((bool) ($komponen->get('rincianNPilih')[$tambahan->id] ?? false));

        $komponen->set("rincianNPilih.{$tambahan->id}", true)
            ->set("rincianNTw.{$tambahan->id}", 2)
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $this->assertSame(3, $tambahan->fresh()->triwulan_realisasi);
    }

    public function test_sesi_triwulan_sebelumnya_tidak_melihat_maupun_menghapus_hitungan_rincian_tambahan(): void
    {
        $this->buatTarget(4);
        $this->tambahTargetLewatTargetTahunan(0);
        $this->tambahLewatVerifikasi($this->capaianTw(3), ['Tambahan' => true]);
        $tambahan = RincianN::where('sumber', RincianN::SUMBER_VERIFIKASI)->sole();

        $komponen = Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(2)]);
        $this->assertFalse($komponen->instance()->rincianNBisaDipilih()->contains('id', $tambahan->id));
        $komponen->assertDontSee('Tambahan (tambahan)');

        $komponen->call('simpanPerubahan')->assertHasNoErrors();

        $this->assertEquals(1, $this->capaianTahunan()->x_realisasi_tw3, 'hitungan TW III tidak tertimpa sesi TW II');
    }

    public function test_koreksi_dan_simpan_ulang_tidak_memindah_triwulan_mulai_berlaku(): void
    {
        $this->buatTarget(2);
        $capaian = $this->capaianTw(3);
        $this->tambahLewatVerifikasi($capaian, ['Tambahan' => true]);
        $tambahan = RincianN::where('sumber', RincianN::SUMBER_VERIFIKASI)->sole();

        // Koreksi: lepas centang lewat mode edit, lalu simpan sementara & simpan lagi.
        Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian->fresh()])
            ->call('mulaiEditRincianN')
            ->call('toggleRincianN', $tambahan->id, 3)
            ->call('simpanSementara')
            ->assertHasNoErrors();

        $this->assertNull($tambahan->fresh()->triwulan_realisasi);
        $this->assertSame(3, $tambahan->fresh()->triwulan_mulai_berlaku);

        // Sesi TW IV kemudian: triwulan mulai berlaku tetap TW III.
        Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(4)])
            ->call('simpanPerubahan')
            ->assertHasNoErrors();
        $this->assertSame(3, $tambahan->fresh()->triwulan_mulai_berlaku);
        $this->assertDatabaseHas('rincian_n', ['id' => $tambahan->id]);
    }

    public function test_rincian_tambahan_belum_terealisasi_bisa_dihapus_yang_terealisasi_tidak(): void
    {
        $this->buatTarget(1);
        $capaian = $this->capaianTw(3);
        $this->tambahLewatVerifikasi($capaian, ['Belum' => false, 'Sudah' => true]);
        $belum = RincianN::where('uraian', 'Belum')->sole();
        $sudah = RincianN::where('uraian', 'Sudah')->sole();
        $target = RincianN::where('sumber', RincianN::SUMBER_TARGET)->sole();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian->fresh()])
            ->call('hapusRincianTambahan', $belum->id)
            ->call('hapusRincianTambahan', $sudah->id)
            ->call('hapusRincianTambahan', $target->id);

        $this->assertNull($belum->fresh());
        $this->assertNotNull($sudah->fresh());
        $this->assertNotNull($target->fresh());
    }

    public function test_data_lama_tetap_dihitung_sebagai_target_mulai_tw_satu(): void
    {
        $lama = RincianN::create(['iku_id' => $this->iku->id, 'tahun' => 2026, 'uraian' => 'Lama', 'triwulan_realisasi' => 2]);

        $this->assertSame(RincianN::SUMBER_TARGET, $lama->fresh()->sumber);
        $this->assertSame(1, $lama->fresh()->triwulan_mulai_berlaku);
        $this->assertSame(1, RincianN::jumlahPenyebut($this->iku->id, 2026));
        $this->assertSame([1 => 0, 2 => 1, 3 => 0, 4 => 0], RincianN::jumlahRealisasiPerTw($this->iku->id, 2026));
    }
}
