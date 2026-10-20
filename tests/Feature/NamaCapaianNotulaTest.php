<?php

namespace Tests\Feature;

use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\Periode;
use App\Services\NotulaBagian1DocxService;
use App\Services\NotulaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Nama capaian di Analisis Capaian Kinerja & tabel RO notula = nama kegiatan utama
 * (uraian_kegiatan), satu kali per kegiatan walau dipisah ke beberapa tahapan —
 * bukan nama folder tahapannya (lihat Kegiatan::namaCapaian()/daftarNamaCapaian()).
 */
class NamaCapaianNotulaTest extends TestCase
{
    use RefreshDatabase;

    protected MasterIku $iku;

    protected Periode $juli;

    protected Periode $agustus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->juli = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->agustus = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $this->iku = $this->buatIku('4001');
    }

    protected function buatIku(string $kode): MasterIku
    {
        $iku = MasterIku::create([
            'kode' => $kode, 'indikator' => "Indikator Uji {$kode}", 'tim' => 'Tim Uji', 'penanggung_jawab' => 'Uji', 'sasaran' => 'Sasaran Uji Nama Capaian',
        ]);
        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $this->juli->id, 'status' => Capaian::STATUS_DIVERIFIKASI]);

        return $iku;
    }

    /**
     * Satu baris Kegiatan per tahapan, persis seperti PengisianKegiatan::simpanBagianIsian().
     */
    protected function tahapan(string $uraian, string $tahapan, array $atribut = []): Kegiatan
    {
        return Kegiatan::create($atribut + [
            'iku_id' => $this->iku->id, 'periode_id' => $this->juli->id,
            'uraian_kegiatan' => $uraian, 'jenis' => 'survei_sensus', 'tahapan_survei' => $tahapan,
            'nama_folder_auto' => ucfirst($tahapan).' '.$uraian,
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);
    }

    protected function satuFolder(string $uraian, array $atribut = []): Kegiatan
    {
        return Kegiatan::create($atribut + [
            'iku_id' => $this->iku->id, 'periode_id' => $this->juli->id,
            'uraian_kegiatan' => $uraian, 'jenis' => 'bukan_survei_sensus',
            'nama_folder_auto' => $uraian, 'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);
    }

    protected function namaCapaianIku(MasterIku $iku): array
    {
        $data = app(NotulaService::class)->kumpulkanDataBagianSatu(Notula::firstOrCreate(['periode_id' => $this->juli->id]));

        return Kegiatan::daftarNamaCapaian($data['kegiatanPerIku']->get($iku->id, collect()))->all();
    }

    /**
     * word/document.xml Bagian I dari generate() — $untukPratinjauHtml true = jalur
     * pratinjau (yang dikonversi LibreOffice jadi bagian1_html, sumber PDF & .docx
     * lengkap), false = jalur unduhan .docx langsung.
     */
    protected function xmlBagianSatu(bool $untukPratinjauHtml): string
    {
        $notula = Notula::firstOrCreate(['periode_id' => $this->juli->id]);
        $path = tempnam(sys_get_temp_dir(), 'nama-capaian').'.docx';

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

    public function test_kegiatan_satu_folder_menghasilkan_satu_nama_capaian(): void
    {
        $this->satuFolder('Rapat   koordinasi  ', ['nama_folder_kustom' => 'Folder Rapat Khusus']);

        $this->assertSame(['Rapat koordinasi'], $this->namaCapaianIku($this->iku));
    }

    public function test_kegiatan_dengan_beberapa_tahapan_menghasilkan_satu_nama_kegiatan_utama(): void
    {
        $this->tahapan('Survei Sakernas', 'persiapan');
        $this->tahapan('Survei Sakernas', 'pelaksanaan');
        $this->tahapan('Survei Sakernas', 'pengolahan');
        // Kegiatan yang sama dari bulan lain triwulan ini (beda besar/kecil huruf & spasi).
        $this->tahapan(' survei  sakernas', 'diseminasi', ['periode_id' => $this->agustus->id]);

        $this->assertSame(['Survei Sakernas'], $this->namaCapaianIku($this->iku));
    }

    public function test_nama_folder_tahapan_yang_diganti_tidak_mengubah_nama_capaian(): void
    {
        $this->tahapan('Survei Sakernas', 'persiapan', ['nama_folder_kustom' => 'Folder Persiapan Versi Saya']);
        $this->tahapan('Survei Sakernas', 'pelaksanaan', ['nama_folder_kustom' => 'Lapangan Sakernas']);

        $this->assertSame(['Survei Sakernas'], $this->namaCapaianIku($this->iku));
    }

    public function test_kegiatan_utama_berbeda_dalam_satu_indikator_tetap_terpisah(): void
    {
        $this->tahapan('Survei Sakernas', 'persiapan');
        $this->tahapan('Survei Sakernas', 'pelaksanaan');
        $this->tahapan('Survei Susenas', 'persiapan');
        $this->satuFolder('Rapat koordinasi');

        $this->assertSame(['Survei Sakernas', 'Survei Susenas', 'Rapat koordinasi'], $this->namaCapaianIku($this->iku));
    }

    public function test_kegiatan_pada_indikator_atau_triwulan_lain_tidak_tercampur(): void
    {
        $ikuLain = $this->buatIku('4002');
        $april = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        $this->tahapan('Survei Sakernas', 'persiapan');
        $this->tahapan('Survei Sakernas', 'pelaksanaan', ['iku_id' => $ikuLain->id]);
        $this->tahapan('Survei Triwulan Lalu', 'persiapan', ['periode_id' => $april->id]);

        $this->assertSame(['Survei Sakernas'], $this->namaCapaianIku($this->iku));
        $this->assertSame(['Survei Sakernas'], $this->namaCapaianIku($ikuLain));

        // Penyatuan tetap per IKU walau koleksi campuran diberikan langsung.
        $this->assertCount(2, Kegiatan::daftarNamaCapaian(Kegiatan::where('uraian_kegiatan', 'Survei Sakernas')->get()));
    }

    public function test_tahapan_yang_dikembalikan_tidak_menghilangkan_kegiatan_utama(): void
    {
        $this->tahapan('Survei Sakernas', 'persiapan', ['status_dokumen' => Kegiatan::STATUS_DIKEMBALIKAN]);
        $this->tahapan('Survei Sakernas', 'pelaksanaan');
        $this->tahapan('Survei Susenas', 'persiapan', ['status_dokumen' => Kegiatan::STATUS_DIKEMBALIKAN]);

        // Sakernas masih punya tahapan terverifikasi; Susenas belum punya sama sekali.
        $this->assertSame(['Survei Sakernas'], $this->namaCapaianIku($this->iku));
    }

    public function test_tabel_ro_memakai_uraian_ro_atau_nama_kegiatan_utama(): void
    {
        $persiapan = $this->tahapan('Survei Sakernas', 'persiapan');
        $pelaksanaan = $this->tahapan('Survei Sakernas', 'pelaksanaan');
        $persiapan->rincianOutput()->create(['volume_ro' => '3 dokumen', 'progres_persen' => 40]);
        $pelaksanaan->rincianOutput()->create(['uraian' => 'Publikasi Sakernas Agustus', 'volume_ro' => '1 publikasi', 'progres_persen' => 75]);

        $xml = $this->xmlBagianSatu(false);

        $this->assertStringContainsString('Publikasi Sakernas Agustus', $xml);
        $this->assertStringContainsString('3 dokumen', $xml);
        $this->assertStringNotContainsString('Persiapan Survei Sakernas', $xml);
        $this->assertStringNotContainsString('Pelaksanaan Survei Sakernas', $xml);
        $this->assertStringContainsString('Survei Sakernas', $xml);
    }

    public function test_docx_unduhan_dan_jalur_pratinjau_menampilkan_daftar_capaian_yang_sama(): void
    {
        $this->tahapan('Survei Sakernas', 'persiapan', ['nama_folder_kustom' => 'Folder Persiapan Versi Saya']);
        $this->tahapan('Survei Sakernas', 'pelaksanaan');
        $this->satuFolder('Rapat koordinasi');

        foreach ([false, true] as $pratinjau) {
            $xml = $this->xmlBagianSatu($pratinjau);

            $this->assertStringContainsString('1. Survei Sakernas', $xml);
            $this->assertStringContainsString('2. Rapat koordinasi', $xml);
            $this->assertSame(1, substr_count($xml, '. Survei Sakernas'), 'Kegiatan bertahapan harus tercatat sekali saja.');
            $this->assertStringNotContainsString('Persiapan Survei Sakernas', $xml);
            $this->assertStringNotContainsString('Folder Persiapan Versi Saya', $xml);
        }
    }
}
