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
use App\Services\NotulaBagian1DocxService;
use App\Services\NotulaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * Satu kegiatan yang dipisah ke beberapa tahapan (satu baris Kegiatan per tahapan)
 * tetap dikenali sebagai SATU kegiatan induk (Kegiatan::kelompokInduk()) di Isian
 * Kegiatan, Verifikasi Capaian, Dasbor & Notula — termasuk bila uraian salah satu
 * tahapannya dikoreksi Tim SAKIP — tanpa menggabungkan kegiatan yang memang berbeda.
 */
class IdentitasKegiatanIndukTest extends TestCase
{
    use RefreshDatabase;

    protected MasterIku $iku;

    protected Periode $juli;

    protected Periode $agustus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->iku = MasterIku::create(['kode' => 'UJI-IKI', 'indikator' => 'Indikator uji induk', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji', 'sasaran' => 'Sasaran Uji Induk']);
        $this->juli = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->agustus = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
    }

    protected function login(string $peran): void
    {
        $this->actingAs(User::create([
            'nama' => "{$peran} Uji", 'username' => 'uji-iki@example.test', 'email' => 'uji-iki@example.test',
            'password' => 'password', 'role_id' => Role::create(['nama' => $peran])->id, 'status_verifikasi' => 'terverifikasi',
        ]));
    }

    /**
     * Satu baris per tahapan, persis seperti PengisianKegiatan::simpanBagianIsian().
     */
    protected function tahapan(string $uraian, string $tahapan, array $atribut = []): Kegiatan
    {
        return Kegiatan::create($atribut + [
            'iku_id' => $this->iku->id, 'periode_id' => $this->juli->id,
            'uraian_kegiatan' => $uraian, 'jenis' => 'survei_sensus', 'tahapan_survei' => $tahapan,
            'nama_folder_auto' => PengisianKegiatan::uraianDenganTahapan($uraian, $tahapan),
            'drive_folder_id' => "folder-{$tahapan}-".str()->random(6),
            'status_dokumen' => Kegiatan::STATUS_DIAJUKAN,
        ]);
    }

    protected function satuFolder(string $uraian, array $atribut = []): Kegiatan
    {
        return Kegiatan::create($atribut + [
            'iku_id' => $this->iku->id, 'periode_id' => $this->juli->id,
            'uraian_kegiatan' => $uraian, 'jenis' => 'bukan_survei_sensus',
            'nama_folder_auto' => $uraian, 'status_dokumen' => Kegiatan::STATUS_DIAJUKAN,
        ]);
    }

    protected function poinRtl(string $teks): RtlEvaluasi
    {
        return RtlEvaluasi::create([
            'iku_id' => $this->iku->id, 'periode_id' => $this->juli->id,
            'rtl_teks' => $teks, 'berlaku_bulan' => 'Juli', 'pic' => 'PIC', 'batas_waktu' => '2026-09-30',
        ]);
    }

    /** @return list<string> */
    protected function namaNotula(): array
    {
        $data = app(NotulaService::class)->kumpulkanDataBagianSatu(Notula::firstOrCreate(['periode_id' => $this->juli->id]));

        return Kegiatan::daftarNamaCapaian($data['kegiatanPerIku']->get($this->iku->id, collect()))->all();
    }

    protected function xmlBagianSatu(bool $untukPratinjauHtml): string
    {
        $notula = Notula::firstOrCreate(['periode_id' => $this->juli->id]);
        $path = tempnam(sys_get_temp_dir(), 'induk').'.docx';

        try {
            app(NotulaBagian1DocxService::class)->generate(
                $notula, app(NotulaService::class)->kumpulkanDataBagianSatu($notula), $path,
                sertakanBlokTtdMandiri: false, untukPratinjauHtml: $untukPratinjauHtml
            );
            $zip = new ZipArchive;
            $zip->open($path);
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            @unlink($path);
        }

        return $xml;
    }

    public function test_kegiatan_satu_tahapan_tampil_dengan_nama_utama(): void
    {
        $k = $this->tahapan('Survei Sakernas', 'persiapan', ['status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);

        $this->assertSame(['Survei Sakernas'], $this->namaNotula());
        $this->assertSame('Survei Sakernas', $k->namaKegiatanUtama());
        $this->assertFalse($k->uraianDikoreksi());
        $this->assertSame([$k->id => 1], Kegiatan::nomorKegiatanInduk(Kegiatan::all()->keyBy('id')));
    }

    public function test_koreksi_uraian_satu_tahapan_tidak_menggandakan_kegiatan_induk(): void
    {
        $rtl = $this->poinRtl('Melaksanakan Sakernas');
        $kegiatan = collect(['persiapan', 'pelaksanaan', 'pengolahan'])
            ->mapWithKeys(fn ($t) => [$t => $this->tahapan('Survei Sakernas', $t, ['rtl_evaluasi_id' => $rtl->id])]);
        Berkas::create([
            'ref_id' => $kegiatan['pelaksanaan']->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian',
            'nama_file' => 'bukti.pdf', 'status_verifikasi' => 'menunggu', 'drive_file_id' => 'drive-bukti',
        ]);
        $sebelum = Kegiatan::orderBy('id')->get()->keyBy('id');
        $capaian = Capaian::create(['iku_id' => $this->iku->id, 'periode_id' => $this->juli->id, 'status' => Capaian::STATUS_DIAJUKAN]);

        // Tim SAKIP mengoreksi uraian HANYA tahapan pelaksanaan.
        $this->login('Tim SAKIP');
        Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian])
            ->set("koreksiKegiatan.{$kegiatan['pelaksanaan']->id}", 'Survei Angkatan Kerja Nasional')
            ->call('simpanSementara')
            ->assertHasNoErrors();

        // Koreksi tersimpan di baris itu saja; baris lain, folder, berkas & status tetap.
        $sesudah = Kegiatan::orderBy('id')->get()->keyBy('id');
        $this->assertSame('Survei Angkatan Kerja Nasional', $sesudah[$kegiatan['pelaksanaan']->id]->uraian_kegiatan);
        $this->assertSame('Survei Sakernas', $sesudah[$kegiatan['persiapan']->id]->uraian_kegiatan);
        $this->assertSame('Survei Sakernas', $sesudah[$kegiatan['pengolahan']->id]->uraian_kegiatan);
        foreach ($sebelum as $id => $lama) {
            $this->assertSame($lama->nama_folder_auto, $sesudah[$id]->nama_folder_auto);
            $this->assertSame($lama->drive_folder_id, $sesudah[$id]->drive_folder_id);
            $this->assertSame($lama->status_dokumen, $sesudah[$id]->status_dokumen);
            $this->assertSame($lama->status_verifikasi_uraian, $sesudah[$id]->status_verifikasi_uraian);
        }
        $this->assertSame('drive-bukti', Berkas::sole()->drive_file_id);

        // Verifikasi: satu nomor kegiatan untuk ketiga tahapan + penjelasan nama di notula.
        Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian->fresh()])
            ->assertSeeInOrder(['Kegiatan 1', 'Tahapan Persiapan', 'Kegiatan 1', 'Tahapan Pelaksanaan', 'Kegiatan 1', 'Tahapan Pengolahan'])
            ->assertDontSee('Kegiatan 2')
            ->assertSee('Di notula kegiatan ini tetap dicatat satu kali');

        // Notula: satu nama (koreksi Tim SAKIP didahulukan), DOCX unduhan & jalur pratinjau/PDF sama.
        Kegiatan::query()->update(['status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);
        $capaian->update(['status' => Capaian::STATUS_DIVERIFIKASI]);

        $this->assertSame(['Survei Angkatan Kerja Nasional'], $this->namaNotula());
        foreach ([false, true] as $pratinjau) {
            $xml = $this->xmlBagianSatu($pratinjau);
            // Daftar kegiatan memakai penomoran Word (w:numPr), bukan teks "1. ".
            $this->assertSame(1, substr_count($xml, '>Survei Angkatan Kerja Nasional<'));
            $this->assertStringNotContainsString('>Survei Sakernas<', $xml);
        }
    }

    public function test_tahapan_bulan_lain_dengan_koreksi_tetap_satu_kegiatan(): void
    {
        $this->tahapan('Survei Sakernas', 'persiapan', ['status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);
        // Tahapan bulan berikutnya, uraiannya sudah dikoreksi Tim SAKIP.
        $this->tahapan('Survei Sakernas', 'pelaksanaan', ['periode_id' => $this->agustus->id, 'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI])
            ->update(['uraian_kegiatan' => 'Survei Sakernas Agustus']);

        $this->assertSame(['Survei Sakernas Agustus'], $this->namaNotula());
    }

    public function test_kegiatan_berbeda_dengan_uraian_sama_tidak_digabung(): void
    {
        // Dua kegiatan terpisah pada bulan yang sama — bukan tahapan satu kegiatan.
        $this->satuFolder('Rapat koordinasi', ['status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);
        $this->satuFolder('Rapat koordinasi', ['status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);
        // Uraian sama tapi melaksanakan poin RTL berbeda (bulan berbeda).
        $this->tahapan('Survei Sakernas', 'persiapan', ['rtl_evaluasi_id' => $this->poinRtl('RTL A')->id, 'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);
        $this->tahapan('Survei Sakernas', 'pelaksanaan', ['periode_id' => $this->agustus->id, 'rtl_evaluasi_id' => $this->poinRtl('RTL B')->id, 'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);

        $this->assertSame(['Rapat koordinasi', 'Rapat koordinasi', 'Survei Sakernas', 'Survei Sakernas'], $this->namaNotula());
    }

    public function test_poin_rtl_sama_dengan_uraian_berbeda_dinilai_dari_identitasnya(): void
    {
        $rtl = $this->poinRtl('Melaksanakan survei rutin');

        // Dua kegiatan yang memang berbeda, sama-sama melaksanakan satu poin RTL: tetap terpisah.
        $this->tahapan('Survei Sakernas', 'persiapan', ['rtl_evaluasi_id' => $rtl->id, 'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);
        $this->tahapan('Survei Susenas', 'pelaksanaan', ['rtl_evaluasi_id' => $rtl->id, 'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);
        // Tahapan Sakernas lain yang uraiannya dikoreksi: tetap menyatu ke Sakernas.
        $this->tahapan('Survei Sakernas', 'pengolahan', ['rtl_evaluasi_id' => $rtl->id, 'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI])
            ->update(['uraian_kegiatan' => 'Survei Sakernas (koreksi)']);

        $this->assertSame(['Survei Sakernas (koreksi)', 'Survei Susenas'], $this->namaNotula());
    }

    public function test_nomor_kegiatan_sama_di_isian_kegiatan_dan_verifikasi(): void
    {
        $persiapan = $this->tahapan('Survei Sakernas', 'persiapan');
        $rapat = $this->satuFolder('Rapat koordinasi');
        $pelaksanaan = $this->tahapan('Survei Sakernas', 'pelaksanaan');
        $capaian = Capaian::create(['iku_id' => $this->iku->id, 'periode_id' => $this->juli->id, 'status' => Capaian::STATUS_DIAJUKAN]);

        $this->login('Tim SAKIP');
        $verifikasi = Livewire::test(VerifikasiCapaian::class, ['capaian' => $capaian])
            ->assertSeeInOrder(['Kegiatan 1', 'Tahapan Persiapan', 'Kegiatan 2', 'Kegiatan 1', 'Tahapan Pelaksanaan']);
        $info = $verifikasi->instance()->infoKegiatanInduk();

        // Isian: tahapan satu kegiatan dimuat berdampingan dalam SATU kartu "Kegiatan 1".
        $isian = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)->set('bulan', 7)->set('iku_id', $this->iku->id)
            ->assertSeeInOrder(['Kegiatan 1', '2 tahapan', 'Survei Sakernas', 'Tahapan Persiapan', 'Tahapan Pelaksanaan', 'Kegiatan 2', 'Rapat koordinasi']);
        $nomorIsian = $isian->instance()->nomorKegiatanBlok();
        $idBlok = collect($isian->get('blocks'))->pluck('id')->all();

        $this->assertSame([$persiapan->id, $pelaksanaan->id, $rapat->id], $idBlok);
        foreach ($idBlok as $i => $id) {
            $this->assertSame($info[$id]['nomor'], $nomorIsian[$i], "Nomor kegiatan #{$id} harus sama di Isian & Verifikasi.");
        }
        $this->assertSame([1, 1, 2], $nomorIsian);

        $html = $isian->html();
        $this->assertSame(1, substr_count($html, '>Kegiatan 1<'));
        $this->assertSame(1, substr_count($html, 'class="keg keg-induk"'));
        $this->assertSame(2, substr_count($html, 'class="keg-tahapan"'));
    }

    public function test_isian_tahapan_terverifikasi_satu_kartu_uraian_sekali(): void
    {
        $persiapan = $this->tahapan('Survei Updating Direktori', 'persiapan', ['status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);
        $this->satuFolder('Rapat koordinasi', ['status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);
        $pelaksanaan = $this->tahapan('Survei Updating Direktori', 'pelaksanaan', ['status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);
        foreach ([$persiapan, $pelaksanaan] as $k) {
            Berkas::create([
                'ref_id' => $k->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian',
                'nama_file' => "bukti-{$k->tahapan_survei}.pdf", 'status_verifikasi' => 'terverifikasi', 'drive_file_id' => "drive-{$k->id}",
            ]);
        }

        $this->login('Ketua Tim');
        $isian = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)->set('bulan', 7)->set('iku_id', $this->iku->id)
            ->assertSeeInOrder([
                'Kegiatan 1', '2 tahapan', 'Survei Updating Direktori',
                'Tahapan Persiapan', 'bukti-persiapan.pdf', 'Tahapan Pelaksanaan', 'bukti-pelaksanaan.pdf',
                'Kegiatan 2', 'Rapat koordinasi',
            ]);

        $html = $isian->html();
        $this->assertSame(1, substr_count($html, '>Kegiatan 1<'));
        $this->assertSame(0, substr_count($html, '>Kegiatan 3<'));
        $this->assertSame(1, substr_count($html, '>Survei Updating Direktori</div>'));
        $this->assertSame(1, substr_count($html, 'Pisah ke beberapa tahapan</div>'));
    }

    public function test_uraian_tahapan_yang_bisa_disunting_disamakan_dalam_satu_kegiatan(): void
    {
        $this->tahapan('Survei Sakernas', 'persiapan', ['status_dokumen' => Kegiatan::STATUS_DRAFT]);
        $this->tahapan('Survei Sakernas', 'pelaksanaan', ['status_dokumen' => Kegiatan::STATUS_DRAFT]);
        $this->satuFolder('Rapat koordinasi', ['status_dokumen' => Kegiatan::STATUS_DRAFT]);

        $this->login('Ketua Tim');
        $isian = Livewire::test(PengisianKegiatan::class)
            ->set('tahun', 2026)->set('bulan', 7)->set('iku_id', $this->iku->id)
            ->set('blocks.0.uraian_kegiatan', 'Survei Angkatan Kerja Nasional');

        $this->assertSame(
            ['Survei Angkatan Kerja Nasional', 'Survei Angkatan Kerja Nasional', 'Rapat koordinasi'],
            collect($isian->get('blocks'))->pluck('uraian_kegiatan')->all()
        );
        $this->assertSame([1, 1, 2], $isian->instance()->nomorKegiatanBlok());
    }

    public function test_keterangan_tahapan_memakai_token_tema_terang_dan_gelap(): void
    {
        $html = (string) $this->blade('<x-tahapan-kegiatan tahapan="pelaksanaan" />');

        $this->assertStringContainsString('Tahapan Pelaksanaan', $html);
        $this->assertStringContainsString('color:var(--ink)', $html);
        $this->assertStringContainsString('background:var(--chip-auto-bg)', $html);
        $this->assertSame('', trim((string) $this->blade('<x-tahapan-kegiatan :tahapan="null" />')));

        // Kedua token didefinisikan ulang di KEDUA blok tema gelap (ikut OS & dipaksa).
        $css = file_get_contents(resource_path('css/sipinter.css'));
        $this->assertSame(2, substr_count($css, '--chip-auto-bg:#334155'));
        $this->assertSame(2, substr_count($css, '--ink:#f8fafc'));
    }
}
