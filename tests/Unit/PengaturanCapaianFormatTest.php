<?php

namespace Tests\Unit;

use App\Models\PengaturanCapaian;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PengaturanCapaian::formatAngka()/formatPersen() — dipakai tabel Rekap Kinerja
 * Tahunan (LAKIN) & Excel-nya untuk menentukan tulisan nilai 0 ("0" apa adanya vs
 * "-" dianggap belum ada data), sesuai pengaturan tampilkan_nol_sebagai_strip.
 * Null SELALU "-" apa pun pengaturannya (beda dari 0 yang memang ada datanya).
 */
class PengaturanCapaianFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_null_selalu_strip_apa_pun_pengaturannya(): void
    {
        $this->assertSame('-', PengaturanCapaian::formatAngka(null));
        $this->assertSame('-', PengaturanCapaian::formatPersen(null));

        PengaturanCapaian::ambil()->update(['tampilkan_nol_sebagai_strip' => true]);
        PengaturanCapaian::lupakanCache();

        $this->assertSame('-', PengaturanCapaian::formatAngka(null));
        $this->assertSame('-', PengaturanCapaian::formatPersen(null));
    }

    public function test_nol_ditulis_apa_adanya_secara_default(): void
    {
        $this->assertSame('0', PengaturanCapaian::formatAngka(0));
        $this->assertSame('0%', PengaturanCapaian::formatPersen(0));
    }

    public function test_nol_ditulis_strip_bila_pengaturan_diaktifkan(): void
    {
        PengaturanCapaian::ambil()->update(['tampilkan_nol_sebagai_strip' => true]);
        PengaturanCapaian::lupakanCache();

        $this->assertSame('-', PengaturanCapaian::formatAngka(0));
        $this->assertSame('-', PengaturanCapaian::formatPersen(0));
    }

    public function test_nilai_bukan_nol_tidak_terpengaruh_pengaturan(): void
    {
        PengaturanCapaian::ambil()->update(['tampilkan_nol_sebagai_strip' => true]);
        PengaturanCapaian::lupakanCache();

        $this->assertSame('75', PengaturanCapaian::formatAngka(75));
        $this->assertSame('75%', PengaturanCapaian::formatPersen(75));
    }
}
