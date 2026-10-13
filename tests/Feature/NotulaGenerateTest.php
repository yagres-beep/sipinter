<?php

namespace Tests\Feature;

use App\Livewire\KompilasiNotula;
use App\Livewire\PersetujuanNotula;
use App\Livewire\TemplateNotula;
use App\Models\Capaian;
use App\Models\FolderConfig;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Services\LibreOfficeConversionService;
use App\Services\NotulaBagian1DocxService;
use App\Services\NotulaService;
use Barryvdh\DomPDF\Facade\Pdf as PdfFacade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Perbaikan alur generate notula: kegagalan konversi/render tidak meruntuhkan halaman
 * maupun menimpa draf, tata halaman mengikuti template unggahan, placeholder tak dikenal
 * diperingatkan, dan data antar-triwulan tidak tercampur.
 */
class NotulaGenerateTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $peran): User
    {
        $role = Role::firstOrCreate(['nama' => $peran]);
        $user = User::create([
            'nama' => $peran.' Uji', 'username' => str($peran)->slug().'@example.test', 'email' => str($peran)->slug().'@example.test',
            'password' => 'password', 'role_id' => $role->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($user);

        return $user;
    }

    private function konversiGagal(): void
    {
        $this->instance(LibreOfficeConversionService::class, Mockery::mock(LibreOfficeConversionService::class, function ($mock) {
            $mock->shouldReceive('convertToHtml')->andThrow(new RuntimeException('LibreOffice tidak ditemukan di: /tidak/ada.'));
            $mock->shouldReceive('convertToPdf')->andThrow(new RuntimeException('LibreOffice tidak ditemukan di: /tidak/ada.'));
        }));
    }

    /** Template bawaan dengan word/document.xml diubah lewat $ubah. */
    private function templateDiubah(callable $ubah): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        copy(base_path('template_notula/SIPINTER_Template_Bagian_I_Mesin.docx'), $temp);
        $zip = new ZipArchive;
        $zip->open($temp);
        $zip->addFromString('word/document.xml', $ubah($zip->getFromName('word/document.xml')));
        $zip->close();
        $isi = file_get_contents($temp);
        unlink($temp);

        return $isi;
    }

    public function test_halaman_kompilasi_tetap_terbuka_saat_konversi_bagian1_gagal(): void
    {
        $this->konversiGagal();
        $this->login('Tim SAKIP');

        Livewire::test(KompilasiNotula::class)
            ->assertOk()
            ->assertHasErrors('bagian1')
            ->assertSee('Bagian I belum bisa disusun otomatis');
    }

    public function test_susun_ulang_gagal_tidak_menimpa_bagian1_maupun_cadangannya(): void
    {
        $this->login('Tim SAKIP');
        $notula = app(NotulaService::class)->untukTriwulan(2026, 3);
        $notula->update(['bagian1_html' => '<p>Suntingan manual</p>', 'bagian1_html_cadangan' => '<p>Cadangan lama</p>']);

        $this->konversiGagal();

        Livewire::test(KompilasiNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->call('susunUlangOtomatis')
            ->assertHasErrors('bagian1');

        $notula->refresh();
        $this->assertSame('<p>Suntingan manual</p>', $notula->bagian1_html);
        $this->assertSame('<p>Cadangan lama</p>', $notula->bagian1_html_cadangan);
    }

    public function test_unggah_bagian2_gagal_tidak_mengubah_isian_lama_dan_membersihkan_berkas_sementara(): void
    {
        $this->login('Tim SAKIP');
        $notula = app(NotulaService::class)->untukTriwulan(2026, 3);
        $notula->update(['bagian1_html' => '<p>I</p>', 'bagian2_html' => '<p>Bagian II lama</p>']);
        $this->konversiGagal();

        Livewire::test(KompilasiNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->set('bagian2File', UploadedFile::fake()->create('b2.docx', 10))
            ->call('unggahBagian', 2)
            ->assertHasErrors('bagian2File');

        $this->assertSame('<p>Bagian II lama</p>', $notula->fresh()->bagian2_html);
        $this->assertSame([], glob(storage_path('app/private/notula-sementara/*')) ?: []);
    }

    public function test_gabungkan_gagal_render_menampilkan_pesan_tanpa_mengirim_ke_kepala(): void
    {
        $this->fakeKonversiBagian1KeXmlMentah();
        $this->login('Tim SAKIP');
        $notula = app(NotulaService::class)->untukTriwulan(2026, 3);
        $notula->update(['bagian1_html' => '<p>I</p>', 'bagian2_html' => '<p>II</p>', 'bagian3_html' => '<p>III</p>']);

        PdfFacade::shouldReceive('loadView')->andThrow(new \Exception('dompdf rusak'));

        Livewire::test(KompilasiNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->call('gabungkan')
            ->assertHasErrors('gabung');

        $this->assertSame(Notula::STATUS_DRAFT, $notula->fresh()->status);
        $this->assertNull($notula->fresh()->pdf_gabungan);
    }

    public function test_persetujuan_dibatalkan_bila_pdf_final_gagal_dibuat(): void
    {
        $kepala = $this->login('Kepala');
        $iku = MasterIku::create(['kode' => 'G1', 'indikator' => 'Indikator G1', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        $kegiatan = Kegiatan::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'uraian_kegiatan' => 'K', 'jenis' => 'bukan_survei_sensus', 'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI]);
        $notula = Notula::create(['periode_id' => $periode->id, 'status' => Notula::STATUS_MENUNGGU_PERSETUJUAN, 'bagian1_html' => '<p>I</p>', 'bagian2_html' => '<p>II</p>', 'bagian3_html' => '<p>III</p>']);

        PdfFacade::shouldReceive('loadView')->andThrow(new \Exception('dompdf rusak'));

        try {
            app(NotulaService::class)->setujui($notula, $kepala);
            $this->fail('Harus melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('PDF notula gagal dibuat', $e->getMessage());
        }

        $this->assertSame(Notula::STATUS_MENUNGGU_PERSETUJUAN, $notula->fresh()->status);
        $this->assertNull($notula->fresh()->pdf_final);
        $this->assertSame(Kegiatan::STATUS_DIVERIFIKASI, $kegiatan->fresh()->status_dokumen);
    }

    public function test_layar_persetujuan_menampilkan_pesan_bila_pdf_final_gagal(): void
    {
        $this->login('Kepala');
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
        Notula::create(['periode_id' => $periode->id, 'status' => Notula::STATUS_MENUNGGU_PERSETUJUAN, 'bagian1_html' => '<p>I</p>', 'bagian2_html' => '<p>II</p>', 'bagian3_html' => '<p>III</p>']);

        PdfFacade::shouldReceive('loadView')->andThrow(new \Exception('dompdf rusak'));

        Livewire::test(PersetujuanNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->call('setujui')
            ->assertHasErrors('aksi');
    }

    public function test_tata_halaman_pdf_mengikuti_template_unggahan(): void
    {
        $isi = $this->templateDiubah(fn (string $xml) => preg_replace(
            ['/<w:pgSz [^>]*\/>/', '/<w:pgMar [^>]*\/>/'],
            ['<w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>', '<w:pgMar w:top="720" w:right="1080" w:bottom="720" w:left="1440" w:header="708" w:footer="708"/>'],
            $xml
        ));
        Storage::disk('local')->put('template-notula/lanskap.docx', $isi);
        FolderConfig::current()->update(['template_notula_path' => 'template-notula/lanskap.docx']);

        $notula = app(NotulaService::class)->untukTriwulan(2026, 3);
        $notula->update(['bagian1_html' => '<p>I</p>']);

        $halaman = app(NotulaBagian1DocxService::class)->tataHalamanTemplate();
        $this->assertEqualsWithDelta(841.9, $halaman['lebar'], 0.1);
        $this->assertEqualsWithDelta(595.3, $halaman['tinggi'], 0.1);

        $html = app(NotulaService::class)->renderNotulaUtuhHtml($notula, false);
        $this->assertStringContainsString('@page { margin: 36pt 54pt 36pt 72pt; }', $html);
    }

    public function test_unggah_template_memperingatkan_placeholder_tidak_dikenal(): void
    {
        Storage::fake('local');
        $this->login('Tim SAKIP');
        $isi = $this->templateDiubah(fn (string $xml) => str_replace('{{tempat}}', '{{tempat}} {{ruang_rapat}}', $xml));

        Livewire::test(TemplateNotula::class)
            ->set('templateFile', UploadedFile::fake()->createWithContent('template.docx', $isi))
            ->call('unggah')
            ->assertHasNoErrors()
            ->assertSee('{{ruang_rapat}}')
            ->assertSee('tidak dikenali sistem');

        $this->assertNotNull(FolderConfig::current()->template_notula_path);
    }

    public function test_template_bawaan_tidak_memicu_peringatan_placeholder(): void
    {
        $this->assertSame([], app(NotulaBagian1DocxService::class)->macroTidakDikenal(base_path('template_notula/SIPINTER_Template_Bagian_I_Mesin.docx')));
    }

    public function test_simpan_detail_rapat_mengingatkan_bagian1_masih_memuat_nilai_lama(): void
    {
        $this->fakeKonversiBagian1KeXmlMentah();
        $this->login('Tim SAKIP');
        app(NotulaService::class)->untukTriwulan(2026, 3)->update(['bagian1_html' => '<p>Bagian I lama</p>']);

        Livewire::test(KompilasiNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->set('tempat', 'Aula Baru')
            ->call('simpanDetailRapat')
            ->assertSee('Bagian I masih memuat detail rapat sebelumnya');
    }

    public function test_bagian1_tiap_triwulan_hanya_memuat_data_triwulannya_sendiri(): void
    {
        $this->fakeKonversiBagian1KeXmlMentah();

        foreach ([2 => 'Analisis khusus TW2', 3 => 'Analisis khusus TW3'] as $tw => $analisis) {
            $iku = MasterIku::create(['kode' => "M{$tw}", 'indikator' => "Indikator TW{$tw}", 'sasaran' => 'Sasaran Uji', 'tim' => 'Uji', 'penanggung_jawab' => 'A']);
            $periode = Periode::create(['tahun' => 2026, 'bulan' => ($tw - 1) * 3 + 1, 'triwulan' => $tw, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);
            Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIVERIFIKASI, 'analisis_capaian' => $analisis]);
        }

        $svc = app(NotulaService::class);
        $tw2 = $svc->susunBagianSatu($svc->untukTriwulan(2026, 2));
        $tw3 = $svc->susunBagianSatu($svc->untukTriwulan(2026, 3));

        $this->assertStringContainsString('Analisis khusus TW2', $tw2);
        $this->assertStringNotContainsString('Analisis khusus TW3', $tw2);
        $this->assertStringContainsString('Analisis khusus TW3', $tw3);
        $this->assertStringNotContainsString('Analisis khusus TW2', $tw3);
    }

    public function test_ketua_tim_tidak_bisa_mengunduh_pratinjau_maupun_docx_notula(): void
    {
        $this->login('Ketua Tim');
        $notula = app(NotulaService::class)->untukTriwulan(2026, 3);

        $this->get(route('notula.pratinjau-cepat-pdf', $notula))->assertForbidden();
        $this->get(route('notula.unduh-bagian1-docx', $notula))->assertForbidden();
    }

    public function test_folder_kerja_libreoffice_tidak_bisa_dibuat_menghasilkan_pesan_jelas(): void
    {
        config(['services.libreoffice.binary_path' => PHP_BINARY]);
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'uji-lo-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/bukan-folder', 'x');
        file_put_contents($dir.'/masuk.docx', 'x');

        try {
            (new LibreOfficeConversionService)->convertToPdf($dir.'/masuk.docx', $dir.'/bukan-folder/keluar');
            $this->fail('Harus melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Folder kerja konversi tidak bisa dibuat', $e->getMessage());
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_folder_docx_bagian1_tidak_bisa_dibuat_menghasilkan_pesan_jelas(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'uji-docx-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/bukan-folder', 'x');
        $notula = app(NotulaService::class)->untukTriwulan(2026, 3);
        $docx = app(NotulaBagian1DocxService::class);

        try {
            $docx->generate($notula, app(NotulaService::class)->kumpulkanDataBagianSatu($notula), $dir.'/bukan-folder/sub/b1.docx');
            $this->fail('Harus melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Folder untuk berkas Bagian I tidak bisa dibuat', $e->getMessage());
        } finally {
            File::deleteDirectory($dir);
        }

        // Delimiter static PhpWord dikembalikan walau generate() gagal.
        $this->assertSame([], $docx->macroTidakDikenal(base_path('template_notula/SIPINTER_Template_Bagian_I_Mesin.docx')));
    }

    public function test_kegagalan_proses_libreoffice_menghasilkan_pesan_yang_jelas(): void
    {
        // PHP_BINARY dipakai sebagai "soffice" palsu: argumen --headless dst. membuatnya
        // keluar dengan kode gagal, mensimulasikan LibreOffice yang crash.
        config(['services.libreoffice.binary_path' => PHP_BINARY]);
        $docx = tempnam(sys_get_temp_dir(), 'lo').'.docx';
        file_put_contents($docx, 'bukan docx');

        try {
            (new LibreOfficeConversionService)->convertToPdf($docx, sys_get_temp_dir());
            $this->fail('Harus melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('Konversi dokumen lewat LibreOffice gagal (kode keluar', $e->getMessage());
        } finally {
            @unlink($docx);
        }
    }
}
