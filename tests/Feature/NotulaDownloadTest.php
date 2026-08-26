<?php

namespace Tests\Feature;

use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class NotulaDownloadTest extends TestCase
{
    /**
     * Ekstrak isi teks word/document.xml dari respons unduhan .docx -- dipakai untuk
     * memastikan macro benar-benar terisi (bukan sekadar HTTP 200), tanpa bergantung
     * pada kode IKU tertentu yang mungkin sudah tidak dipakai lagi di Master IKU nyata.
     */
    protected function documentXmlDariRespons($response): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'notula_test_');
        file_put_contents($tmpPath, $response->streamedContent());

        $zip = new ZipArchive;
        $zip->open($tmpPath);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($tmpPath);

        return $xml === false ? '' : $xml;
    }
    use RefreshDatabase;

    protected function loginSebagai(string $peranNama): User
    {
        $peran = Role::firstOrCreate(['nama' => $peranNama]);
        $user = User::create([
            'nama' => "$peranNama Uji", 'username' => strtolower(str_replace(' ', '', $peranNama)), 'email' => strtolower(str_replace(' ', '', $peranNama)).'@example.test',
            'password' => 'password', 'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_pratinjau_bagian2_404_bila_belum_diunggah(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $notula = Notula::create(['periode_id' => $periode->id]);

        $this->get(route('notula.pratinjau-bagian2', $notula))->assertNotFound();
    }

    public function test_pratinjau_bagian2_menampilkan_berkas_inline_setelah_diunggah(): void
    {
        Storage::fake('local');
        $this->loginSebagai('Tim SAKIP');

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $notula = Notula::create(['periode_id' => $periode->id]);

        Storage::disk('local')->put("notula/{$notula->id}/bagian2.pdf", '%PDF-1.4 dummy');
        $notula->update(['bagian2_pdf' => "notula/{$notula->id}/bagian2.pdf"]);

        $this->get(route('notula.pratinjau-bagian2', $notula))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_ketua_tim_tidak_bisa_mengakses_pratinjau_bagian2(): void
    {
        $this->loginSebagai('Ketua Tim');

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $notula = Notula::create(['periode_id' => $periode->id]);

        $this->get(route('notula.pratinjau-bagian2', $notula))->assertForbidden();
    }

    public function test_tim_sakip_bisa_mengunduh_template_word_bagian1_bagian2_dan_bagian3(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $this->get(route('notula.template-bagian1'))
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->get(route('notula.template-bagian2'))
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->get(route('notula.template-bagian3'))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_tim_sakip_bisa_mengunduh_bagian1_sebagai_docx_terisi_otomatis(): void
    {
        $this->loginSebagai('Tim SAKIP');

        MasterIku::create([
            'kode' => '1111',
            'indikator' => 'Persentase Publikasi/Laporan Statistik Kependudukan Dan Ketenagakerjaan Yang Berkualitas',
            'tim' => 'Tim Uji',
            'penanggung_jawab' => 'Uji',
            'sasaran' => 'Terwujudnya Penyediaan Data Dan Insight Statistik Kependudukan Dan Ketenagakerjaan Yang Berkualitas',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $notula = Notula::create(['periode_id' => $periode->id, 'pimpinan_rapat' => 'Uji Pimpinan']);

        $this->get(route('notula.unduh-bagian1-docx', $notula))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    /**
     * Regresi RF baru: dulu template .docx punya satu macro TERPISAH per kode IKU
     * yang dibakukan saat template dibuat (mis. {{sasaran_1111}}) -- begitu Master IKU
     * diberi kode BERBEDA (mis. lewat impor ulang dari Excel), macro-nya tidak pernah
     * cocok lagi dan sel-selnya tetap kosong di berkas hasil unduhan walau pratinjau
     * web sudah benar. Kode di bawah ini SENGAJA bukan salah satu kode lama yang dulu
     * dibakukan di template (1111, 1131, dst.) untuk memastikan itu tidak terulang.
     */
    public function test_docx_bagian1_mengikuti_kode_iku_terkini_walau_bukan_kode_lama(): void
    {
        $this->loginSebagai('Tim SAKIP');

        MasterIku::create([
            'kode' => '1001',
            'indikator' => 'Persentase Publikasi Statistik Uji',
            'tim' => 'Tim Uji',
            'penanggung_jawab' => 'Uji',
            'sasaran' => 'Sasaran Uji Kode Baru',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('1001', $xml);
        $this->assertStringContainsString('Sasaran Uji Kode Baru', $xml);
        $this->assertStringContainsString('Persentase Publikasi Statistik Uji', $xml);
        // Tidak boleh ada lagi macro mentah yang tersisa (tanda template gagal terisi).
        $this->assertStringNotContainsString('{{sasaran}}', $xml);
        $this->assertStringNotContainsString('{{kode}}', $xml);
        $this->assertStringNotContainsString('{{indikator}}', $xml);
    }

    /**
     * Realisasi Volume RO & Progres Pelaksanaan Kegiatan pada .docx harus diambil dari
     * data Kegiatan sungguhan (sama seperti jalur pratinjau PDF), bukan dikosongkan
     * begitu saja seperti versi lama.
     */
    public function test_docx_bagian1_mengisi_realisasi_ro_dari_kegiatan(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '2002',
            'indikator' => 'Indikator Uji RO',
            'tim' => 'Tim Uji',
            'penanggung_jawab' => 'Uji',
            'sasaran' => 'Sasaran Uji RO',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        Kegiatan::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan uji RO docx',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
            'rincian_output' => 'Publikasi Uji RO Docx',
            'volume_ro' => '5 publikasi',
            'progres_persen' => 60,
        ]);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('Publikasi Uji RO Docx', $xml);
        $this->assertStringContainsString('5 publikasi', $xml);
        $this->assertStringContainsString('60,00%', $xml);
    }

    /**
     * Indikator SAKIP/BerAKHLAK pada .docx harus memakai format khusus (tabel Indikator
     * Proksi / narasi tanpa tabel RO), sama seperti jalur pratinjau PDF -- dideteksi dari
     * teks indikator, bukan kode (yang bisa berbeda tiap satker & berubah kapan pun).
     */
    public function test_docx_bagian1_indikator_sakip_dan_berakhlak_memakai_format_khusus(): void
    {
        $this->loginSebagai('Tim SAKIP');

        MasterIku::create(['kode' => '9008', 'indikator' => 'Nilai SAKIP oleh Inspektorat', 'sasaran' => 'Dukungan Manajemen', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        MasterIku::create(['kode' => '9009', 'indikator' => 'Indeks Implementasi BerAKHLAK', 'sasaran' => 'Dukungan Manajemen', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('Jelaskan mengenai persentase monitoring capaian kinerja triwulanan yang terlaksana tepat waktu', $xml);
        $this->assertStringContainsString('Indikator Proksi', $xml);
        $this->assertStringContainsString('Jelaskan mengenai Persentase kegiatan untuk mengoptimalkan implementasi BerAKHLAK yang terlaksana sesuai rencana', $xml);
        $this->assertStringNotContainsString('Realisasi Volume RO dan Progress Pelaksanaan Kegiatan', $xml);
    }

    /**
     * TTD (Kepala Satker/Notulis) dan header rapat (hari/waktu/tempat/pimpinan) harus
     * benar-benar tercetak di .docx begitu diisi lewat Detail Rapat -- bukan cuma "…".
     */
    public function test_docx_bagian1_mencetak_detail_rapat_dan_ttd(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $notula = Notula::create([
            'periode_id' => $periode->id,
            'hari_tanggal' => 'Senin, 12 Oktober 2026',
            'waktu' => '09.00 - 11.00 WITA',
            'tempat' => 'Ruang Rapat Uji',
            'pimpinan_rapat' => 'Budi Pimpinan',
            'notulis' => 'Sri Notulis',
            'kepala_satker' => 'Andi Kepala',
            'kota_ttd' => 'Kulisusu',
        ]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('Senin, 12 Oktober 2026', $xml);
        $this->assertStringContainsString('09.00 - 11.00 WITA', $xml);
        $this->assertStringContainsString('Ruang Rapat Uji', $xml);
        $this->assertStringContainsString('Budi Pimpinan', $xml);
        $this->assertStringContainsString('Sri Notulis', $xml);
        $this->assertStringContainsString('Andi Kepala', $xml);
        $this->assertStringContainsString('Kulisusu', $xml);
    }
}
