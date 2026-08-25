<?php

namespace Tests\Feature;

use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\Periode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NotulaDownloadTest extends TestCase
{
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
}
