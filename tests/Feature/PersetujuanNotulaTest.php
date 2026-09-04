<?php

namespace Tests\Feature;

use App\Livewire\PersetujuanNotula;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class PersetujuanNotulaTest extends TestCase
{
    use RefreshDatabase;

    protected function buatUser(string $peranNama): User
    {
        $peran = Role::firstOrCreate(['nama' => $peranNama]);

        return User::create([
            'nama' => $peranNama.' Uji',
            'username' => strtolower(str_replace(' ', '', $peranNama)).'-uji@example.test',
            'email' => strtolower(str_replace(' ', '', $peranNama)).'-uji@example.test',
            'password' => 'password',
            'role_id' => $peran->id,
            'status_verifikasi' => 'terverifikasi',
        ]);
    }

    /**
     * Notula TW3 2026 "menunggu_persetujuan" beserta satu IKU yang sudah "diverifikasi"
     * Tim SAKIP (dua kegiatan di bawahnya) — persis kondisi yang dilihat Kepala saat
     * meninjau notula sebelum menyetujui.
     *
     * @return array{notula: Notula, capaian: Capaian, kegiatan1: Kegiatan, kegiatan2: Kegiatan}
     */
    protected function siapkanNotulaMenungguDenganIkuTerverifikasi(): array
    {
        $iku = MasterIku::create([
            'kode' => 'UJI-020',
            'indikator' => 'Indikator uji persetujuan',
            'tim' => 'Uji',
            'penanggung_jawab' => 'Ketua Uji',
        ]);

        $periode = Periode::create([
            'tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false,
        ]);

        $notula = Notula::create(['periode_id' => $periode->id, 'status' => Notula::STATUS_MENUNGGU_PERSETUJUAN]);

        $capaian = Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIVERIFIKASI]);

        $kegiatan1 = Kegiatan::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan pertama', 'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        $kegiatan2 = Kegiatan::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan kedua', 'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        return compact('notula', 'capaian', 'kegiatan1', 'kegiatan2');
    }

    public function test_kepala_mengembalikan_isian_langsung_ke_ketua_tim(): void
    {
        Queue::fake();

        $kepala = $this->buatUser('Kepala');
        $this->actingAs($kepala);
        $data = $this->siapkanNotulaMenungguDenganIkuTerverifikasi();

        Livewire::test(PersetujuanNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->call('bukaFormKembalikanIsian', $data['capaian']->id)
            ->set('catatanKembalikanIsian', 'Realisasi belum sesuai bukti dukung')
            ->call('kembalikanIsian');

        $capaian = $data['capaian']->fresh();
        $this->assertSame(Capaian::STATUS_DIKEMBALIKAN, $capaian->status);
        $this->assertSame(Kegiatan::STATUS_DIKEMBALIKAN, $data['kegiatan1']->fresh()->status_dokumen);
        $this->assertSame(Kegiatan::STATUS_DIKEMBALIKAN, $data['kegiatan2']->fresh()->status_dokumen);
        $this->assertSame(Notula::STATUS_DIKEMBALIKAN, $data['notula']->fresh()->status);

        $riwayat = $capaian->riwayatStatus->first();
        $this->assertSame($kepala->id, $riwayat->user_id);
        $this->assertSame('Realisasi belum sesuai bukti dukung', $riwayat->catatan);
    }

    public function test_kepala_tidak_bisa_mengembalikan_isian_yang_belum_diverifikasi(): void
    {
        $kepala = $this->buatUser('Kepala');
        $this->actingAs($kepala);
        $data = $this->siapkanNotulaMenungguDenganIkuTerverifikasi();

        $data['capaian']->update(['status' => Capaian::STATUS_DIAJUKAN]);

        Livewire::test(PersetujuanNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->call('bukaFormKembalikanIsian', $data['capaian']->id)
            ->set('catatanKembalikanIsian', 'Catatan apa saja')
            ->call('kembalikanIsian')
            ->assertHasErrors('aksiIsian');

        $this->assertSame(Capaian::STATUS_DIAJUKAN, $data['capaian']->fresh()->status);
        $this->assertSame(Notula::STATUS_MENUNGGU_PERSETUJUAN, $data['notula']->fresh()->status);
    }

    public function test_ketua_tim_dan_tim_sakip_tidak_bisa_akses_halaman_persetujuan(): void
    {
        $this->actingAs($this->buatUser('Ketua Tim'))->get('/persetujuan')->assertForbidden();
        $this->actingAs($this->buatUser('Tim SAKIP'))->get('/persetujuan')->assertForbidden();
    }

    public function test_kepala_mengembalikan_notula_mencatat_riwayat_dan_tampil_di_riwayat_tindakan(): void
    {
        Queue::fake();

        $kepala = $this->buatUser('Kepala');
        $this->actingAs($kepala);
        $data = $this->siapkanNotulaMenungguDenganIkuTerverifikasi();

        Livewire::test(PersetujuanNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->call('bukaFormKembalikan')
            ->set('catatanPengembalian', 'Bagian II perlu diperbaiki')
            ->call('kembalikan')
            ->assertSee('Riwayat Tindakan')
            ->assertSee('Bagian II perlu diperbaiki');

        $riwayat = $data['notula']->fresh()->riwayatStatus->first();
        $this->assertSame(Notula::STATUS_DIKEMBALIKAN, $riwayat->status);
        $this->assertSame($kepala->id, $riwayat->user_id);
        $this->assertSame('Bagian II perlu diperbaiki', $riwayat->catatan);
    }

    public function test_ketua_tim_bisa_ajukan_ulang_kegiatan_setelah_dikembalikan_kepala(): void
    {
        $kepala = $this->buatUser('Kepala');
        $this->actingAs($kepala);
        $data = $this->siapkanNotulaMenungguDenganIkuTerverifikasi();

        Livewire::test(PersetujuanNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->call('bukaFormKembalikanIsian', $data['capaian']->id)
            ->set('catatanKembalikanIsian', 'Perlu revisi')
            ->call('kembalikanIsian');

        $kegiatan1 = $data['kegiatan1']->fresh();
        $kegiatan1->ajukan();

        $this->assertSame(Kegiatan::STATUS_DIAJUKAN, $kegiatan1->fresh()->status_dokumen);
    }
}
