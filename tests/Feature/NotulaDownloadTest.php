<?php

namespace Tests\Feature;

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
}
