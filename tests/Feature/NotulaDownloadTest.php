<?php

namespace Tests\Feature;

use App\Models\CapaianTahunan;
use App\Models\FolderConfig;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\Periode;
use App\Models\Role;
use App\Models\RtlEvaluasi;
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
     * IKU bersatuan Persen: "Dasar Hitung" harus dirakit otomatis jadi "y = n/N x 100%"
     * dari Deskripsi X/Y + data CapaianTahunan -- n = nilai MENTAH triwulan berjalan
     * saja (x_realisasi_twN, BUKAN kumulatif dari TW sebelumnya), N = Target Tahunan Y
     * (konstan). Kolom dasar_hitung manual (kalau diisi) tetap tampil sebagai keterangan
     * tambahan setelah rumus otomatis, bukan digantikan.
     */
    public function test_docx_bagian1_dasar_hitung_persen_otomatis_pakai_realisasi_mentah_bukan_kumulatif(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '3001', 'indikator' => 'Persentase Uji Dasar Hitung', 'tim' => 'Uji', 'penanggung_jawab' => 'A',
            'sasaran' => 'Sasaran Uji Dasar Hitung', 'satuan' => 'Persen', 'metode_capaian' => MasterIku::METODE_RASIO,
            'deskripsi_x' => 'Jumlah Publikasi Berkualitas', 'deskripsi_y' => 'Jumlah Seluruh Publikasi',
            'dasar_hitung' => 'Target 2026: N = 8 mencakup: Laporan A, Laporan B.',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        // TW I mentah = 5, TW II mentah = 2 (kumulatifnya 7) -- rumus HARUS pakai 2
        // (mentah TW II saja), bukan 7 (kumulatif TW I+II).
        CapaianTahunan::create([
            'iku_id' => $iku->id, 'tahun' => 2026, 'y_target' => 8,
            'x_realisasi_tw1' => 5, 'x_realisasi_tw2' => 2,
        ]);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('2.00/8.00', $xml);
        $this->assertStringNotContainsString('7.00/8.00', $xml);
        $this->assertStringContainsString('Jumlah Publikasi Berkualitas', $xml);
        $this->assertStringContainsString('Jumlah Seluruh Publikasi', $xml);
        $this->assertStringContainsString('Target 2026: N = 8 mencakup: Laporan A, Laporan B.', $xml);
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
     * Batas Waktu Tindak Lanjut HARUS selalu akhir bulan triwulan periode notula itu
     * sendiri (RF-34, sesuai Kertas Kerja resmi) -- dihitung dari triwulan/tahun
     * periode, BUKAN dibaca apa adanya dari kolom rtl_evaluasi.batas_waktu tersimpan
     * (yang bisa salah kalau pernah diketik manual sebelum field itu dikunci di form).
     */
    public function test_docx_bagian1_batas_waktu_rtl_selalu_akhir_triwulan_bukan_kolom_tersimpan(): void
    {
        $this->loginSebagai('Tim SAKIP');

        $iku = MasterIku::create([
            'kode' => '3004', 'indikator' => 'Indikator Uji Batas Waktu RTL', 'tim' => 'Uji',
            'penanggung_jawab' => 'Uji', 'sasaran' => 'Sasaran Uji Batas Waktu RTL',
        ]);

        // Triwulan II 2026 -> akhir triwulannya Juni 2026 -- batas_waktu TERSIMPAN
        // sengaja diisi tanggal yang SALAH (Desember) untuk membuktikan kolom ini
        // tidak lagi dipakai apa adanya.
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 4, 'triwulan' => 2, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        RtlEvaluasi::create([
            'iku_id' => $iku->id,
            'periode_id' => $periode->id,
            'rtl_teks' => 'RTL uji batas waktu',
            'pic' => 'Uji',
            'batas_waktu' => '2026-12-31',
        ]);

        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('Juni 2026', $xml);
        $this->assertStringNotContainsString('Desember 2026', $xml);
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

        MasterIku::create([
            'kode' => '9999', 'indikator' => 'Indikator Uji Template Unggahan', 'sasaran' => 'Sasaran Uji Template Unggahan',
            'tim' => 'Uji', 'penanggung_jawab' => 'Uji',
        ]);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $notula = Notula::create(['periode_id' => $periode->id]);

        $xml = $this->documentXmlDariRespons($this->get(route('notula.unduh-bagian1-docx', $notula)));

        $this->assertStringContainsString('Sasaran Uji Template Unggahan', $xml);
    }
}
