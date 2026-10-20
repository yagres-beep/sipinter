<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StorageAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusSambunganGoogleTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsPeran(string $nama): User
    {
        $peran = Role::firstOrCreate(['nama' => $nama]);

        $user = User::create([
            'nama' => $nama.' Uji', 'username' => 'u-'.uniqid(), 'email' => 'u-'.uniqid().'@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]);

        $this->actingAs($user);

        return $user;
    }

    protected function akunAktif(array $atribut = []): StorageAccount
    {
        $akun = StorageAccount::create(array_merge([
            'email_gmail_institusi' => 'drive@example.test',
            'kuota_total' => 15,
            'status' => StorageAccount::STATUS_AKTIF,
        ], $atribut));

        return $akun->refresh();
    }

    public function test_status_google_membedakan_terhubung_terputus_dan_belum(): void
    {
        $belum = $this->akunAktif();
        $this->assertSame(StorageAccount::GOOGLE_BELUM, $belum->statusGoogle());
        $this->assertFalse($belum->googlePerluHubungUlang());

        $belum->update(['google_refresh_token' => 'token-valid']);
        $this->assertSame(StorageAccount::GOOGLE_TERHUBUNG, $belum->refresh()->statusGoogle());

        // Token masih terbaca tapi sudah ditolak Google (invalid_grant) — dulu tetap "Terhubung".
        $belum->update(['google_galat_pada' => now(), 'google_galat' => 'Token has been expired or revoked.']);
        $this->assertSame(StorageAccount::GOOGLE_TERPUTUS, $belum->refresh()->statusGoogle());
        $this->assertTrue($belum->googlePerluHubungUlang());
    }

    public function test_banner_mengingatkan_role_lain_untuk_menghubungi_tim_sakip(): void
    {
        $this->akunAktif(['google_refresh_token' => 'token-valid', 'google_galat_pada' => now()]);
        $this->actingAsPeran('Ketua Tim');

        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Sambungan Google Drive (drive@example.test) terputus')
            ->assertSee('Mohon ingatkan Tim SAKIP');
    }

    public function test_banner_tim_sakip_berisi_tombol_sambungkan_ulang(): void
    {
        $this->akunAktif(['google_refresh_token' => 'token-valid', 'google_galat_pada' => now()]);
        $this->actingAsPeran('Tim SAKIP');

        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('terputus')
            ->assertSee(route('storage-accounts.index'), false)
            ->assertDontSee('Mohon ingatkan Tim SAKIP');
    }

    public function test_banner_tidak_tampil_saat_terhubung(): void
    {
        $this->akunAktif(['google_refresh_token' => 'token-valid']);
        $this->actingAsPeran('Kepala');

        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('Unggahan bukti dukung ke Google Drive sedang terhenti');
    }
}
