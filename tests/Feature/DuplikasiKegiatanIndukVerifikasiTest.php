<?php

namespace Tests\Feature;

use App\Livewire\PengisianKegiatan;
use App\Livewire\VerifikasiCapaian;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\Periode;
use App\Models\Role;
use App\Models\RtlEvaluasi;
use App\Models\User;
use App\Services\NotulaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Detail Verifikasi menampilkan SATU kartu per kegiatan induk — kegiatan lama yang
 * kemudian dipecah ke beberapa tahapan (satu baris Kegiatan per tahapan, mis. baris
 * "Pelaksanaan" lama + baris "Persiapan" yang ditambahkan belakangan) tidak lagi
 * muncul berulang, sejalan dengan daftar kegiatan di notula. Baris tahapan, berkas,
 * folder & status verifikasi yang sudah tersimpan tetap utuh.
 */
class DuplikasiKegiatanIndukVerifikasiTest extends TestCase
{
    use RefreshDatabase;

    protected MasterIku $iku;

    protected Periode $periode;

    protected Capaian $capaian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->iku = MasterIku::create(['kode' => 'UJI-DUP', 'indikator' => 'Indikator uji duplikasi', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji', 'sasaran' => 'Sasaran Uji Duplikasi']);
        $this->periode = Periode::create(['tahun' => 2026, 'bulan' => 9, 'triwulan' => 3, 'bulan_ke' => 3, 'flag_bulan_terlewat' => false]);
        $this->capaian = Capaian::create(['iku_id' => $this->iku->id, 'periode_id' => $this->periode->id, 'status' => Capaian::STATUS_DIAJUKAN]);

        $this->actingAs(User::create([
            'nama' => 'Tim SAKIP Uji', 'username' => 'uji-dup@example.test', 'email' => 'uji-dup@example.test',
            'password' => 'password', 'role_id' => Role::create(['nama' => 'Tim SAKIP'])->id, 'status_verifikasi' => 'terverifikasi',
        ]));
    }

    protected function poinRtl(string $teks): RtlEvaluasi
    {
        return RtlEvaluasi::create([
            'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id,
            'rtl_teks' => $teks, 'berlaku_bulan' => 'September', 'pic' => 'PIC', 'batas_waktu' => '2026-09-30',
        ]);
    }

    /** Satu baris tahapan, persis seperti PengisianKegiatan::simpanBagianIsian(). */
    protected function tahapan(string $uraian, ?string $tahapan, array $atribut = []): Kegiatan
    {
        $kegiatan = Kegiatan::create($atribut + [
            'iku_id' => $this->iku->id, 'periode_id' => $this->periode->id,
            'uraian_kegiatan' => $uraian, 'jenis' => 'survei_sensus', 'tahapan_survei' => $tahapan,
            'nama_folder_auto' => PengisianKegiatan::uraianDenganTahapan($uraian, $tahapan),
            'drive_folder_id' => 'folder-'.($tahapan ?? 'kegiatan').'-'.str()->random(6),
            'status_dokumen' => Kegiatan::STATUS_DIAJUKAN,
        ]);

        Berkas::create([
            'ref_id' => $kegiatan->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian',
            'nama_file' => "Bukti {$tahapan} {$kegiatan->id}.pdf", 'status_verifikasi' => 'menunggu', 'drive_file_id' => "drive-{$kegiatan->id}",
        ]);

        return $kegiatan;
    }

    /**
     * Data produksi lama: baris "Pelaksanaan" dibuat dulu, tahapan "Persiapan" kegiatan
     * yang SAMA ditambahkan belakangan sebagai baris baru (id lebih besar), sama-sama
     * melaksanakan satu poin RTL.
     *
     * @return array{pelaksanaan: Kegiatan, persiapan: Kegiatan}
     */
    protected function kegiatanLamaDipecah(): array
    {
        $rtl = $this->poinRtl('Survei Updating Direktori UPB dan UTL');
        $uraian = 'Terlaksananya persiapan dan pelaksanaan Survei Updating Direktori UPB dan UTL';

        return [
            'pelaksanaan' => $this->tahapan($uraian, 'pelaksanaan', ['rtl_evaluasi_id' => $rtl->id]),
            'persiapan' => $this->tahapan($uraian, 'persiapan', ['rtl_evaluasi_id' => $rtl->id]),
        ];
    }

    protected function verifikasi(): Testable
    {
        return Livewire::test(VerifikasiCapaian::class, ['capaian' => $this->capaian->fresh()]);
    }

    /** @return list<string> */
    protected function namaKartu(Testable $test): array
    {
        return $test->instance()->kegiatanIndukList()->pluck('nama_utama')->all();
    }

    public function test_kegiatan_lama_yang_belum_dipecah_tampil_satu_kali(): void
    {
        $this->tahapan('Survei Sakernas', 'pelaksanaan');
        $this->tahapan('Rapat koordinasi', null, ['jenis' => 'bukan_survei_sensus']);

        $test = $this->verifikasi()
            ->assertSee('2 kegiatan pendukung')
            ->assertSeeInOrder(['Kegiatan 1', 'Kegiatan 2']);

        $this->assertSame(['Survei Sakernas', 'Rapat koordinasi'], $this->namaKartu($test));
        $this->assertSame(1, substr_count($test->html(), '>Kegiatan 1<'));
        $this->assertSame(1, substr_count($test->html(), '>Kegiatan 2<'));
    }

    public function test_kegiatan_lama_yang_dipecah_tidak_tampil_berulang(): void
    {
        $kegiatan = $this->kegiatanLamaDipecah();

        $test = $this->verifikasi()
            ->assertSee('1 kegiatan pendukung')
            ->assertSee('2 tahapan')
            // Tahapan urut alur kerja (Persiapan dulu) walau baris Persiapan dibuat belakangan.
            ->assertSeeInOrder(['Kegiatan 1', 'Tahapan Persiapan', 'Tahapan Pelaksanaan'])
            ->assertDontSee('Kegiatan 2');
        $html = $test->html();

        $this->assertSame(1, substr_count($html, '>Kegiatan 1<'));
        // Satu isian uraian & satu tombol tanda uraian untuk seluruh tahapan.
        $this->assertSame(1, substr_count($html, 'wire:model.live.blur="koreksiKegiatan.'));
        $this->assertSame(1, substr_count($html, 'wire:click="tandaiUraianSesuai('));
        $this->assertSame(['Terlaksananya persiapan dan pelaksanaan Survei Updating Direktori UPB dan UTL'], $this->namaKartu($test));

        // Belum ditandai: uraian kegiatan ini tercatat sekali, bukan sekali per tahapan.
        $test->call('verifikasiSelesai');
        $this->assertSame(1, substr_count((string) $test->errors()->first('berkas'), 'Uraian kegiatan "'));

        // Menandai sekali berlaku untuk kedua tahapan.
        $this->verifikasi()->call('tandaiUraianSesuai', $kegiatan['persiapan']->id)->assertHasNoErrors();
        foreach ($kegiatan as $k) {
            $this->assertSame('terverifikasi', $k->fresh()->status_verifikasi_uraian);
        }
    }

    public function test_tahapan_berkas_dan_folder_tetap_tersimpan_per_tahapan(): void
    {
        $kegiatan = $this->kegiatanLamaDipecah();
        $sebelum = Kegiatan::orderBy('id')->get()->keyBy('id');

        $test = $this->verifikasi();
        foreach ($kegiatan as $k) {
            // Lokasi bukti dukung & berkas tiap tahapan tetap tampil di kartu kegiatannya.
            $test->assertSee($k->namaFolder())->assertSee("Bukti {$k->tahapan_survei} {$k->id}.pdf");
        }

        // Berkas tetap ditandai per berkas, tidak ikut tahapan lain.
        $berkasPersiapan = Berkas::where('ref_id', $kegiatan['persiapan']->id)->sole();
        $test->call('tandaiSesuai', $berkasPersiapan->id)->assertHasNoErrors();
        $this->assertSame('terverifikasi', $berkasPersiapan->fresh()->status_verifikasi);
        $this->assertSame('menunggu', Berkas::where('ref_id', $kegiatan['pelaksanaan']->id)->sole()->status_verifikasi);

        // Uraian yang dikoreksi berlaku untuk semua tahapan; baris, folder & berkas tetap.
        $test->set("koreksiKegiatan.{$kegiatan['pelaksanaan']->id}", 'Terlaksananya Survei Updating Direktori UPB dan UTL')
            ->call('simpanSementara')
            ->assertHasNoErrors();

        $sesudah = Kegiatan::orderBy('id')->get()->keyBy('id');
        $this->assertSame($sebelum->keys()->all(), $sesudah->keys()->all());
        foreach ($sebelum as $id => $lama) {
            $this->assertSame('Terlaksananya Survei Updating Direktori UPB dan UTL', $sesudah[$id]->uraian_kegiatan);
            $this->assertSame($lama->tahapan_survei, $sesudah[$id]->tahapan_survei);
            $this->assertSame($lama->nama_folder_auto, $sesudah[$id]->nama_folder_auto);
            $this->assertSame($lama->drive_folder_id, $sesudah[$id]->drive_folder_id);
            $this->assertSame("drive-{$id}", Berkas::where('ref_id', $id)->sole()->drive_file_id);
        }
    }

    public function test_kegiatan_berbeda_dengan_nama_serupa_tetap_terpisah(): void
    {
        $this->tahapan('Survei Updating Direktori UPB', 'persiapan');
        $this->tahapan('Survei Updating Direktori UTL', 'pelaksanaan');
        // Uraian sama persis tapi tahapan & bulan sama: dua kegiatan berbeda.
        $this->tahapan('Rapat koordinasi', null, ['jenis' => 'bukan_survei_sensus']);
        $this->tahapan('Rapat koordinasi', null, ['jenis' => 'bukan_survei_sensus']);

        $test = $this->verifikasi()
            ->assertSee('4 kegiatan pendukung')
            ->assertSeeInOrder(['Kegiatan 1', 'Kegiatan 2', 'Kegiatan 3', 'Kegiatan 4']);

        $this->assertSame(['Survei Updating Direktori UPB', 'Survei Updating Direktori UTL', 'Rapat koordinasi', 'Rapat koordinasi'], $this->namaKartu($test));
    }

    public function test_kegiatan_dari_rtl_tidak_menggandakan_kegiatan_yang_sama(): void
    {
        $rtl = $this->poinRtl('Melaksanakan Sakernas');
        // Tahapan pertama dipilih dari dropdown RTL, tahapan berikutnya tanpa tautan RTL.
        $this->tahapan('Survei Sakernas', 'persiapan', ['rtl_evaluasi_id' => $rtl->id]);
        $this->tahapan('survei  sakernas', 'pelaksanaan');
        // Kegiatan lain yang melaksanakan poin RTL yang sama tetap kegiatan tersendiri.
        $this->tahapan('Survei Susenas', 'pelaksanaan', ['rtl_evaluasi_id' => $rtl->id]);

        $test = $this->verifikasi()->assertSee('2 kegiatan pendukung');

        $this->assertSame(['Survei Sakernas', 'Survei Susenas'], $this->namaKartu($test));
        $this->assertSame([2, 1], $test->instance()->kegiatanIndukList()->map(fn ($induk) => $induk['tahapan']->count())->all());
    }

    public function test_daftar_kegiatan_verifikasi_sama_dengan_notula(): void
    {
        $this->kegiatanLamaDipecah();
        $this->tahapan('Survei Sakernas', 'persiapan');
        $this->tahapan('Rapat koordinasi', null, ['jenis' => 'bukan_survei_sensus']);

        $namaVerifikasi = $this->namaKartu($this->verifikasi());

        Kegiatan::query()->update(['status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);
        $this->capaian->update(['status' => Capaian::STATUS_DIVERIFIKASI]);
        $data = app(NotulaService::class)->kumpulkanDataBagianSatu(Notula::firstOrCreate(['periode_id' => $this->periode->id]));
        $namaNotula = Kegiatan::daftarNamaCapaian($data['kegiatanPerIku']->get($this->iku->id, collect()))->all();

        $this->assertSame($namaNotula, $namaVerifikasi);
        $this->assertCount(3, $namaNotula);
    }

    public function test_status_verifikasi_tersimpan_tidak_berubah(): void
    {
        $kegiatan = $this->kegiatanLamaDipecah();
        $kegiatan['pelaksanaan']->update(['status_verifikasi_uraian' => 'terverifikasi', 'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);
        $kegiatan['persiapan']->update(['status_verifikasi_uraian' => 'ditolak', 'catatan_uraian' => 'Uraian belum lengkap']);
        Berkas::where('ref_id', $kegiatan['pelaksanaan']->id)->update(['status_verifikasi' => 'terverifikasi']);
        Berkas::where('ref_id', $kegiatan['persiapan']->id)->update(['status_verifikasi' => 'ditolak', 'catatan' => 'Bukti buram']);
        $sebelum = Kegiatan::orderBy('id')->get(['id', 'uraian_kegiatan', 'status_dokumen', 'status_verifikasi_uraian', 'catatan_uraian'])->toArray();
        $berkasSebelum = Berkas::orderBy('id')->get(['id', 'status_verifikasi', 'catatan'])->toArray();

        // Membuka & menyimpan sementara tanpa menandai apa pun tidak mengubah status.
        $test = $this->verifikasi()
            // Status gabungan: ada tahapan yang ditolak → ditolak, catatannya tampil di
            // satu-satunya isian catatan uraian kegiatan ini (kunci = baris wakil).
            ->assertSeeHtml('data-tersimpan="ditolak"');
        $wakil = $test->instance()->kegiatanIndukList()->sole()['wakil'];
        $test->assertSet("catatanUraian.{$wakil->id}", 'Uraian belum lengkap')
            ->call('simpanSementara')
            ->assertHasNoErrors();

        $this->assertSame($sebelum, Kegiatan::orderBy('id')->get(['id', 'uraian_kegiatan', 'status_dokumen', 'status_verifikasi_uraian', 'catatan_uraian'])->toArray());
        $this->assertSame($berkasSebelum, Berkas::orderBy('id')->get(['id', 'status_verifikasi', 'catatan'])->toArray());

        $ditolak = collect($test->instance()->daftarIsianDitolak())->filter(fn ($d) => str_starts_with($d['label'], 'Uraian kegiatan'));
        $this->assertCount(1, $ditolak);
        $this->assertSame('Uraian belum lengkap', $ditolak->first()['catatan']);
    }

    public function test_uraian_lama_yang_sudah_berbeda_tidak_ditimpa_bila_tidak_disunting(): void
    {
        $kegiatan = $this->kegiatanLamaDipecah();
        // Data lama: uraian satu tahapan pernah dikoreksi sendiri (sebelum perbaikan ini).
        $kegiatan['persiapan']->update(['uraian_kegiatan' => 'Survei Updating Direktori UPB dan UTL 2026']);

        $test = $this->verifikasi()
            ->assertSee('1 kegiatan pendukung')
            ->assertSee('Di notula kegiatan ini tetap dicatat satu kali');
        $this->assertSame(['Survei Updating Direktori UPB dan UTL 2026'], $this->namaKartu($test));

        $test->call('simpanSementara')->assertHasNoErrors();

        $this->assertSame('Terlaksananya persiapan dan pelaksanaan Survei Updating Direktori UPB dan UTL', $kegiatan['pelaksanaan']->fresh()->uraian_kegiatan);
        $this->assertSame('Survei Updating Direktori UPB dan UTL 2026', $kegiatan['persiapan']->fresh()->uraian_kegiatan);
    }
}
