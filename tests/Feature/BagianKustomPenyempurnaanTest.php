<?php

namespace Tests\Feature;

use App\Livewire\BagianKustomManager;
use App\Livewire\IsianBagianKustom;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Support\KelengkapanBagianKustom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Penyempurnaan Bagian Kustom (30 Okt 2026).
 *
 * Akar masalah yang dilaporkan: "Manajemen Resiko" aktif di Pengelolaan Bagian tetapi
 * halaman Isian Bagian Kustom menampilkan "Belum ada bagian kustom aktif". Bagian itu
 * ditempatkan di Isian Kegiatan (Ketua Tim) dan Verifikasi & Notula (Tim SAKIP) — sah,
 * tetapi halaman Isian Bagian Kustom hanya menampilkan bagian yang ditempatkan di menu
 * itu sendiri dan pesannya tidak membedakan penyebab. Ditambah: bagian tanpa kolom tidak
 * diberi tanda apa pun di pengelolaan.
 */
class BagianKustomPenyempurnaanTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsPeran(string $nama): User
    {
        $peran = Role::firstOrCreate(['nama' => $nama]);
        $user = User::create([
            'nama' => $nama.' Uji', 'username' => 'u-'.uniqid(), 'email' => 'u-'.uniqid().'@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($user);

        return $user;
    }

    /** Persis seperti baris Manajemen Resiko di produksi (cek baca-saja 30 Okt). */
    protected function manajemenResikoProduksi(): BagianKustom
    {
        return BagianKustom::create([
            'kode' => 'manajemen_resiko', 'nama' => 'Manajemen Resiko', 'aktif' => true, 'urutan' => 1,
            'peran' => ['ketua_tim', 'tim_sakip'], 'penempatan' => ['pengisian', 'verifikasi'],
            'frekuensi_wajib' => 'akhir_triwulan', 'bukti_wajib' => true, 'masuk_notula' => true,
            'kolom' => [], 'subbagian' => [],
        ]);
    }

    protected function isian()
    {
        Periode::firstOrCreate(['tahun' => 2026, 'bulan' => 4], ['triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);

        return Livewire::test(IsianBagianKustom::class, ['menu' => 'isian_bagian_kustom'])->set('tahun', 2026)->set('bulan', 4);
    }

    /* ---------- Halaman Isian Bagian Kustom: alasan kosong & tautan menu lain ---------- */

    public function test_bagian_aktif_di_menu_lain_ditunjukkan_dengan_tautan_bukan_pesan_tidak_ada(): void
    {
        $this->manajemenResikoProduksi();
        $this->actingAsPeran('Tim SAKIP');

        $this->isian()
            ->assertDontSee('Belum ada bagian kustom yang berstatus aktif')
            ->assertSee('Ada 1 bagian kustom aktif, tetapi tidak ada yang ditempatkan di menu Isian Bagian Kustom.')
            ->assertSee('Bagian kustom Anda di menu lain')
            ->assertSee('Manajemen Resiko')
            ->assertSee('Isi di Verifikasi &amp; Notula', false)
            ->assertSee(route('verifikasi.index'))
            // Ketua Tim tidak bisa dibuka Tim SAKIP: tidak ada tautan ke Isian Kegiatan.
            ->assertDontSee(route('pengisian.index'));
    }

    public function test_tidak_ada_bagian_aktif_dibedakan(): void
    {
        BagianKustom::create(['nama' => 'Nonaktif', 'aktif' => false, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom']]);
        $this->actingAsPeran('Tim SAKIP');

        $this->isian()->assertSee('Belum ada bagian kustom yang berstatus aktif.')->assertDontSee('Bagian kustom Anda di menu lain');
    }

    public function test_bagian_di_menu_ini_untuk_peran_lain_dibedakan(): void
    {
        BagianKustom::create(['nama' => 'Khusus Kepala', 'aktif' => true, 'peran' => ['kepala'], 'penempatan' => ['isian_bagian_kustom']]);
        $this->actingAsPeran('Tim SAKIP');

        $this->isian()->assertSee('1 bagian di menu ini tidak diberikan kepada peran Tim SAKIP');
    }

    public function test_bagian_di_luar_periode_berlaku_dibedakan(): void
    {
        BagianKustom::create([
            'nama' => 'Hanya TW I', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom'],
            'periode_berlaku' => ['triwulan' => [1]],
        ]);
        $this->actingAsPeran('Tim SAKIP');

        $this->isian()->assertSee('"Hanya TW I" tidak berlaku pada TW II 2026');
    }

    public function test_pembaca_mendapat_tautan_lihat_ke_menu_yang_bisa_dibukanya(): void
    {
        BagianKustom::create([
            'nama' => 'Dibaca Kepala', 'aktif' => true, 'peran' => ['ketua_tim'], 'peran_pembaca' => ['kepala'],
            'penempatan' => ['pengisian', 'notula_riwayat'],
        ]);
        $this->actingAsPeran('Kepala');

        $this->isian()->assertSee('Lihat di Notula (riwayat)')->assertSee(route('notula-riwayat.index'));
    }

    public function test_bagian_tanpa_kolom_tetap_bisa_diisi_dan_diberi_keterangan(): void
    {
        $b = BagianKustom::create(['nama' => 'Tanpa Kolom', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom']]);
        $iku = MasterIku::create(['kode' => 'UJI-1', 'indikator' => 'Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $this->actingAsPeran('Tim SAKIP');

        $this->isian()
            ->assertSeeHtml('data-tanpa-kolom="'.$b->id.'"')
            ->set("baris.{$b->id}.0.iku_id", (string) $iku->id)
            ->set("baris.{$b->id}.0.teks", 'Uraian tanpa kolom')
            ->call('simpan', $b->id)
            ->assertHasNoErrors();

        $this->assertSame(1, BagianKustomPoin::where('bagian_kustom_id', $b->id)->count());
    }

    public function test_menu_lain_tidak_mengubah_menu_yang_disisipi(): void
    {
        // Di menu Verifikasi (disisipkan di halaman lain) tidak ada kartu "menu lain".
        $this->manajemenResikoProduksi();
        BagianKustom::create(['nama' => 'Di Isian Kustom', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom']]);
        $this->actingAsPeran('Tim SAKIP');

        Livewire::test(IsianBagianKustom::class, ['menu' => 'verifikasi'])
            ->assertSee('Manajemen Resiko')
            ->assertDontSee('Bagian kustom Anda di menu lain');
    }

    /* ---------- Kelengkapan konfigurasi ---------- */

    public function test_kelengkapan_manajemen_resiko_produksi_hanya_saran_tanpa_kolom(): void
    {
        $m = KelengkapanBagianKustom::periksa($this->manajemenResikoProduksi(), 2026, 4);

        $this->assertFalse(KelengkapanBagianKustom::adaGalat($m));
        $this->assertCount(1, $m);
        $this->assertSame('isian', $m[0]['tab']);
        $this->assertStringContainsString('Belum ada kolom isian aktif', $m[0]['pesan']);
    }

    public function test_kelengkapan_mendeteksi_peran_yang_tidak_bisa_membuka_menu_dan_variabel_notula_asing(): void
    {
        $b = BagianKustom::create([
            'nama' => 'Salah Atur', 'aktif' => true, 'peran' => ['kepala'], 'penempatan' => ['pengisian'],
            'masuk_notula' => true, 'format_notula' => '{teks} {tidak_ada}',
            'kolom' => [['kunci' => 'nilai', 'label' => 'Nilai', 'tipe' => 'teks']],
            'subbagian' => [['kunci' => 'sub_1', 'nama' => 'Kosong', 'aktif' => true]],
        ]);

        $pesan = collect(KelengkapanBagianKustom::periksa($b, 2026, 4));

        $this->assertTrue(KelengkapanBagianKustom::adaGalat($pesan->all()));
        $this->assertTrue($pesan->contains(fn ($m) => $m['tingkat'] === 'galat' && str_contains($m['pesan'], 'Kepala dipilih sebagai pengisi')));
        $this->assertTrue($pesan->contains(fn ($m) => str_contains($m['pesan'], 'Menu Isian Kegiatan tidak dibuka')));
        $this->assertTrue($pesan->contains(fn ($m) => $m['tab'] === 'notula' && str_contains($m['pesan'], '{tidak_ada}')));
        $this->assertTrue($pesan->contains(fn ($m) => str_contains($m['pesan'], 'Subbagian "Kosong" belum punya kolom aktif')));
    }

    public function test_daftar_pengelolaan_menampilkan_indikator_dan_tombol_perbaiki_membuka_tab(): void
    {
        $b = $this->manajemenResikoProduksi();
        $lengkap = BagianKustom::create([
            'nama' => 'Lengkap', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom'],
            'kolom' => [['kunci' => 'nilai', 'label' => 'Nilai', 'tipe' => 'teks']],
        ]);
        $this->actingAsPeran('Tim SAKIP');

        Livewire::test(BagianKustomManager::class)
            ->assertSeeHtml('data-kelengkapan="saran"')
            ->assertSeeHtml('data-kelengkapan="lengkap"')
            ->assertSee('Belum ada kolom isian aktif')
            ->call('perbaiki', $b->id, 'isian')
            ->assertSet('bukaId', $b->id)
            ->assertSet('tab', 'isian')
            // Tab yang tidak dikenal diabaikan.
            ->call('perbaiki', $b->id, 'tidak-ada')
            ->assertSet('tab', 'isian');

        $this->assertNotSame($b->id, $lengkap->id);
    }

    /* ---------- Duplikasi subbagian & kolom ---------- */

    protected function bagianBerstruktur(): BagianKustom
    {
        return BagianKustom::create([
            'nama' => 'Berstruktur', 'aktif' => true, 'peran' => ['tim_sakip'], 'penempatan' => ['isian_bagian_kustom'],
            'subbagian' => [['kunci' => 'sub_1', 'nama' => 'Identifikasi', 'aktif' => true]],
            'kolom' => [
                ['kunci' => 'level', 'label' => 'Level', 'tipe' => 'pilihan', 'opsi' => ['Rendah', 'Tinggi'], 'wajib' => true, 'subbagian' => 'sub_1'],
                ['kunci' => 'skor', 'label' => 'Skor', 'tipe' => 'angka', 'min' => '0', 'maks' => '25', 'subbagian' => 'sub_1'],
                ['kunci' => 'catatan', 'label' => 'Catatan', 'tipe' => 'teks_panjang'],
            ],
        ]);
    }

    public function test_duplikasi_subbagian_menyalin_kolom_dengan_identitas_baru_tanpa_mengubah_data_lama(): void
    {
        $b = $this->bagianBerstruktur();
        $iku = MasterIku::create(['kode' => 'UJI-1', 'indikator' => 'Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $periode = Periode::firstOrCreate(['tahun' => 2026, 'bulan' => 4], ['triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]);
        $poin = BagianKustomPoin::create([
            'bagian_kustom_id' => $b->id, 'iku_id' => $iku->id, 'periode_id' => $periode->id, 'teks' => 'Risiko A',
            'nilai_kolom' => ['level' => 'Tinggi', 'skor' => '12'], 'status_verifikasi' => 'terverifikasi',
            'menu' => 'isian_bagian_kustom', 'peran_pengisi' => 'tim_sakip',
        ]);
        $this->actingAsPeran('Tim SAKIP');

        Livewire::test(BagianKustomManager::class)
            ->call('buka', $b->id)
            ->call('setTab', 'isian')
            ->call('duplikasiSubbagian', $b->id, 0)
            ->assertSet("form.{$b->id}.subbagian.1.nama", 'Identifikasi (salinan)')
            ->call('simpan', $b->id)
            ->assertHasNoErrors();

        $b->refresh();
        $sub = $b->subbagianList();
        $this->assertSame(['sub_1', 'sub_2'], array_column($sub, 'kunci'));

        $kolom = collect($b->kolomList());
        $this->assertCount(5, $kolom);
        $this->assertCount(5, $kolom->pluck('kunci')->unique(), 'setiap kolom salinan punya variabel sendiri');
        $salinan = $kolom->where('subbagian', 'sub_2')->values();
        $this->assertSame(['Level', 'Skor'], $salinan->pluck('label')->all());
        $this->assertSame(['Rendah', 'Tinggi'], $salinan[0]['opsi']);
        $this->assertTrue($salinan[0]['wajib']);
        $this->assertNotContains($salinan[0]['kunci'], ['level', 'skor']);

        // Data isian lama tidak berubah dan tetap terhubung ke kolom asal.
        $this->assertSame(['level' => 'Tinggi', 'skor' => '12'], $poin->fresh()->nilai_kolom);
    }

    public function test_duplikasi_kolom_menyalin_definisi_tepat_di_bawahnya(): void
    {
        $b = $this->bagianBerstruktur();
        $this->actingAsPeran('Tim SAKIP');

        Livewire::test(BagianKustomManager::class)
            ->call('buka', $b->id)
            ->call('duplikasiKolom', $b->id, 1)
            ->assertSet("form.{$b->id}.kolom.2.label", 'Skor (salinan)')
            ->assertSet("form.{$b->id}.kolom.2.kunci", '')
            ->assertSet("form.{$b->id}.kolom.2.subbagian", 'sub_1')
            ->call('simpan', $b->id)
            ->assertHasNoErrors();

        $kolom = collect($b->fresh()->kolomList());
        $this->assertSame(['Level', 'Skor', 'Skor (salinan)', 'Catatan'], $kolom->pluck('label')->all());
        $baru = $kolom[2];
        $this->assertNotSame('skor', $baru['kunci']);
        $this->assertSame('angka', $baru['tipe']);
        $this->assertSame('25', (string) $baru['maks']);
    }

    public function test_pengguna_bukan_tim_sakip_tidak_bisa_memakai_aksi_editor_baru(): void
    {
        $b = $this->bagianBerstruktur();
        $this->actingAsPeran('Ketua Tim');

        Livewire::test(BagianKustomManager::class)->assertForbidden();
        $this->assertCount(3, $b->fresh()->kolomList());
    }
}
