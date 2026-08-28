<?php

namespace Tests\Feature;

use App\Models\Notula;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Services\NotulaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Notula menyatu (Bagian I+II+III dirender sebagai SATU dokumen mengalir, bukan
 * tiga PDF terpisah yang digabung) + blok TTD di paling akhir setelah Bagian III.
 *
 * Konversi berkas ASLI Bagian II/III (LibreOffice) sengaja TIDAK diuji di
 * sini karena butuh binari eksternal terpasang di mesin penguji — bagian2_html/
 * bagian3_html diisi manual, sama seperti pola NotulaSetujuiTest yang mengisi
 * bagian2_pdf/bagian3_pdf manual lewat dompdf tanpa LibreOffice.
 */
class NotulaMenyatuTest extends TestCase
{
    use RefreshDatabase;

    protected function buatNotulaLengkap(array $atribut = []): Notula
    {
        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        return Notula::create(array_merge([
            'periode_id' => $periode->id,
            // Eksplisit (bukan andalkan default kolom di DB) — Notula::create()
            // tidak membaca balik default DB ke model dalam memori, jadi tanpa ini
            // $notula->status bernilai null sesaat setelah create() dalam request
            // yang sama (di alur produksi notula selalu di-refetch dulu sebelum
            // gabungkan() dipanggil, jadi tidak pernah terjadi).
            'status' => Notula::STATUS_DRAFT,
            'bagian1_html' => '<p class="penanda-bagian1">Konten Bagian I</p>',
            'bagian2_html' => '<p class="penanda-bagian2">Konten Bagian II</p>',
            'bagian3_html' => '<p class="penanda-bagian3">Konten Bagian III</p>',
            'tempat' => 'Ruang Rapat BPS',
            'notulis' => 'Notulis Uji',
        ], $atribut));
    }

    public function test_bagian_lengkap_ditentukan_dari_konten_inline_bukan_pdf(): void
    {
        $notula = $this->buatNotulaLengkap(['bagian3_html' => null]);

        $this->assertFalse($notula->bagianLengkap());

        $notula->update(['bagian3_html' => '<p>Konten Bagian III</p>']);

        $this->assertTrue($notula->fresh()->bagianLengkap());
    }

    public function test_render_notula_utuh_menyusun_ketiga_bagian_berurutan_tanpa_ttd(): void
    {
        $notula = $this->buatNotulaLengkap();

        $html = app(NotulaService::class)->renderNotulaUtuhHtml($notula, sertakanTtd: false);

        $posisi1 = strpos($html, 'penanda-bagian1');
        $posisi2 = strpos($html, 'penanda-bagian2');
        $posisi3 = strpos($html, 'penanda-bagian3');

        $this->assertNotFalse($posisi1);
        $this->assertNotFalse($posisi2);
        $this->assertNotFalse($posisi3);
        $this->assertTrue($posisi1 < $posisi2 && $posisi2 < $posisi3);

        // Tanpa TTD: markup blok tanda tangan (bukan cuma definisi CSS-nya di
        // <style>, yang selalu ada) tidak boleh muncul sama sekali.
        $this->assertStringNotContainsString('Mengetahui,', $html);

        // Tugas 2: TIDAK ada page-break paksa antar bagian — hanya blok TTD yang
        // boleh punya page-break-inside:avoid.
        $this->assertStringNotContainsString('page-break-before', $html);
    }

    public function test_render_notula_utuh_menyertakan_ttd_sekali_di_paling_akhir(): void
    {
        $kepalaRole = Role::firstOrCreate(['nama' => 'Kepala']);
        $kepala = User::create([
            'nama' => 'Kepala Uji Menyatu', 'username' => 'kepala-menyatu@example.test', 'email' => 'kepala-menyatu@example.test',
            'password' => 'password', 'role_id' => $kepalaRole->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $notula = $this->buatNotulaLengkap([
            'status' => Notula::STATUS_MENUNGGU_PERSETUJUAN,
            'disetujui_oleh_user_id' => $kepala->id,
            'disetujui_pada' => now(),
        ]);

        $html = app(NotulaService::class)->renderNotulaUtuhHtml($notula, sertakanTtd: true);

        // "Mengetahui," HANYA muncul di blok TTD Kepala — sekali saja.
        $this->assertSame(1, substr_count($html, 'Mengetahui,'));

        $posisi3 = strpos($html, 'penanda-bagian3');
        $posisiTtd = strpos($html, 'Mengetahui,');

        $this->assertNotFalse($posisiTtd);
        $this->assertTrue($posisi3 < $posisiTtd);
        $this->assertStringContainsString($kepala->nama, $html);
        $this->assertStringContainsString('Notulis Uji', $html);
    }

    public function test_gabungkan_merender_satu_pdf_dari_ketiga_bagian(): void
    {
        $notula = $this->buatNotulaLengkap();

        app(NotulaService::class)->gabungkan($notula);

        $notula->refresh();
        $this->assertNotNull($notula->pdf_gabungan);
        $this->assertTrue(Storage::disk('local')->exists($notula->pdf_gabungan));
        $this->assertSame(Notula::STATUS_MENUNGGU_PERSETUJUAN, $notula->status);
    }

    public function test_gabungkan_menolak_bila_bagian_belum_lengkap(): void
    {
        $notula = $this->buatNotulaLengkap(['bagian2_html' => null]);

        $this->expectException(RuntimeException::class);

        app(NotulaService::class)->gabungkan($notula);
    }
}
