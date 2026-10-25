<?php

namespace Tests\Feature;

use App\Livewire\TargetTahunan;
use App\Livewire\VerifikasiCapaian;
use App\Models\Capaian;
use App\Models\CapaianTahunan;
use App\Models\LogAktivitas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\RincianN;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pemisahan angka N (Target Tahunan) dan rincian n (Verifikasi): N diisi eksplisit,
 * nilai dasar n mengikuti N, rincian tambahan n tidak mengubah N, dan pembilang
 * kumulatif hanya menghitung rincian unik yang terealisasi sesuai triwulannya.
 * Skenario 1-9 mengikuti daftar uji wajib pada permintaan perubahan.
 */
class RincianTambahanNTest extends TestCase
{
    use RefreshDatabase;

    private MasterIku $iku;

    /** @var array<int, Capaian> */
    private array $capaianPerTw = [];

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
        if (! isset($this->capaianPerTw[$tw])) {
            $periode = Periode::create([
                'tahun' => 2026, 'bulan' => $tw * 3, 'triwulan' => $tw, 'bulan_ke' => 3, 'flag_bulan_terlewat' => false,
            ]);
            $this->capaianPerTw[$tw] = Capaian::create(['iku_id' => $this->iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);
        }

        return $this->capaianPerTw[$tw]->fresh();
    }

    /** Buat $jumlah rincian target langsung di DB (data awal uji). */
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

    /**
     * Simpan Target Tahunan lewat komponen: opsional ubah angka N dan tambah
     * $jumlahBaru rincian target.
     */
    private function simpanTarget(?float $n = null, int $jumlahBaru = 0): void
    {
        $komponen = Livewire::test(TargetTahunan::class)->set('tahun', 2026);

        if ($n !== null) {
            $komponen->set("angkaN.{$this->iku->id}", $n);
        }

        $sebelum = array_keys($komponen->get('rincianN')[$this->iku->id] ?? []);
        for ($i = 0; $i < $jumlahBaru; $i++) {
            $komponen->call('tambahN', $this->iku->id);
        }
        $baru = array_diff(array_keys($komponen->get('rincianN')[$this->iku->id] ?? []), $sebelum);
        foreach (array_values($baru) as $i => $kunci) {
            $komponen->set("rincianN.{$this->iku->id}.{$kunci}.uraian", 'Target baru '.($i + 1));
        }

        $komponen->call('simpan')->assertHasNoErrors();
    }

    /** Tambah rincian tambahan n lewat halaman Verifikasi sesi TW $tw lalu simpan. */
    private function tambahLewatVerifikasi(int $tw, array $uraianTerealisasi): void
    {
        $komponen = Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw($tw)]);

        foreach ($uraianTerealisasi as $uraian => $terealisasi) {
            $komponen->call('tambahRincianTambahan');
            $kunci = array_key_last($komponen->get('rincianTambahanBaru'));
            $komponen->set("rincianTambahanBaru.{$kunci}.uraian", $uraian)
                ->set("rincianTambahanBaru.{$kunci}.terealisasi", $terealisasi);
        }

        $komponen->call('simpanPerubahan')->assertHasNoErrors();
    }

    /** Centang rincian $ids terealisasi pada TW $tw lewat sesi Verifikasi TW $tw. */
    private function realisasikan(int $tw, array $ids): void
    {
        $komponen = Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw($tw)]);
        foreach ($ids as $id) {
            $komponen->call('toggleRincianN', $id, $tw);
        }
        $komponen->call('simpanPerubahan')->assertHasNoErrors();
    }

    private function ct(): CapaianTahunan
    {
        return CapaianTahunan::where('iku_id', $this->iku->id)->where('tahun', 2026)->firstOrFail();
    }

    /** n kumulatif TW I s.d. $tw dari kolom tersimpan. */
    private function nKumulatif(int $tw): float
    {
        $ct = $this->ct();

        return collect(range(1, $tw))->sum(fn ($i) => (float) $ct->{"x_realisasi_tw{$i}"});
    }

    private function jumlah(?string $sumber = null): int
    {
        return RincianN::milik($this->iku->id, 2026)->when($sumber, fn ($q) => $q->where('sumber', $sumber))->count();
    }

    // ---------------------------------------------------------------- Skenario 1

    public function test_skenario_1_inisialisasi_n_dasar_dari_angka_n(): void
    {
        $this->buatTarget(10);
        $this->simpanTarget(n: 10);

        $ct = $this->ct();
        foreach ([1, 2, 3, 4] as $tw) {
            $this->assertEquals(10, $ct->{"y_alokasi_tw{$tw}"}, "N TW {$tw}");
            $this->assertEquals(10, $ct->{"y_realisasi_tw{$tw}"}, "nilai dasar n TW {$tw}");
            $this->assertNull($ct->{"x_realisasi_tw{$tw}"}, 'nilai dasar n bukan realisasi');
        }
        $this->assertSame(10.0, $ct->angkaN());

        $ringkasan = RincianN::ringkasan($this->iku->id, 2026, 4);
        $this->assertSame(['terdaftar' => 10, 'target' => 10, 'tambahan' => 0, 'dilepas' => 0, 'terealisasi' => 0], $ringkasan);
        $this->assertEquals(0.0, $ct->realisasiKumulatif(4));
    }

    // ---------------------------------------------------------------- Skenario 2

    public function test_skenario_2_tambahan_verifikasi_tidak_mengubah_n_dan_belum_terhitung(): void
    {
        $this->buatTarget(10);
        $this->simpanTarget(n: 10);

        $this->tambahLewatVerifikasi(2, ['Tambahan A' => false, 'Tambahan B' => false]);

        $this->assertSame(10.0, $this->ct()->angkaN());
        $this->assertEquals(10, $this->ct()->y_realisasi_tw4);
        $this->assertSame(2, $this->jumlah(RincianN::SUMBER_VERIFIKASI));
        $this->assertSame(0, RincianN::milik($this->iku->id, 2026)->whereNotNull('triwulan_realisasi')->count());
        $this->assertEquals(0, $this->nKumulatif(4));
    }

    // ---------------------------------------------------------------- Skenario 3

    public function test_skenario_3_n_berubah_nilai_dasar_ikut_pembilang_tidak(): void
    {
        $target = $this->buatTarget(10);
        $this->simpanTarget(n: 10);
        $this->realisasikan(1, [$target[0]->id, $target[1]->id]);
        $this->tambahLewatVerifikasi(2, ['Tambahan' => false]);

        $this->simpanTarget(n: 15);

        $ct = $this->ct();
        foreach ([1, 2, 3, 4] as $tw) {
            $this->assertEquals(15, $ct->{"y_alokasi_tw{$tw}"});
            $this->assertEquals(15, $ct->{"y_realisasi_tw{$tw}"});
        }
        $this->assertEquals(2, $this->nKumulatif(4), 'pembilang tetap 2, tidak menjadi 15');
        $this->assertEqualsWithDelta(13.33, $ct->realisasiKumulatif(4), 0.01);
        $this->assertSame(1, $this->jumlah(RincianN::SUMBER_VERIFIKASI), 'rincian tambahan tetap ada');
        $this->assertSame(10, $this->jumlah(RincianN::SUMBER_TARGET), 'rincian target tidak bertambah');

        $log = LogAktivitas::where('aksi', 'target_tahunan.n_diubah')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals(['N' => ['sebelum' => 10, 'sesudah' => 15]], $log->perubahan);
    }

    // ---------------------------------------------------------------- Skenario 4

    public function test_skenario_4_sinkron_rincian_target_tanpa_duplikasi(): void
    {
        $this->buatTarget(3);
        $this->simpanTarget(n: 3);

        // Tambah satu rincian target: satu baris baru, berlaku juga sebagai rincian n.
        $this->simpanTarget(jumlahBaru: 1);
        $this->assertSame(4, $this->jumlah(RincianN::SUMBER_TARGET));
        $baru = RincianN::where('uraian', 'Target baru 1')->sole();
        $this->assertTrue(
            Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(1)])
                ->instance()->rincianNBisaDipilih()->contains('id', $baru->id),
            'rincian target baru langsung muncul di daftar n'
        );
        $this->assertSame(3.0, $this->ct()->angkaN(), 'menambah rincian tidak mengubah N');

        // Ubah uraian: baris yang sama yang diperbarui (identitas tetap).
        $komponen = Livewire::test(TargetTahunan::class)->set('tahun', 2026)
            ->set("rincianN.{$this->iku->id}.{$baru->id}.uraian", 'Target diganti')
            ->call('simpan')->assertHasNoErrors();
        $this->assertSame('Target diganti', $baru->fresh()->uraian);
        $this->assertSame(4, $this->jumlah());

        // Hapus rincian target yang belum terealisasi: baris benar-benar dihapus.
        $komponen->call('hapusN', $this->iku->id, (string) $baru->id)->call('simpan')->assertHasNoErrors();
        $this->assertNull($baru->fresh());
        $this->assertSame(3, $this->jumlah());
        $this->assertSame(3.0, $this->ct()->angkaN());
        $this->assertTrue(LogAktivitas::where('aksi', 'rincian_n.dihapus')->where('objek_id', $baru->id)->exists());
    }

    // ---------------------------------------------------------------- Skenario 5

    public function test_skenario_5_target_terealisasi_dilepas_riwayat_tetap_tidak_ganda(): void
    {
        [$satu] = $this->buatTarget(2);
        $this->simpanTarget(n: 2);
        $this->realisasikan(1, [$satu->id]);

        Livewire::test(TargetTahunan::class)->set('tahun', 2026)
            ->call('hapusN', $this->iku->id, (string) $satu->id)
            ->call('simpan')->assertHasNoErrors();

        $satu = $satu->fresh();
        $this->assertNotNull($satu, 'rincian terealisasi tidak dihapus');
        $this->assertTrue($satu->isDilepas());
        $this->assertSame(1, $satu->triwulan_realisasi);
        $this->assertSame(1, RincianN::jumlahRincianTarget($this->iku->id, 2026), 'tidak lagi di daftar target');
        $this->assertSame([1 => 1, 2 => 0, 3 => 0, 4 => 0], RincianN::jumlahRealisasiPerTw($this->iku->id, 2026));

        // Simpan Verifikasi TW II: rincian yang dilepas tetap dihitung SEKALI.
        Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(2)])->call('simpanPerubahan')->assertHasNoErrors();
        $this->assertEquals(1, $this->nKumulatif(4));
        $this->assertSame(1, RincianN::ringkasan($this->iku->id, 2026, 4)['dilepas']);
        $this->assertTrue(LogAktivitas::where('aksi', 'rincian_n.dilepas')->where('objek_id', $satu->id)->exists());
    }

    // ---------------------------------------------------------------- Skenario 6

    public function test_skenario_6_tambahan_tw_tiga_hasil_kumulatif_2_2_4_4(): void
    {
        $target = $this->buatTarget(10);
        $this->simpanTarget(n: 10);
        $this->realisasikan(1, [$target[0]->id, $target[1]->id]);

        $this->tambahLewatVerifikasi(3, ['Baru 1' => true, 'Baru 2' => true]);

        foreach (RincianN::where('sumber', RincianN::SUMBER_VERIFIKASI)->get() as $n) {
            $this->assertSame(3, $n->triwulan_mulai_berlaku);
            $this->assertSame(3, $n->triwulan_realisasi);
        }

        $this->assertEquals([2, 2, 4, 4], array_map(fn ($tw) => $this->nKumulatif($tw), [1, 2, 3, 4]));

        $ct = $this->ct();
        $this->assertEquals([20.0, 20.0, 40.0, 40.0], array_map(fn ($tw) => $ct->realisasiKumulatif($tw), [1, 2, 3, 4]));
        $this->assertSame(10.0, $ct->angkaN());
    }

    // ---------------------------------------------------------------- Skenario 7

    public function test_skenario_7_tambahan_tw_tiga_baru_terealisasi_tw_empat(): void
    {
        $this->buatTarget(10);
        $this->simpanTarget(n: 10);

        $this->tambahLewatVerifikasi(3, ['Belum terealisasi' => false]);
        $tambahan = RincianN::where('sumber', RincianN::SUMBER_VERIFIKASI)->sole();
        $this->assertNull($tambahan->triwulan_realisasi, 'ditambahkan tidak sama dengan terealisasi');

        $this->realisasikan(4, [$tambahan->id]);

        $this->assertSame(4, $tambahan->fresh()->triwulan_realisasi);
        $this->assertSame(3, $tambahan->fresh()->triwulan_mulai_berlaku);
        $this->assertEquals([0, 0, 0, 1], array_map(fn ($tw) => $this->nKumulatif($tw), [1, 2, 3, 4]));
    }

    // ---------------------------------------------------------------- Skenario 8

    public function test_skenario_8_tambahan_tw_dua_tetap_saat_n_berubah(): void
    {
        $this->buatTarget(4);
        $this->simpanTarget(n: 4);
        $this->tambahLewatVerifikasi(2, ['Tambahan TW II' => true]);
        $tambahan = RincianN::where('sumber', RincianN::SUMBER_VERIFIKASI)->sole();
        $this->assertSame(2, $tambahan->triwulan_mulai_berlaku);

        $this->simpanTarget(n: 6);
        $this->simpanTarget(n: 3);

        $tambahan = $tambahan->fresh();
        $this->assertNotNull($tambahan);
        $this->assertSame(2, $tambahan->triwulan_mulai_berlaku);
        $this->assertSame(2, $tambahan->triwulan_realisasi);
        $this->assertEquals([0, 1, 1, 1], array_map(fn ($tw) => $this->nKumulatif($tw), [1, 2, 3, 4]));
        $this->assertSame(3.0, $this->ct()->angkaN());
    }

    // ---------------------------------------------------------------- Skenario 9

    public function test_skenario_9_simpan_berulang_tidak_mengubah_apa_pun(): void
    {
        $target = $this->buatTarget(5);
        $this->simpanTarget(n: 5);
        $this->realisasikan(1, [$target[0]->id]);
        $this->tambahLewatVerifikasi(3, ['Tambahan' => true]);

        $potret = fn () => [
            RincianN::orderBy('id')->get(['id', 'uraian', 'sumber', 'triwulan_mulai_berlaku', 'triwulan_realisasi', 'dilepas_dari_target_at'])->toArray(),
            collect($this->ct()->only(['x_realisasi_tw1', 'x_realisasi_tw2', 'x_realisasi_tw3', 'x_realisasi_tw4', 'y_alokasi_tw4', 'y_realisasi_tw4']))->toArray(),
        ];
        $sebelum = $potret();
        $logSebelum = LogAktivitas::count();

        $this->simpanTarget();
        $this->simpanTarget();
        Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(3)])->call('simpanPerubahan')->assertHasNoErrors();
        Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(3)])->call('simpanPerubahan')->assertHasNoErrors();
        Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(1)])->call('simpanPerubahan')->assertHasNoErrors();

        $this->assertSame($sebelum, $potret());
        $this->assertSame($logSebelum, LogAktivitas::count(), 'simpan tanpa perubahan tidak menulis jejak');
    }

    // ---------------------------------------------------------------- Tambahan

    public function test_sesi_verifikasi_yang_sudah_terbuka_tidak_menimpa_n_baru(): void
    {
        $this->buatTarget(10);
        $this->simpanTarget(n: 10);

        // Halaman Verifikasi dibuka saat N masih 10 ...
        $verifikasi = Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(2)]);

        // ... lalu N diubah di Target Tahunan, baru kemudian Verifikasi disimpan.
        $this->simpanTarget(n: 15);
        $verifikasi->call('simpanPerubahan')->assertHasNoErrors();

        $this->assertEquals(15, $this->ct()->y_alokasi_tw2);
        $this->assertEquals(15, $this->ct()->y_realisasi_tw2);
    }

    public function test_verifikasi_menampilkan_angka_n_bukan_isian_n(): void
    {
        $this->buatTarget(3);
        $this->simpanTarget(n: 8);

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(1)])
            ->assertSee('N = ')
            ->assertSeeHtml('<strong>8</strong>')
            ->assertSee('Rincian n terdaftar: 3')
            ->assertDontSeeHtml('wire:model.live.debounce.400ms="angkaN');
    }

    public function test_target_tahunan_tidak_memuat_maupun_mengubah_rincian_tambahan(): void
    {
        $this->buatTarget(2);
        $tambahan = RincianN::create([
            'iku_id' => $this->iku->id, 'tahun' => 2026, 'uraian' => 'Tambahan',
            'sumber' => RincianN::SUMBER_VERIFIKASI, 'triwulan_mulai_berlaku' => 3, 'triwulan_realisasi' => 3,
        ]);

        $komponen = Livewire::test(TargetTahunan::class)->set('tahun', 2026)->set("angkaN.{$this->iku->id}", 2);
        $this->assertArrayNotHasKey((string) $tambahan->id, $komponen->get('rincianN')[$this->iku->id]);

        // Payload dimanipulasi: id rincian tambahan dikirim sebagai hapus.
        $komponen->set("rincianNDihapus.{$this->iku->id}", [$tambahan->id])->call('simpan')->assertHasNoErrors();

        $this->assertDatabaseHas('rincian_n', ['id' => $tambahan->id, 'uraian' => 'Tambahan', 'triwulan_mulai_berlaku' => 3]);
        $this->assertEquals(2, $this->ct()->y_alokasi_tw4);
    }

    public function test_hapus_rincian_target_baru_diproses_saat_simpan(): void
    {
        [$terealisasi, $belum] = $this->buatTarget(2);
        $terealisasi->update(['triwulan_realisasi' => 1]);

        $komponen = Livewire::test(TargetTahunan::class)->set('tahun', 2026)
            ->call('hapusN', $this->iku->id, (string) $terealisasi->id)
            ->call('hapusN', $this->iku->id, (string) $belum->id);

        $this->assertNull($terealisasi->fresh()->dilepas_dari_target_at);
        $this->assertNotNull($belum->fresh());

        $komponen->call('simpan')->assertHasNoErrors();

        $this->assertNotNull($terealisasi->fresh()->dilepas_dari_target_at);
        $this->assertNull($belum->fresh());
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
        $this->simpanTarget(n: 4);
        $this->tambahLewatVerifikasi(3, ['Tambahan' => true]);
        $tambahan = RincianN::where('sumber', RincianN::SUMBER_VERIFIKASI)->sole();

        $komponen = Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(2)]);
        $this->assertFalse($komponen->instance()->rincianNBisaDipilih()->contains('id', $tambahan->id));
        $this->assertFalse($komponen->instance()->rincianTambahanTersimpan()->contains('id', $tambahan->id));
        $komponen->assertDontSee('Tambahan (tambahan)');

        // Payload dimanipulasi: koreksi uraian dari sesi TW II diabaikan.
        $komponen->set("uraianTambahan.{$tambahan->id}", 'Diubah dari TW II')->call('simpanPerubahan')->assertHasNoErrors();

        $this->assertEquals(1, $this->ct()->x_realisasi_tw3, 'hitungan TW III tidak tertimpa sesi TW II');
        $this->assertSame('Tambahan', $tambahan->fresh()->uraian);
    }

    public function test_koreksi_uraian_tambahan_tidak_memindah_periode_maupun_mengubah_n(): void
    {
        $this->buatTarget(2);
        $this->simpanTarget(n: 2);
        $this->tambahLewatVerifikasi(3, ['Tambahan lama' => true]);
        $tambahan = RincianN::where('sumber', RincianN::SUMBER_VERIFIKASI)->sole();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(4)])
            ->set("uraianTambahan.{$tambahan->id}", 'Tambahan dikoreksi')
            ->call('simpanPerubahan')
            ->assertHasNoErrors();

        $tambahan = $tambahan->fresh();
        $this->assertSame('Tambahan dikoreksi', $tambahan->uraian);
        $this->assertSame(3, $tambahan->triwulan_mulai_berlaku);
        $this->assertSame(3, $tambahan->triwulan_realisasi);
        $this->assertSame(2.0, $this->ct()->angkaN());
        $this->assertNotNull($tambahan->diubah_oleh);
        $this->assertTrue(LogAktivitas::where('aksi', 'rincian_n.diubah')->where('objek_id', $tambahan->id)->exists());

        // Uraian kosong ditolak.
        Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(4)])
            ->set("uraianTambahan.{$tambahan->id}", '')
            ->call('simpanPerubahan')
            ->assertHasErrors(["uraianTambahan.{$tambahan->id}"]);
    }

    public function test_koreksi_dan_simpan_ulang_tidak_memindah_triwulan_mulai_berlaku(): void
    {
        $this->buatTarget(2);
        $this->tambahLewatVerifikasi(3, ['Tambahan' => true]);
        $tambahan = RincianN::where('sumber', RincianN::SUMBER_VERIFIKASI)->sole();

        // Koreksi: lepas centang lewat mode edit, lalu simpan sementara.
        Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(3)])
            ->call('mulaiEditRincianN')
            ->call('toggleRincianN', $tambahan->id, 3)
            ->call('simpanSementara')
            ->assertHasNoErrors();

        $this->assertNull($tambahan->fresh()->triwulan_realisasi);
        $this->assertSame(3, $tambahan->fresh()->triwulan_mulai_berlaku);
        $this->assertTrue(LogAktivitas::where('aksi', 'rincian_n.realisasi_diubah')->where('objek_id', $tambahan->id)->exists());

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(4)])
            ->call('simpanPerubahan')
            ->assertHasNoErrors();
        $this->assertSame(3, $tambahan->fresh()->triwulan_mulai_berlaku);
        $this->assertDatabaseHas('rincian_n', ['id' => $tambahan->id]);
    }

    public function test_rincian_tambahan_belum_terealisasi_bisa_dihapus_yang_terealisasi_tidak(): void
    {
        $this->buatTarget(1);
        $this->tambahLewatVerifikasi(3, ['Belum' => false, 'Sudah' => true]);
        $belum = RincianN::where('uraian', 'Belum')->sole();
        $sudah = RincianN::where('uraian', 'Sudah')->sole();
        $target = RincianN::where('sumber', RincianN::SUMBER_TARGET)->sole();

        Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaianTw(3)])
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
        $this->assertSame(1, RincianN::jumlahRincianTarget($this->iku->id, 2026));
        $this->assertSame([1 => 0, 2 => 1, 3 => 0, 4 => 0], RincianN::jumlahRealisasiPerTw($this->iku->id, 2026));
    }

    public function test_n_nol_dan_kosong_tanpa_pembagian_nol(): void
    {
        $target = $this->buatTarget(2);
        $this->simpanTarget(n: 0);
        $this->realisasikan(1, [$target[0]->id]);

        $ct = $this->ct();
        $this->assertSame(0.0, $ct->angkaN());
        $this->assertEquals(0.0, $ct->realisasiKumulatif(1));
        $this->assertNull($ct->capaianSetahun(1), 'target 0 & realisasi 0 = "-"');

        // N dikosongkan: tersimpan null, tetap tidak error.
        Livewire::test(TargetTahunan::class)->set('tahun', 2026)
            ->set("angkaN.{$this->iku->id}", '')
            ->call('simpan')->assertHasNoErrors();
        $this->assertNull($this->ct()->angkaN());
        $this->assertEquals(0.0, $this->ct()->realisasiKumulatif(4));
    }
}
