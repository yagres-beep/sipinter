<?php

namespace Tests\Feature;

use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use App\Services\NotulaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Versi notula (RF-44 lanjutan): notula yang SUDAH disetujui Kepala tidak lagi jadi
 * jalan buntu. Begitu ada data/isian yang berubah setelahnya — Kepala mengembalikan
 * satu isian IKU dari halaman Persetujuan, atau Tim SAKIP mengganti/menyunting salah
 * satu bagian — notula ditarik kembali ke awal (draft) sebagai VERSI BERIKUTNYA,
 * digabung ulang, lalu disetujui (ber-TTD) lagi. Berkas PDF tiap versi tersimpan
 * dengan nama berakhiran "-v{n}" supaya versi lama tidak pernah tertimpa.
 */
class NotulaVersiTest extends TestCase
{
    use RefreshDatabase;

    protected function buatKepala(): User
    {
        $peran = Role::firstOrCreate(['nama' => 'Kepala']);

        return User::create([
            'nama' => 'Kepala Uji Versi',
            'username' => 'kepala-versi@example.test',
            'email' => 'kepala-versi@example.test',
            'password' => 'password',
            'role_id' => $peran->id,
            'status_verifikasi' => 'terverifikasi',
        ]);
    }

    /**
     * Notula TW3 2026 lengkap (tiga bagian terisi) berstatus "menunggu persetujuan",
     * beserta satu IKU yang sudah diverifikasi Tim SAKIP — kondisi tepat sebelum
     * Kepala menekan "Setujui".
     *
     * @return array{notula: Notula, capaian: Capaian, kegiatan: Kegiatan}
     */
    protected function siapkanNotulaMenunggu(): array
    {
        $iku = MasterIku::create([
            'kode' => 'UJI-VERSI', 'indikator' => 'Indikator uji versi notula', 'tim' => 'Uji', 'penanggung_jawab' => 'Ketua Uji',
        ]);

        $periode = Periode::create(['tahun' => 2026, 'bulan' => 7, 'triwulan' => 3, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]);

        $capaian = Capaian::create(['iku_id' => $iku->id, 'periode_id' => $periode->id, 'status' => Capaian::STATUS_DIVERIFIKASI]);

        $kegiatan = Kegiatan::create([
            'iku_id' => $iku->id, 'periode_id' => $periode->id,
            'uraian_kegiatan' => 'Kegiatan uji versi', 'jenis' => 'bukan_survei_sensus',
            'status_dokumen' => Kegiatan::STATUS_DIVERIFIKASI,
        ]);

        $notula = Notula::create([
            'periode_id' => $periode->id,
            'status' => Notula::STATUS_MENUNGGU_PERSETUJUAN,
            'bagian1_html' => '<p>Bagian I</p>',
            'bagian2_html' => '<p>Bagian II</p>',
            'bagian3_html' => '<p>Bagian III</p>',
            'notulis' => 'Notulis Uji',
        ]);

        return compact('notula', 'capaian', 'kegiatan');
    }

    public function test_notula_baru_dimulai_dari_versi_satu(): void
    {
        $notula = app(NotulaService::class)->untukTriwulan(2026, 3);

        $this->assertSame(1, $notula->versiSaatIni());
        $this->assertSame(1, $notula->fresh()->versiSaatIni());
    }

    public function test_kepala_mengembalikan_isian_setelah_notula_disetujui_menarik_notula_ke_draft_versi_berikutnya(): void
    {
        Queue::fake();

        $kepala = $this->buatKepala();
        $data = $this->siapkanNotulaMenunggu();

        app(NotulaService::class)->setujui($data['notula'], $kepala);

        $notula = $data['notula']->fresh();
        $this->assertSame(Notula::STATUS_DISETUJUI, $notula->status);
        $this->assertSame(1, $notula->versiSaatIni());

        // Kegiatan ikut disetujui saat notula disetujui, jadi Capaian-nya kini
        // "disetujui" — Kepala tetap boleh mengembalikannya dari halaman Persetujuan.
        $capaian = $data['capaian']->fresh();
        $this->assertSame(Capaian::STATUS_DISETUJUI, $capaian->status);
        $this->assertTrue($capaian->bisaDikembalikanOlehKepala());

        app(NotulaService::class)->kembalikanIsian($capaian, $kepala, 'Realisasi IKU ini keliru, perlu diperbaiki');

        $notula = $notula->fresh();
        $this->assertSame(Notula::STATUS_DRAFT, $notula->status);
        $this->assertSame(2, $notula->versiSaatIni());
        $this->assertNull($notula->pdf_gabungan);
        $this->assertNull($notula->pdf_final);
        $this->assertNull($notula->disetujui_oleh_user_id);
        $this->assertNull($notula->disetujui_pada);

        $this->assertSame(Capaian::STATUS_DIKEMBALIKAN, $capaian->fresh()->status);

        // Riwayat Tindakan: baris terbaru menerangkan pembukaan versi baru beserta
        // alasannya, dan jejak persetujuan versi 1 tetap tersimpan di bawahnya.
        $riwayat = $notula->riwayatStatus()->get();
        $this->assertSame(Notula::STATUS_DRAFT, $riwayat->first()->status);
        $this->assertSame($kepala->id, $riwayat->first()->user_id);
        $this->assertStringContainsString('Realisasi IKU ini keliru', $riwayat->first()->catatan);
        $this->assertStringContainsString('versi 2', $riwayat->first()->catatan);
        $this->assertTrue($riwayat->contains(fn ($r) => $r->status === Notula::STATUS_DISETUJUI));
    }

    public function test_versi_kedua_bisa_digabung_dan_disetujui_ulang_tanpa_menimpa_berkas_versi_pertama(): void
    {
        Queue::fake();

        $kepala = $this->buatKepala();
        $data = $this->siapkanNotulaMenunggu();

        app(NotulaService::class)->setujui($data['notula'], $kepala);

        $finalVersi1 = $data['notula']->fresh()->pdf_final;
        $this->assertStringEndsWith('notula-final-tw3-2026-v1.pdf', $finalVersi1);
        $this->assertTrue(Storage::disk('local')->exists($finalVersi1));

        app(NotulaService::class)->kembalikanIsian($data['capaian']->fresh(), $kepala, 'Perlu perbaikan isian');

        // Alur normal diulang dari awal: Tim SAKIP menggabungkan lagi (otomatis
        // mengirim ke Kepala), lalu Kepala membubuhkan persetujuan + TTD lagi.
        $notula = $data['notula']->fresh();
        app(NotulaService::class)->gabungkan($notula);

        $this->assertSame(Notula::STATUS_MENUNGGU_PERSETUJUAN, $notula->fresh()->status);
        $this->assertStringEndsWith('notula-draf-tw3-2026-v2.pdf', $notula->fresh()->pdf_gabungan);

        app(NotulaService::class)->setujui($notula, $kepala);

        $notula = $notula->fresh();
        $this->assertSame(Notula::STATUS_DISETUJUI, $notula->status);
        $this->assertSame(2, $notula->versiSaatIni());
        $this->assertSame($kepala->id, $notula->disetujui_oleh_user_id);
        $this->assertStringEndsWith('notula-final-tw3-2026-v2.pdf', $notula->pdf_final);

        // Berkas final versi 1 TIDAK ditimpa versi 2 — keduanya ada berdampingan.
        $this->assertTrue(Storage::disk('local')->exists($finalVersi1));
        $this->assertTrue(Storage::disk('local')->exists($notula->pdf_final));
        $this->assertNotSame($finalVersi1, $notula->pdf_final);
    }

    /**
     * Isian yang dikembalikan selagi notula masih MENUNGGU persetujuan tidak boleh
     * menaikkan versi — dokumen itu belum pernah ditandatangani siapa pun, jadi
     * perilakunya tetap seperti semula: notula ikut berstatus "dikembalikan".
     */
    public function test_pengembalian_isian_sebelum_disetujui_tidak_menaikkan_versi(): void
    {
        Queue::fake();

        $kepala = $this->buatKepala();
        $data = $this->siapkanNotulaMenunggu();

        app(NotulaService::class)->kembalikanIsian($data['capaian'], $kepala, 'Bukti dukung belum sesuai');

        $notula = $data['notula']->fresh();
        $this->assertSame(Notula::STATUS_DIKEMBALIKAN, $notula->status);
        $this->assertSame(1, $notula->versiSaatIni());
    }

    /**
     * Mengganti/menyunting isi bagian notula setelah disetujui (jalur
     * Notula::tandaiPerluDigabungUlang(), dipakai unggah Bagian II/III & simpan
     * suntingan Bagian I di Kompilasi Notula) juga membuka versi baru — bukan
     * diam-diam mengubah isi dokumen yang sudah ber-TTD.
     */
    public function test_perubahan_bagian_setelah_disetujui_membuka_versi_baru(): void
    {
        Queue::fake();

        $kepala = $this->buatKepala();
        $data = $this->siapkanNotulaMenunggu();

        app(NotulaService::class)->setujui($data['notula'], $kepala);

        $notula = $data['notula']->fresh();
        $notula->tandaiPerluDigabungUlang($kepala, 'Berkas Bagian II diganti setelah notula disetujui');

        $notula = $notula->fresh();
        $this->assertSame(Notula::STATUS_DRAFT, $notula->status);
        $this->assertSame(2, $notula->versiSaatIni());
        $this->assertNull($notula->pdf_final);
        $this->assertSame('Berkas Bagian II diganti setelah notula disetujui', $notula->catatanVersiBaru());
    }

    /**
     * Perubahan bagian saat notula masih MENUNGGU persetujuan tetap hanya menarik
     * notula ke draft tanpa menaikkan versi (perilaku RF-42e yang sudah ada).
     */
    public function test_perubahan_bagian_sebelum_disetujui_tidak_menaikkan_versi(): void
    {
        $data = $this->siapkanNotulaMenunggu();

        $data['notula']->tandaiPerluDigabungUlang();

        $notula = $data['notula']->fresh();
        $this->assertSame(Notula::STATUS_DRAFT, $notula->status);
        $this->assertSame(1, $notula->versiSaatIni());
        $this->assertNull($notula->catatanVersiBaru());
    }
}
