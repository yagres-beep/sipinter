<?php

namespace Tests\Feature;

use App\Livewire\KompilasiNotula;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KompilasiNotulaTest extends TestCase
{
    use RefreshDatabase;

    protected function loginSebagaiTimSakip(): User
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $user = User::create([
            'nama' => 'SAKIP Uji', 'email' => 'sakip-notula@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_susun_ulang_otomatis_mengirim_event_untuk_memperbarui_editor_wysiwyg(): void
    {
        $this->loginSebagaiTimSakip();

        Livewire::test(KompilasiNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->call('susunUlangOtomatis')
            ->assertDispatched('bagian1-diperbarui');
    }

    public function test_simpan_suntingan_bagian1_menyimpan_konten_dari_editor(): void
    {
        $this->loginSebagaiTimSakip();

        $component = Livewire::test(KompilasiNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->set('bagian1EditText', '<h3>Uji Editor Word</h3><p>Konten disunting langsung.</p>')
            ->call('simpanSuntinganBagian1')
            ->assertHasNoErrors();

        $this->assertStringContainsString(
            'Uji Editor Word',
            \App\Models\Notula::first()->bagian1_html
        );
    }

    public function test_bagian_2_menolak_berkas_selain_docx(): void
    {
        $this->loginSebagaiTimSakip();

        Livewire::test(KompilasiNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->set('bagian2File', \Illuminate\Http\UploadedFile::fake()->create('bagian2.pdf', 100, 'application/pdf'))
            ->call('unggahBagian', 2)
            ->assertHasErrors('bagian2File');
    }

    public function test_ganti_triwulan_mengirim_event_untuk_memuat_ulang_editor(): void
    {
        $this->loginSebagaiTimSakip();

        Livewire::test(KompilasiNotula::class)
            ->set('tahun', 2026)
            ->set('triwulan', 3)
            ->set('triwulan', 2)
            ->assertDispatched('bagian1-diperbarui');
    }
}
