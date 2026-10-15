<?php

namespace Tests\Feature;

use App\Models\Capaian;
use App\Models\CapaianTahunan;
use App\Models\FolderConfig;
use App\Models\IndikatorProksi;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\Periode;
use App\Models\RincianN;
use App\Models\Role;
use App\Models\RtlEvaluasi;
use App\Models\User;
use App\Services\FolderStructureService;
use App\Services\LibreOfficeConversionService;
use App\Services\NotulaBagian1DocxService;
use App\Services\NotulaService;
use App\Support\RumusMarkup;
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

    /**
     * Sejak NotulaService::kumpulkanDataBagianSatu() memfilter Bagian I hanya untuk IKU
     * yang Capaian::status-nya sudah "diverifikasi"/"disetujui", fixture di bawah perlu
     * baris Capaian ini secara eksplisit -- sebelumnya cukup Kegiatan::STATUS_DIVERIFIKASI
     * saja karena seluruh Sasaran/IKU resmi selalu tampil sebagai kerangka baku.
     */
    protected function verifikasiCapaian(MasterIku $iku, Periode $periode): void
    {
        Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIVERIFIKASI]);
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

    /**
     * Berkas bagian2_pdf (hasil konversi LibreOffice saat unggah) bisa saja hilang dari
     * disk (mis. storage sempat dibersihkan/direset) walau kolom bagian2_html di DB
     * masih utuh -- pratinjau harus tetap bisa dibuka (dirender ulang dari HTML lewat
     * dompdf), BUKAN 404, karena isinya sendiri sebenarnya masih valid.
     */
    public function test_pratinjau_bagian2_dirender_ulang_dari_html_bila_berkas_pdf_sudah_hilang_dari_disk(): void
    {
        Storage::fake('local');
        $this->loginSebagai('Tim SAKIP');

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $notula = Notula::create([
            'periode_id' => $periode->id,
            // Menunjuk ke berkas yang TIDAK PERNAH dibuat di disk fake ini -- mensimulasikan
            // berkas hilang walau kolomnya masih terisi.
            'bagian2_pdf' => "notula/{$periode->id}/bagian2-hilang.pdf",
            'bagian2_html' => '<p>Konten Bagian II tersimpan</p>',
        ]);

        $this->get(route('notula.pratinjau-bagian2', $notula))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
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

        $iku = MasterIku::create([
            'kode' => '1001',
            'indikator' => 'Persentase Publikasi Statistik Uji',
            'tim' => 'Tim Uji',
            'penanggung_jawab' => 'Uji',
            'sasaran' => 'Sasaran Uji Kode Baru',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);
        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('1001', $xml);
        $this->assertStringContainsString('Sasaran Uji Kode Baru', $xml);
        $this->assertStringContainsString('Persentase Publikasi Statistik Uji', $xml);
        // Tidak boleh ada lagi macro mentah yang tersisa (tanda template gagal terisi).
        $this->assertStringNotContainsString('{{sasaran}}', $xml);
        $this->assertStringNotContainsString('{{kode}}', $xml);
        $this->assertStringNotContainsString('{{indikator}}', $xml);
        // IKU ini bukan bersatuan Persen -- paragraf rumus (blok_formula) harus dibuang
        // TOTAL (bukan cuma dikosongkan jadi "…"), tidak menyisakan macro mentah.
        $this->assertStringNotContainsString('{{blok_formula}}', $xml);
        $this->assertStringNotContainsString('{{/blok_formula}}', $xml);
        $this->assertStringNotContainsString('{{formula_capaian}}', $xml);
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
        $this->verifikasiCapaian($iku, $periode);

        $kegiatan = Kegiatan::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan uji RO docx',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        // Satu Kegiatan boleh punya lebih dari satu RO (RF baru) -- keduanya harus
        // sama-sama muncul di unduhan, bukan cuma yang pertama.
        $kegiatan->rincianOutput()->create(['uraian' => 'Publikasi Uji RO Docx', 'volume_ro' => '5 publikasi', 'progres_persen' => 60]);
        $kegiatan->rincianOutput()->create(['uraian' => 'Laporan Uji RO Docx Kedua', 'volume_ro' => '2 laporan', 'progres_persen' => 40]);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('Publikasi Uji RO Docx', $xml);
        $this->assertStringContainsString('5 publikasi', $xml);
        $this->assertStringContainsString('60,00%', $xml);
        $this->assertStringContainsString('Laporan Uji RO Docx Kedua', $xml);
        $this->assertStringContainsString('2 laporan', $xml);
        $this->assertStringContainsString('40,00%', $xml);
    }

    /**
     * Realisasi Volume RO hanya boleh tercetak bila IKU-nya BENAR-BENAR punya RO
     * terisi -- sebelumnya tetap tercetak (satu baris placeholder "...") walau IKU
     * ini belum punya RincianOutput sama sekali.
     */
    public function test_docx_bagian1_tidak_menampilkan_tabel_ro_bila_belum_ada_ro_terisi(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '2003', 'indikator' => 'Indikator Uji Tanpa RO', 'tim' => 'Tim Uji', 'penanggung_jawab' => 'Uji', 'sasaran' => 'Sasaran Uji RO',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        Kegiatan::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan uji tanpa RO sama sekali',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringNotContainsString('Realisasi Volume RO', $xml);
    }

    /**
     * RO tanpa nama sendiri (uraian kosong) harus jatuh ke uraian_kegiatan induknya
     * sebagai nama tampilan, bukan dikosongkan.
     */
    public function test_docx_bagian1_ro_jatuh_ke_uraian_kegiatan_bila_uraian_ro_kosong(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '2004', 'indikator' => 'Indikator Uji RO Fallback', 'tim' => 'Tim Uji', 'penanggung_jawab' => 'Uji', 'sasaran' => 'Sasaran Uji RO',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        $kegiatan = Kegiatan::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan uji fallback nama RO',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        $kegiatan->rincianOutput()->create(['volume_ro' => '1 publikasi', 'progres_persen' => 50]);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('Kegiatan uji fallback nama RO', $xml);
    }

    /**
     * Analisis Capaian Kinerja harus diikuti daftar bernomor kegiatan pendukung IKU
     * ini pada triwulan berjalan yang sudah diverifikasi/disetujui (RF-37).
     */
    public function test_docx_bagian1_analisis_capaian_menampilkan_daftar_kegiatan_terverifikasi(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '2005', 'indikator' => 'Indikator Uji Daftar Kegiatan', 'tim' => 'Tim Uji', 'penanggung_jawab' => 'Uji', 'sasaran' => 'Sasaran Uji RO',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        Kegiatan::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Pelaksanaan Sakernas Agustus 2026 terverifikasi',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('Pelaksanaan Sakernas Agustus 2026 terverifikasi', $xml);
    }

    /**
     * Regresi: notula selalu memakai periode BULAN PERTAMA triwulan (lihat
     * NotulaService::untukTriwulan()), tapi Capaian bisa tersimpan di periode bulan
     * LAIN dalam triwulan yang sama (bulan Kegiatan-nya sendiri diajukan) --
     * analisis_capaian harus tetap tampil walau Capaian-nya bukan milik periode
     * bulan pertama itu.
     */
    public function test_docx_bagian1_analisis_capaian_tampil_walau_capaian_di_bulan_kedua_triwulan(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '2006', 'indikator' => 'Indikator Uji Analisis Bulan Kedua', 'tim' => 'Tim Uji', 'penanggung_jawab' => 'Uji', 'sasaran' => 'Sasaran Uji RO',
        ]);

        // Notula Triwulan III 2026 memakai periode Juli (bulan pertama), tapi Kegiatan
        // & Capaian IKU ini baru diajukan/diverifikasi di Agustus (bulan kedua).
        $periodeJuli = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $periodeAgustus = Periode::create(['tahun' => 2026, 'bulan' => 8, 'triwulan' => 3, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);

        Capaian::create([
            'iku_id' => $iku->id,
            'periode_id' => $periodeAgustus->id,
            'status' => Capaian::STATUS_DIVERIFIKASI,
            'analisis_capaian' => 'Narasi analisis capaian uji bulan Agustus',
        ]);

        Kegiatan::create([
            'iku_id' => $iku->id,
            'periode_id' => $periodeAgustus->id,
            'uraian_kegiatan' => 'Kegiatan uji bulan Agustus',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        $notula = Notula::create(['periode_id' => $periodeJuli->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('Narasi analisis capaian uji bulan Agustus', $xml);
    }

    /**
     * Kendala/Solusi harus dicetak sebagai daftar bernomor yang pindah baris SETELAH
     * label ("Kendala :"), bukan menempel di baris yang sama ("Kendala : 1. ...") --
     * NotulaBagian1DocxService::set() sebelumnya memakai trim() yang membuang newline
     * pembuka dari daftarBernomor(), jadi poin 1 selalu menempel ke label walau poin
     * berikutnya sudah pindah baris.
     */
    public function test_docx_bagian1_kendala_solusi_poin_pertama_pindah_baris_dari_label(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '2007', 'indikator' => 'Indikator Uji Kendala Solusi', 'tim' => 'Tim Uji', 'penanggung_jawab' => 'Uji', 'sasaran' => 'Sasaran Uji RO',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        Kegiatan::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan uji kendala solusi',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        KendalaSolusi::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'kendala' => 'Kendala pertama uji',
            'solusi' => 'Solusi pertama uji',
        ]);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        // "Kendala :" diikuti <w:br/> (pindah baris) baru "1. Kendala pertama uji" --
        // BUKAN "Kendala : 1. Kendala pertama uji" menempel di baris yang sama.
        $this->assertMatchesRegularExpression('/Kendala :(?:(?!<\/w:p>|1\. Kendala).)*<w:br\/><w:t>1\. Kendala pertama uji/s', $xml);
        $this->assertMatchesRegularExpression('/Solusi :(?:(?!<\/w:p>|1\. Solusi).)*<w:br\/><w:t>1\. Solusi pertama uji/s', $xml);
    }

    /**
     * Warna teks isian di Bagian I HARUS hitam (000000), KECUALI dua tautan bukti
     * dukung (link folder Drive) yang tetap biru (1F4E79) supaya masih kelihatan
     * sebagai tautan -- sebelumnya SEMUA isian (termasuk yang bukan tautan sama
     * sekali, mis. Kendala/Solusi/Dasar Hitung) ikut biru.
     */
    public function test_docx_bagian1_warna_isian_hitam_kecuali_tautan(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '2008', 'indikator' => 'Indikator Uji Warna Font', 'tim' => 'Tim Uji', 'penanggung_jawab' => 'Uji', 'sasaran' => 'Sasaran Uji RO',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        Kegiatan::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan uji warna font',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        // Kedua baris "Tautan Bukti Dukung ...:" tetap biru (1F4E79) -- placeholder
        // "…" pun tetap biru karena warnanya atribut tetap template, bukan tergantung
        // ada/tidaknya link sungguhan.
        // (Teks label statis lain boleh saja berwarna sesuai desain template; yang diuji
        // di sini isian datanya.)
        // Run isian boleh punya properti lain (mis. <w:lang/>) setelah <w:color/>-nya.
        $warna = fn (string $hex) => '<w:color w:val="'.$hex.'"\/>(?:(?!<\/w:rPr>).)*<\/w:rPr><w:t[^>]*>';
        $this->assertMatchesRegularExpression('/Tautan Bukti Dukung Realisasi Target :.*?'.$warna('1F4E79').'…<\/w:t>/su', $xml);
        // Isian bukan tautan (Sasaran, Kendala, Solusi) harus hitam.
        $this->assertMatchesRegularExpression('/'.$warna('000000').'Sasaran Uji RO<\/w:t>/su', $xml);
        $this->assertMatchesRegularExpression('/Kendala :(?:(?!<\/w:p>).)*'.$warna('000000').'…<\/w:t>/su', $xml);
        $this->assertMatchesRegularExpression('/Solusi :(?:(?!<\/w:p>).)*'.$warna('000000').'…<\/w:t>/su', $xml);
    }

    /**
     * Indikator BerAKHLAK tidak lagi punya narasi khusus di template bawaan (blok_berakhlak
     * dihapus -- proksi kini berlaku untuk SEMUA IKU), dan tabel Indikator Proksi TIDAK
     * muncul hanya karena indikatornya berteks "SAKIP" -- proksi isian Tim SAKIP sendiri
     * (lihat uji di bawah).
     */
    public function test_docx_bagian1_berakhlak_format_khusus_dan_sakip_tanpa_proksi_tidak_mencetak_tabel_proksi(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $ikuSakip = MasterIku::create(['kode' => '9008', 'indikator' => 'Nilai SAKIP oleh Inspektorat', 'sasaran' => 'Dukungan Manajemen', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $ikuBerakhlak = MasterIku::create(['kode' => '9009', 'indikator' => 'Indeks Implementasi BerAKHLAK', 'sasaran' => 'Dukungan Manajemen', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($ikuSakip, $periode);
        $this->verifikasiCapaian($ikuBerakhlak, $periode);
        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringNotContainsString('Indikator Proksi', $xml);
        $this->assertDoesNotMatchRegularExpression('/\{\{\/?(blok_proksi|blok_sakip|proksi_|target_proksi|realisasi_proksi)/', $xml);
        $this->assertStringNotContainsString('Jelaskan mengenai Persentase kegiatan untuk mengoptimalkan implementasi BerAKHLAK', $xml);
        $this->assertStringNotContainsString('blok_berakhlak', $xml);
        $this->assertStringNotContainsString('Realisasi Volume RO dan Progress Pelaksanaan Kegiatan', $xml);
    }

    /**
     * Indikator Proksi isian Tim SAKIP dicetak untuk IKU APA PUN (bukan hanya SAKIP):
     * satu baris tabel per proksi, hanya pada IKU pemiliknya & triwulan yang sama.
     */
    public function test_docx_bagian1_mencetak_baris_proksi_per_indikator_proksi_untuk_iku_apa_pun(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $ikuProksi = MasterIku::create(['kode' => '9010', 'indikator' => 'Persentase Publikasi Berkualitas', 'sasaran' => 'Sasaran Uji Proksi', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $ikuLain = MasterIku::create(['kode' => '9011', 'indikator' => 'Indikator Tanpa Proksi', 'sasaran' => 'Sasaran Uji Proksi', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($ikuProksi, $periode);
        $this->verifikasiCapaian($ikuLain, $periode);
        IndikatorProksi::create(['iku_id' => $ikuProksi->id, 'tahun' => 2026, 'triwulan' => 3, 'uraian' => 'Proksi Satu & Dua', 'target' => '100%', 'realisasi' => '75%']);
        IndikatorProksi::create(['iku_id' => $ikuProksi->id, 'tahun' => 2026, 'triwulan' => 3, 'uraian' => 'Proksi Kedua', 'target' => '4 laporan', 'realisasi' => null]);
        // Triwulan lain -- tidak boleh ikut tercetak.
        IndikatorProksi::create(['iku_id' => $ikuProksi->id, 'tahun' => 2026, 'triwulan' => 2, 'uraian' => 'Proksi TW Lalu', 'target' => '1', 'realisasi' => '1']);
        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        // Satu tabel proksi saja (judul kolom "Indikator Proksi"), hanya untuk IKU pemiliknya.
        $this->assertSame(1, substr_count(strip_tags($xml), 'Indikator Proksi'));
        $this->assertStringContainsString('Proksi Satu &amp; Dua', $xml);
        $this->assertStringContainsString('75%', $xml);
        $this->assertStringContainsString('Proksi Kedua', $xml);
        $this->assertStringContainsString('4 laporan', $xml);
        $this->assertStringNotContainsString('Proksi TW Lalu', $xml);
        $this->assertDoesNotMatchRegularExpression('/\{\{\/?(blok_proksi|blok_sakip|proksi_|target_proksi|realisasi_proksi)/', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'document.xml hasil harus XML yang sah');
    }

    /**
     * IKU bersatuan Persen: "Dasar Hitung" harus dirakit otomatis jadi "y = n/N x 100%"
     * dari Deskripsi X/Y + data CapaianTahunan -- n = KUMULATIF x_realisasi TW I s.d.
     * TW berjalan (sama dengan Realisasi Kumulatif di Verifikasi Capaian), N = Target
     * Tahunan Y (konstan). Kolom dasar_hitung manual (kalau diisi) tetap tampil sebagai
     * keterangan tambahan setelah rumus otomatis, bukan digantikan.
     */
    public function test_docx_bagian1_dasar_hitung_persen_otomatis_pakai_realisasi_kumulatif(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '3001', 'indikator' => 'Persentase Uji Dasar Hitung', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'sasaran' => 'Sasaran Uji Dasar Hitung', 'satuan' => 'Persen', 'metode_capaian' => MasterIku::METODE_RASIO,
            'deskripsi_x' => 'Jumlah Publikasi Berkualitas', 'deskripsi_y' => 'Jumlah Seluruh Publikasi',
            'dasar_hitung' => 'Target 2026: N = 8 mencakup: Laporan A, Laporan B.',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        // TW I mentah = 5, TW II mentah = 2 -- rumus HARUS pakai 7 (kumulatif
        // TW I+II), bukan 2 (mentah TW II saja).
        CapaianTahunan::create([
            'iku_id' => $iku->id, 'tahun' => 2026, 'y_alokasi_tw4' => 8,
            'x_realisasi_tw1' => 5, 'x_realisasi_tw2' => 2,
        ]);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        // setFormula() "memutus" <w:r>/<w:t> yang tadinya membungkus macro untuk
        // menyisipkan <m:oMath> sebagai sibling -- document.xml HASIL AKHIRNYA tetap
        // harus well-formed XML, bukan cuma potongan substring yang kebetulan cocok.
        libxml_use_internal_errors(true);
        $valid = (new \DOMDocument)->loadXML($xml);
        $this->assertTrue($valid, 'document.xml Bagian I harus tetap well-formed XML setelah rumus disisipkan sebagai OOXML Math.');

        // Rumus dirakit jadi pecahan bersusun SUNGGUHAN (OOXML Math <m:f>, lihat
        // App\Support\RumusMarkup::keOmml()) -- pembilang/penyebutnya elemen XML
        // terpisah, BUKAN notasi teks "7/8" lagi. Angkanya bilangan bulat, bukan "7.00".
        $this->assertStringContainsString('<m:t xml:space="preserve">7</m:t>', $xml);
        $this->assertStringContainsString('<m:t xml:space="preserve">8</m:t>', $xml);
        $this->assertStringNotContainsString('<m:t xml:space="preserve">2</m:t>', $xml);
        $this->assertStringNotContainsString('7.00', $xml);
        $this->assertStringContainsString('Jumlah Publikasi Berkualitas', $xml);
        $this->assertStringContainsString('Jumlah Seluruh Publikasi', $xml);
        $this->assertStringContainsString('Target 2026: N = 8 mencakup: Laporan A, Laporan B.', $xml);
        // Baris rumus ("y = n/N x 100% = 2.00/8.00 x 100%") dicetak sebagai paragraf
        // TERSENDIRI rata tengah (blok_formula/{{formula_capaian}}), terpisah dari
        // "dimana:.../rincian" yang tetap rata kiri -- posisi paragraf rata-tengah
        // ("<w:jc w:val="center"/>") harus MUNCUL SEBELUM elemen <m:oMath> rumusnya.
        $posisiRataTengah = strpos($xml, '<w:jc w:val="center"/>', strpos($xml, 'Dasar Hitung dan Basis Data Realisasi IKU'));
        $posisiRumus = strpos($xml, '<m:oMath>');
        $this->assertNotFalse($posisiRataTengah);
        $this->assertNotFalse($posisiRumus);
        $this->assertTrue($posisiRataTengah < $posisiRumus, 'Paragraf rumus harus rata tengah (marker jc=center sebelum elemen <m:oMath>).');
    }

    /**
     * Jalur pratinjau HTML: LibreOffice membuang <m:oMath> saat ekspor ke HTML, jadi
     * docx untuk pratinjau memakai teks penanda di posisi rumus (diganti HTML bersusun
     * setelah konversi, lihat NotulaService::gantiPenandaRumusDenganHtml()).
     */
    public function test_docx_bagian1_untuk_pratinjau_memakai_penanda_rumus_bukan_omml(): void
    {
        $iku = MasterIku::create([
            'kode' => '3002', 'indikator' => 'Persentase Uji Penanda', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'sasaran' => 'Sasaran Uji Penanda', 'satuan' => 'Persen', 'metode_capaian' => MasterIku::METODE_RASIO,
            'deskripsi_x' => 'X', 'deskripsi_y' => 'Y',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);
        CapaianTahunan::create(['iku_id' => $iku->id, 'tahun' => 2026, 'y_alokasi_tw4' => 8, 'x_realisasi_tw2' => 2]);

        $notula = Notula::create(['periode_id' => $periode->id]);
        $docx = app(NotulaBagian1DocxService::class);
        $path = tempnam(sys_get_temp_dir(), 'penanda').'.docx';

        try {
            $docx->generate($notula, app(NotulaService::class)->kumpulkanDataBagianSatu($notula), $path, sertakanBlokTtdMandiri: false, untukPratinjauHtml: true);

            $zip = new ZipArchive;
            $zip->open($path);
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            @unlink($path);
        }

        $this->assertCount(1, $docx->formulaUrutanTerakhir());
        $this->assertStringContainsString(NotulaBagian1DocxService::penandaRumus(0), $xml);
        $this->assertStringNotContainsString('<m:oMath>', $xml);
        $this->assertStringContainsString(NotulaBagian1DocxService::penandaBlok('iku-'.$iku->id), $xml);
        $this->assertStringContainsString(NotulaBagian1DocxService::penandaBlok('ekor'), $xml);
    }

    /**
     * Bagian I yang sudah disusun & disunting di pratinjau: unduhan .docx dibangun dari
     * bagian1_html tersimpan (sumber yang sama dengan PDF), BUKAN disusun ulang dari
     * data -- teks & angka rumus hasil suntingan ikut, rumusnya tetap OOXML Math.
     * LibreOffice dipalsukan: HTML yang dikirim ke konversi diperiksa, lalu dibalas
     * .docx minimal berisi teks yang sama supaya pasca-proses rumusnya teruji.
     */
    public function test_docx_bagian1_mengikuti_suntingan_pratinjau(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $rumusHtml = str_replace('>2<', '>5<', RumusMarkup::keHtml('y = [[n|N]] × 100% = [[2|8]] × 100%'));
        $notula = Notula::create([
            'periode_id' => $periode->id,
            'bagian1_html' => '<style>.notula-inline-ABC p.western { font-family: "Arial" }</style><div class="notula-inline notula-inline-ABC">'
                .'<p class="western">Analisis hasil suntingan Tim SAKIP</p>'
                .'<p class="western" style="text-align:center"><span class="rumus">'.$rumusHtml.'</span></p>'
                .'<p><i>{{bagian_2}}</i></p></div>',
        ]);

        $this->mock(LibreOfficeConversionService::class, function ($mock) {
            $mock->shouldReceive('convertHtmlToDocx')->once()->andReturnUsing(function (string $htmlPath, string $dir) {
                $html = file_get_contents($htmlPath);
                $this->assertStringContainsString('Analisis hasil suntingan Tim SAKIP', $html);
                $this->assertStringContainsString('font-family: "Arial"', $html);
                $this->assertStringNotContainsString('{{bagian_2}}', $html);

                $penanda = NotulaBagian1DocxService::penandaRumus(0);
                $docx = $dir.'/palsu-'.uniqid().'.docx';
                $zip = new ZipArchive;
                $zip->open($docx, ZipArchive::CREATE);
                $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
                    .'<w:p><w:r><w:t>Analisis hasil suntingan Tim SAKIP</w:t></w:r></w:p>'
                    .'<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>'.$penanda.'</w:t></w:r></w:p>'
                    .'<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr></w:body></w:document>');
                $zip->close();

                return $docx;
            });
        });

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        libxml_use_internal_errors(true);
        $this->assertTrue((new \DOMDocument)->loadXML($xml), 'document.xml harus tetap well-formed setelah rumus disisipkan.');
        $this->assertStringContainsString('Analisis hasil suntingan Tim SAKIP', $xml);
        $this->assertStringContainsString('<m:oMath>', $xml);
        $this->assertStringContainsString('<m:t xml:space="preserve">5</m:t>', $xml);
        $this->assertStringContainsString('<m:t xml:space="preserve">8</m:t>', $xml);
        $this->assertStringNotContainsString('SIPINTERRUMUS', $xml);
        $this->assertStringContainsString('<w:pgSz w:w="11906" w:h="16838"/>', $xml);
    }

    /**
     * IKU bersatuan Poin (mis. IPP) tidak punya rumus otomatis (formulaBaris() null
     * karena satuan-nya bukan Persen) -- kalau Tim SAKIP menuliskan sintaks
     * RumusMarkup di BARIS PERTAMA kolom "Dasar Hitung", baris itu yang dipakai
     * sebagai rumus bersusun (lihat NotulaBagian1DocxService::formulaKustomDariDasarHitung()),
     * termasuk sintaks sigma [[SUM:batas_bawah,batas_atas|suku]] baru untuk rumus
     * seperti IPP = (w1 x sigma xi) + (w2 x sigma yi). Baris KEDUA dst. tetap tercetak
     * sebagai keterangan biasa, TIDAK dobel dengan rumusnya.
     */
    public function test_docx_bagian1_dasar_hitung_poin_baris_pertama_jadi_rumus_sigma_bersusun(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '3002', 'indikator' => 'Indeks Uji Sigma', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'sasaran' => 'Sasaran Uji Sigma', 'satuan' => 'Poin', 'metode_capaian' => MasterIku::METODE_LANGSUNG,
            'dasar_hitung' => "IPP = (w1 x [[SUM:i=1,n|xi]]) + (w2 x [[SUM:i=1,m|yi]])\nKeterangan tambahan baris kedua.",
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        libxml_use_internal_errors(true);
        $valid = (new \DOMDocument)->loadXML($xml);
        $this->assertTrue($valid, 'document.xml Bagian I harus tetap well-formed XML setelah rumus sigma disisipkan sebagai OOXML Math.');

        // Rumusnya dirakit jadi <m:nary> SUNGGUHAN (batas bawah/atas bersusun), bukan
        // diratakan jadi teks "Σ(i=1..n) xi" biasa seperti dasar_hitung lain.
        $this->assertStringContainsString('<m:nary>', $xml);
        $this->assertStringContainsString('<m:sub><m:r><m:t xml:space="preserve">i=1</m:t></m:r></m:sub>', $xml);
        $this->assertStringContainsString('<m:sup><m:r><m:t xml:space="preserve">n</m:t></m:r></m:sup>', $xml);
        $this->assertStringContainsString('<m:sup><m:r><m:t xml:space="preserve">m</m:t></m:r></m:sup>', $xml);

        // Baris kedua tetap tercetak sebagai keterangan biasa (rata kiri) -- TIDAK
        // dobel dengan sintaks "[[SUM:...]]" mentahnya.
        $this->assertStringContainsString('Keterangan tambahan baris kedua.', $xml);
        $this->assertStringNotContainsString('[[SUM:', $xml);
        $this->assertStringNotContainsString('SUM:i=1,n', $xml);
    }

    /**
     * IKU bermetode Rasio (MasterIku::pakaiRasio(), yang kini SELALU memakai
     * Rincian N): Dasar Hitung .docx harus otomatis mencantumkan rincian NYATA n
     * (item yang triwulan_realisasi-nya = triwulan notula ini) & N (SELURUH item
     * tahun itu) dari App\Models\RincianN, bukan lagi mengharuskan Tim SAKIP
     * mengetiknya manual ke kolom dasar_hitung.
     */
    public function test_docx_bagian1_dasar_hitung_rincian_n_dicantumkan_otomatis(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '3009', 'indikator' => 'Persentase Uji Rincian N', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'sasaran' => 'Sasaran Uji Rincian N', 'satuan' => 'Persen', 'metode_capaian' => MasterIku::METODE_RASIO,
            'deskripsi_x' => 'Jumlah Publikasi Berkualitas', 'deskripsi_y' => 'Jumlah Seluruh Publikasi',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 5, 'triwulan' => 2, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        CapaianTahunan::create([
            'iku_id' => $iku->id, 'tahun' => 2026, 'y_alokasi_tw4' => 4,
            'x_realisasi_tw2' => 1,
        ]);

        // 4 item total tahun ini (N), hanya 1 direalisasikan TEPAT di TW II (n) --
        // sisanya belum direalisasikan sama sekali, TIDAK ikut dihitung sebagai n
        // walau tetap ikut menghitung N.
        RincianN::create(['iku_id' => $iku->id, 'tahun' => 2026, 'uraian' => 'Laporan Kegiatan Susenas Maret 2026', 'triwulan_realisasi' => 2]);
        RincianN::create(['iku_id' => $iku->id, 'tahun' => 2026, 'uraian' => 'Publikasi Statistik Kesejahteraan Rakyat 2026']);
        RincianN::create(['iku_id' => $iku->id, 'tahun' => 2026, 'uraian' => 'Laporan Kegiatan Susenas September 2026']);
        RincianN::create(['iku_id' => $iku->id, 'tahun' => 2026, 'uraian' => 'Laporan Kegiatan Seruti 2026']);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('n = 1 mencakup:', $xml);
        $this->assertStringContainsString('Laporan Kegiatan Susenas Maret 2026', $xml);
        $this->assertStringContainsString('N = 4 mencakup:', $xml);
        $this->assertStringContainsString('Publikasi Statistik Kesejahteraan Rakyat 2026', $xml);
        $this->assertStringContainsString('Laporan Kegiatan Susenas September 2026', $xml);
        $this->assertStringContainsString('Laporan Kegiatan Seruti 2026', $xml);
    }

    /**
     * Baris "n = 0 (belum ada item yang direalisasikan triwulan ini)" DIHILANGKAN
     * SAMA SEKALI (bukan dicetak "n = 0 ...") bila belum ada satu pun item RincianN
     * yang triwulan_realisasi-nya <= triwulan notula ini -- langsung lompat ke "N = ...
     * mencakup:", sesuai contoh dokumen resmi.
     */
    public function test_docx_bagian1_dasar_hitung_rincian_n_tidak_menampilkan_baris_n_bila_belum_ada_realisasi(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '3010', 'indikator' => 'Persentase Uji Rincian N Kosong', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'sasaran' => 'Sasaran Uji Rincian N Kosong', 'satuan' => 'Persen', 'metode_capaian' => MasterIku::METODE_RASIO,
            'deskripsi_x' => 'Jumlah Publikasi Berkualitas', 'deskripsi_y' => 'Jumlah Seluruh Publikasi',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        CapaianTahunan::create([
            'iku_id' => $iku->id, 'tahun' => 2026, 'y_alokasi_tw4' => 4,
            'x_realisasi_tw2' => 0,
        ]);

        // Belum satu pun item RincianN yang triwulan_realisasi-nya = triwulan berjalan (TW II).
        RincianN::create(['iku_id' => $iku->id, 'tahun' => 2026, 'uraian' => 'Publikasi Statistik Kesejahteraan Rakyat 2026']);
        RincianN::create(['iku_id' => $iku->id, 'tahun' => 2026, 'uraian' => 'Laporan Kegiatan Susenas Maret 2026']);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringNotContainsString('n = 0', $xml);
        $this->assertStringContainsString('N = 2 mencakup:', $xml);
        $this->assertStringContainsString('Publikasi Statistik Kesejahteraan Rakyat 2026', $xml);
    }

    /**
     * Rincian n KUMULATIF: item yang direalisasikan di TW sebelumnya tetap tercetak
     * di "n = ... mencakup:" notula TW berikutnya (sama seperti daftar "item kumulatif
     * s.d. TW ini" di Verifikasi Capaian) -- walau di TW berjalan tidak ada item baru.
     */
    public function test_docx_bagian1_dasar_hitung_rincian_n_kumulatif_dari_tw_sebelumnya(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '3011', 'indikator' => 'Persentase Uji Rincian N Kumulatif', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'sasaran' => 'Sasaran Uji Rincian N Kumulatif', 'satuan' => 'Persen', 'metode_capaian' => MasterIku::METODE_RASIO,
            'deskripsi_x' => 'Jumlah Desa Cinta Statistik', 'deskripsi_y' => 'Jumlah Desa Setara',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 6, 'triwulan' => 2, 'bulan_ke' => 3, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        CapaianTahunan::create([
            'iku_id' => $iku->id, 'tahun' => 2026, 'y_alokasi_tw4' => 3,
            'x_realisasi_tw1' => 2, 'x_realisasi_tw2' => 0,
        ]);

        RincianN::create(['iku_id' => $iku->id, 'tahun' => 2026, 'uraian' => 'Desa Linsowu (2021)', 'triwulan_realisasi' => 1]);
        RincianN::create(['iku_id' => $iku->id, 'tahun' => 2026, 'uraian' => 'Desa Kalibu (2022)', 'triwulan_realisasi' => 1]);
        RincianN::create(['iku_id' => $iku->id, 'tahun' => 2026, 'uraian' => 'Desa Laangke (2026)']);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('n = 2 mencakup:', $xml);
        $this->assertStringContainsString('N = 3 mencakup:', $xml);
        $this->assertStringContainsString('<m:t xml:space="preserve">2</m:t>', $xml);
        $this->assertStringContainsString('<m:t xml:space="preserve">3</m:t>', $xml);
        $this->assertStringNotContainsString('<m:t xml:space="preserve">0</m:t>', $xml);
    }

    /**
     * Unduhan .docx = notula LENGKAP: Bagian I dari pratinjau tersimpan, Bagian II dari
     * .docx ASLI unggahan (gayanya dipertahankan, tidak lewat HTML), Bagian III yang
     * belum punya .docx asli (unggahan lama) dari HTML hasil konversinya.
     */
    public function test_unduh_docx_notula_lengkap_menyisipkan_bagian2_docx_asli_dan_bagian3_html_lama(): void
    {
        if (! is_file((string) config('services.libreoffice.binary_path'))) {
            $this->markTestSkipped('LibreOffice tidak terpasang.');
        }

        $this->loginSebagai('Tim SAKIP');
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        $bagian2 = tempnam(sys_get_temp_dir(), 'b2_').'.docx';
        $zip = new ZipArchive;
        $zip->open($bagian2, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('word/styles.xml', '<?xml version="1.0"?><w:styles '.$w.'><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr></w:style></w:styles>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document '.$w.'><w:body><w:p><w:r><w:t>Isi Bagian Dua Asli</w:t></w:r></w:p><w:sectPr><w:pgSz w:w="11906" w:h="16838"/></w:sectPr></w:body></w:document>');
        $zip->close();

        $notula = Notula::create([
            'periode_id' => $periode->id,
            'bagian1_html' => '<p>Isi Bagian Satu</p><p>{{bagian_2}}</p><p>{{bagian_3}}</p><p>Akhir Bagian Satu</p>',
            'bagian2_html' => '<p>HTML Bagian Dua (tidak dipakai)</p>',
            'bagian2_docx' => base64_encode((string) file_get_contents($bagian2)),
            'bagian3_html' => '<p>Isi Bagian Tiga Lama</p>',
        ]);
        @unlink($bagian2);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        libxml_use_internal_errors(true);
        $this->assertTrue((new \DOMDocument)->loadXML($xml), 'document.xml notula lengkap harus XML yang sah.');
        $teks = strip_tags(str_replace('</w:p>', "\n", $xml));
        $this->assertMatchesRegularExpression('/Isi Bagian Satu.*Isi Bagian Dua Asli.*Isi Bagian Tiga Lama.*Akhir Bagian Satu/s', $teks);
        $this->assertStringNotContainsString('HTML Bagian Dua', $teks);
        $this->assertStringNotContainsString('SIPINTERBAGIAN', $xml);
        $this->assertStringNotContainsString('{{bagian_', $teks);
    }

    /**
     * PDF notula dibuat dari .docx lengkap lewat LibreOffice (isi sama dengan unduhan
     * .docx); bila LibreOffice gagal, otomatis jatuh ke dompdf -- PDF tetap jadi.
     */
    public function test_pdf_notula_jatuh_ke_dompdf_bila_libreoffice_gagal(): void
    {
        config(['services.libreoffice.pdf_notula' => true]);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $notula = Notula::create(['periode_id' => $periode->id, 'bagian1_html' => '<p>Isi Bagian Satu</p>']);

        $this->mock(LibreOfficeConversionService::class, function ($mock) {
            $mock->shouldReceive('convertHtmlToDocx')->andThrow(new \RuntimeException('LibreOffice mati'));
        });

        $path = storage_path('app/private/uji-pdf-'.uniqid().'.pdf');
        app(NotulaService::class)->renderPratinjauPdf($notula, $path);

        $this->assertStringStartsWith('%PDF-', (string) file_get_contents($path));
        @unlink($path);
    }

    public function test_pdf_notula_dari_docx_lengkap_lewat_libreoffice(): void
    {
        if (! is_file((string) config('services.libreoffice.binary_path'))) {
            $this->markTestSkipped('LibreOffice tidak terpasang.');
        }
        config(['services.libreoffice.pdf_notula' => true]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $notula = Notula::create([
            'periode_id' => $periode->id,
            'bagian1_html' => '<p>Isi Bagian Satu</p><p>{{bagian_2}}</p>',
            'bagian2_html' => '<p>Isi Bagian Dua Lewat HTML</p>',
        ]);

        $path = storage_path('app/private/uji-pdf-'.uniqid().'.pdf');
        app(NotulaService::class)->renderPratinjauPdf($notula, $path);
        $pdf = (string) file_get_contents($path);
        @unlink($path);

        $this->assertStringStartsWith('%PDF-', $pdf);
        // LibreOffice menulis Producer-nya sendiri -- bukti PDF bukan dari dompdf.
        $this->assertStringContainsString('LibreOffice', $pdf);
    }

    /**
     * Link Lampiran Basis Data IKU berlaku per tahun: notula yang belum mengisinya
     * memakai link notula lain di tahun yang SAMA (bukan tahun lain).
     */
    public function test_link_lampiran_basis_data_kosong_memakai_link_triwulan_lain_tahun_sama(): void
    {
        $tw2 = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $tw3 = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $tahunLalu = Periode::create(['tahun' => 2025, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        Notula::create(['periode_id' => $tahunLalu->id, 'link_lampiran_basis_data' => 'https://contoh.test/2025']);
        $notulaTw2 = Notula::create(['periode_id' => $tw2->id]);

        $this->assertNull($notulaTw2->linkLampiranBasisDataEfektif());

        Notula::create(['periode_id' => $tw3->id, 'link_lampiran_basis_data' => 'https://contoh.test/2026']);
        $this->assertSame('https://contoh.test/2026', $notulaTw2->fresh()->linkLampiranBasisDataEfektif());

        $notulaTw2->update(['link_lampiran_basis_data' => 'https://contoh.test/khusus-tw2']);
        $this->assertSame('https://contoh.test/khusus-tw2', $notulaTw2->fresh()->linkLampiranBasisDataEfektif());
    }

    /**
     * Semua <w:t> hasil generate bertanda xml:space="preserve" -- macro yang dipecah Word
     * ("{{" | "tahun" | "}} ") disatukan PhpWord ke run pertama; tanpa preserve, spasi
     * setelah macro dibuang ("IKU 2026dapat dilihat").
     */
    public function test_docx_bagian1_spasi_setelah_macro_dipertahankan(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertNotSame('', $xml);
        $this->assertStringNotContainsString('<w:t>', $xml);
    }

    /**
     * Variabel tautan ({{lampiran_basis_data}}, {{bukti_realisasi}}, dst.) dicetak
     * sebagai HYPERLINK sungguhan (bisa diklik, biru bergaris bawah), bukan teks URL
     * polos -- dan "&" di URL tetap di-escape supaya document.xml tetap sah.
     */
    public function test_docx_bagian1_variabel_tautan_dicetak_sebagai_hyperlink(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '3012', 'indikator' => 'Indikator Uji Tautan', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'sasaran' => 'Sasaran Uji Tautan',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 6, 'triwulan' => 2, 'bulan_ke' => 3, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        Kegiatan::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'Kegiatan uji tautan',
            'jenis' => 'bukan_survei_sensus', 'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        $this->mock(FolderStructureService::class, function ($mock) {
            $mock->shouldReceive('linkKategoriIku', 'linkBuktiDukungIku')->andReturn('https://drive.google.com/drive/folders/iku-uji?usp=drive_link&x=1');
        });

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));
        libxml_use_internal_errors(true);
        $this->assertTrue((new \DOMDocument)->loadXML($xml), 'document.xml harus tetap well-formed setelah tautan disisipkan.');
        $this->assertStringContainsString(' HYPERLINK "https://drive.google.com/drive/folders/iku-uji?usp=drive_link&amp;x=1" ', $xml);
        $this->assertStringContainsString('<w:u w:val="single"/></w:rPr><w:t xml:space="preserve">https://drive.google.com/drive/folders/iku-uji?usp=drive_link&amp;x=1</w:t>', $xml);
    }

    /**
     * Realisasi Volume RO hanya boleh tercetak bila BENAR-BENAR ada RO yang SUDAH
     * direalisasikan (volume_ro atau progres_persen terisi) -- RincianOutput yang
     * baru dibuat (uraian saja, belum diisi realisasinya) TIDAK cukup untuk
     * menampilkan tabelnya; harus langsung lompat ke bagian berikutnya.
     */
    public function test_docx_bagian1_tidak_menampilkan_tabel_ro_bila_ro_ada_tapi_belum_direalisasikan(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '2009', 'indikator' => 'Indikator Uji RO Belum Direalisasikan', 'tim' => 'Tim Uji', 'penanggung_jawab' => 'Uji', 'sasaran' => 'Sasaran Uji RO',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        $kegiatan = Kegiatan::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan uji RO belum direalisasikan',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        $kegiatan->rincianOutput()->create(['uraian' => 'RO belum direalisasikan']);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringNotContainsString('Realisasi Volume RO', $xml);
    }

    /**
     * Setiap baris tabel (<w:tr>) di Bagian I .docx harus ditandai <w:cantSplit/> supaya
     * Word tidak memotong satu baris di tengah saat menyeberangi batas halaman -- baris
     * yang terpotong sebelumnya bikin nilai Realisasi/Progres tampak "lepas" dari
     * uraiannya sendiri (nyambung ke baris Kegiatan/Kendala-Solusi berikutnya).
     */
    public function test_docx_bagian1_setiap_baris_tabel_ditandai_cant_split(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '2010', 'indikator' => 'Indikator Uji CantSplit', 'tim' => 'Tim Uji', 'penanggung_jawab' => 'Uji', 'sasaran' => 'Sasaran Uji CantSplit',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        Kegiatan::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan uji cantSplit',
            'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        // <w:tr> bisa beratribut (w:rsidR, w14:paraId, ...) dan <w:trPr> bisa punya anak lain.
        $totalBaris = preg_match_all('/<w:tr(?: [^>]*)?>/', $xml);
        $totalCantSplit = preg_match_all('/<w:tr(?: [^>]*)?><w:trPr>(?:(?!<\/w:trPr>).)*<w:cantSplit\/>/s', $xml);

        $this->assertGreaterThan(0, $totalBaris);
        $this->assertSame($totalBaris, $totalCantSplit, 'Setiap <w:tr> harus punya <w:cantSplit/> di dalam <w:trPr>-nya.');
    }

    /**
     * PIC Tindak Lanjut pada .docx harus memakai tim yang ditugaskan pada IKU-nya di
     * Master IKU (master_iku.tim), BUKAN nama orang yang diketik bebas per poin RTL
     * (rtl_evaluasi.pic) -- penanggung jawab RTL selalu tim, bukan individu.
     */
    public function test_docx_bagian1_pic_tindak_lanjut_memakai_tim_master_iku_bukan_pic_rtl(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '3003',
            'indikator' => 'Indikator Uji PIC',
            'tim' => 'Tim III',
            'penanggung_jawab' => 'Uji',
            'sasaran' => 'Sasaran Uji PIC',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        RtlEvaluasi::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'rtl_teks' => 'RTL uji PIC',
            'pic' => 'Intan Purwanti',
            'batas_waktu' => '2026-09-30',
        ]);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('Tim III', $xml);
        $this->assertStringNotContainsString('Intan Purwanti', $xml);
    }

    /**
     * Satu IKU boleh ditugaskan ke lebih dari satu tim (App\Models\IkuTim) -- PIC
     * Tindak Lanjut pada .docx harus menggabungkan namanya dengan "dan", TANPA koma
     * bila cuma dua tim (lihat NotulaBagian1DocxService::gabungDenganDan()).
     */
    public function test_docx_bagian1_pic_tindak_lanjut_menggabungkan_dua_tim_dengan_dan_tanpa_koma(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '3011',
            'indikator' => 'Indikator Uji PIC Dua Tim',
            'tim' => 'Tim Satu, Tim Dua',
            'penanggung_jawab' => 'Uji',
            'sasaran' => 'Sasaran Uji PIC',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        RtlEvaluasi::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'rtl_teks' => 'RTL uji PIC dua tim',
            'pic' => 'Diabaikan',
            'batas_waktu' => '2026-09-30',
        ]);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        // Urutan tim SELALU alfabet (App\Models\MasterIku::timList()), jadi "Tim Dua"
        // tampil lebih dulu daripada "Tim Satu" meski diisi/diketik sebaliknya.
        $this->assertStringContainsString('Tim Dua dan Tim Satu', $xml);
        $this->assertStringNotContainsString('Tim Dua, dan Tim Satu', $xml);
    }

    /**
     * Tiga tim atau lebih HARUS dipisah koma di antaranya, baru "dan" sebelum nama
     * terakhir (mis. "Tim Dua, Tim Satu, dan Tim Tiga") -- beda dari kasus dua tim
     * di atas yang tanpa koma sama sekali.
     */
    public function test_docx_bagian1_pic_tindak_lanjut_menggabungkan_tiga_tim_dengan_koma_dan_dan(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '3012',
            'indikator' => 'Indikator Uji PIC Tiga Tim',
            'tim' => 'Tim Satu, Tim Dua, Tim Tiga',
            'penanggung_jawab' => 'Uji',
            'sasaran' => 'Sasaran Uji PIC',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        RtlEvaluasi::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'rtl_teks' => 'RTL uji PIC tiga tim',
            'pic' => 'Diabaikan',
            'batas_waktu' => '2026-09-30',
        ]);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        // Urutan alfabet: Tim Dua, Tim Satu, Tim Tiga.
        $this->assertStringContainsString('Tim Dua, Tim Satu, dan Tim Tiga', $xml);
    }

    /**
     * Batas Waktu Tindak Lanjut ({{batas_waktu_rtl_berikutnya}} di template bawaan) HARUS
     * selalu akhir bulan triwulan BERIKUTNYA yang jadi target RTL itu (RF-34, sesuai Kertas
     * Kerja resmi) -- dihitung dari triwulan/tahun periode, BUKAN dibaca apa adanya dari
     * kolom rtl_evaluasi.batas_waktu tersimpan (yang bisa salah kalau pernah diketik manual
     * sebelum field itu dikunci di form).
     */
    public function test_docx_bagian1_batas_waktu_rtl_selalu_akhir_triwulan_bukan_kolom_tersimpan(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '3004', 'indikator' => 'Indikator Uji Batas Waktu RTL', 'tim' => 'Uji',
            'penanggung_jawab' => 'Uji', 'sasaran' => 'Sasaran Uji Batas Waktu RTL',
        ]);

        // Notula Triwulan II 2026 -> RTL berikutnya untuk TW III, akhir triwulannya
        // September 2026 -- batas_waktu TERSIMPAN sengaja diisi tanggal yang SALAH
        // (Desember) untuk membuktikan kolom ini tidak lagi dipakai apa adanya.
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $periodeBerikutnya = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);

        RtlEvaluasi::create([
            'iku_id' => $iku->id,
            'periode_id' => $periodeBerikutnya->id,
            'rtl_teks' => 'RTL uji batas waktu',
            'pic' => 'Uji',
            'batas_waktu' => '2026-12-31',
            'status_dokumen' => RtlEvaluasi::STATUS_DIAJUKAN,
        ]);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('RTL uji batas waktu', $xml);
        $this->assertStringContainsString('September 2026', $xml);
        $this->assertStringNotContainsString('Desember 2026', $xml);
    }

    /**
     * Header rapat (hari/waktu/tempat/pimpinan) harus benar-benar tercetak di .docx
     * begitu diisi lewat Detail Rapat -- bukan cuma "…". Tabel TTD (Mengetahui/Kepala/
     * Notulis) SENGAJA tidak tercetak SAMA SEKALI di sini (notula masih draft, belum
     * disetujui Kepala) -- lihat test_docx_bagian1_mencetak_ttd_setelah_disetujui()
     * untuk kasus disetujui.
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
        $this->assertStringNotContainsString('Sri Notulis', $xml);
        $this->assertStringNotContainsString('Kulisusu', $xml);
        $this->assertStringNotContainsString('Andi Kepala', $xml);
        $this->assertStringNotContainsString('Mengetahui,', $xml);
    }

    /**
     * Tabel TTD (Mengetahui/Kepala/Notulis) hanya tercetak begitu notula berstatus
     * disetujui (RF-44) -- sebelum itu tabelnya dibuang total (lihat test di atas)
     * supaya draf tidak tampak sudah tinggal ditandatangani padahal baru isian form.
     * Tanggal "Mengetahui" yang menyertainya mengikuti hari_tanggal rapat, bukan
     * tanggal klik "Setuju" di sistem.
     */
    public function test_docx_bagian1_mencetak_ttd_setelah_disetujui(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $notula = Notula::create([
            'periode_id' => $periode->id,
            'hari_tanggal' => 'Senin, 12 Oktober 2026',
            'notulis' => 'Sri Notulis',
            'kepala_satker' => 'Andi Kepala',
            'kota_ttd' => 'Kulisusu',
            'status' => Notula::STATUS_DISETUJUI,
        ]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('Mengetahui,', $xml);
        $this->assertStringContainsString('Andi Kepala', $xml);
        $this->assertStringContainsString('Sri Notulis', $xml);
        $this->assertStringContainsString('Kulisusu', $xml);
        $this->assertStringContainsString('Senin, 12 Oktober 2026', $xml);
    }

    /**
     * "Unduh Pratinjau" harus tersedia kapan saja (mis. Bagian II/III belum diunggah)
     * TANPA syarat "semua bukti terverifikasi" seperti unduh-draf, dan TANPA efek
     * samping mengubah status notula/pdf_gabungan seperti gabungkan() -- murni
     * kenyamanan Tim SAKIP memeriksa hasil sewaktu masih menyusun.
     */
    public function test_pratinjau_cepat_pdf_tersedia_kapan_saja_tanpa_efek_samping(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $notula = Notula::create(['periode_id' => $periode->id]);

        $this->get(route('notula.pratinjau-cepat-pdf', $notula))
            ->assertOk()
            ->assertHeader('content-disposition');

        $notula->refresh();
        $this->assertSame(Notula::STATUS_DRAFT, $notula->status);
        $this->assertNull($notula->pdf_gabungan);
    }

    /**
     * Begitu Tim SAKIP mengunggah template baru lewat Pengaturan > Template Notula
     * (mis. format resmi berubah dari pusat), unduhan Bagian I harus memakai berkas
     * itu -- BUKAN berkas bawaan proyek -- tanpa perlu developer mengubah kode.
     */
    public function test_docx_bagian1_memakai_template_yang_diunggah_lewat_pengaturan(): void
    {
        Storage::fake('local');
        $this->loginSebagai('Tim SAKIP');

        $isiTemplateAsli = file_get_contents(base_path('template_notula/SIPINTER_Template_Bagian_I_Mesin.docx'));
        Storage::disk('local')->put('template-notula/custom.docx', $isiTemplateAsli);
        FolderConfig::current()->update([
            'template_notula_path' => 'template-notula/custom.docx',
            'template_notula_nama_asli' => 'custom.docx',
        ]);

        $iku = MasterIku::create([
            'kode' => '9999', 'indikator' => 'Indikator Uji Template Unggahan', 'sasaran' => 'Sasaran Uji Template Unggahan',
            'tim' => 'Uji', 'penanggung_jawab' => 'Uji',
        ]);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $this->verifikasiCapaian($iku, $periode);
        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('Sasaran Uji Template Unggahan', $xml);
    }
}
