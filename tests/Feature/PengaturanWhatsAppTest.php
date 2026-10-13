<?php

namespace Tests\Feature;

use App\Livewire\PengaturanWhatsApp;
use App\Models\RiwayatPengirimanWa;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class PengaturanWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://gateway.contoh.test';

    private const TOKEN = 'token-rahasia-gateway-uji-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp.gateway_url' => self::URL, 'services.whatsapp.gateway_token' => self::TOKEN]);
    }

    protected function buatUser(string $peran, bool $setuju = false, string $nomor = '081234567890'): User
    {
        $role = Role::firstOrCreate(['nama' => $peran]);
        $user = User::create([
            'nama' => "{$peran} Uji", 'username' => uniqid('u'), 'email' => uniqid().'@example.test',
            'password' => 'password', 'role_id' => $role->id, 'status_verifikasi' => 'terverifikasi',
            'nomor_telepon' => $nomor,
        ]);
        $user->forceFill(['wa_opt_in' => $setuju])->save();

        return $user;
    }

    protected function statusGateway(string $status, array $tambahan = []): array
    {
        return array_merge([
            'status' => $status, 'connected' => $status === 'connected', 'session_present' => $status === 'connected',
            'me' => $status === 'connected' ? '6281****890' : null, 'qr_available' => $status === 'waiting_qr',
            'reconnect_attempts' => 0, 'next_retry_at' => null, 'last_disconnect' => null, 'lock_held' => true,
            'outbox' => ['queued' => 0, 'sending' => 0, 'unknown' => 0],
        ], $tambahan);
    }

    public function test_selain_tim_sakip_ditolak(): void
    {
        $this->actingAs($this->buatUser('Ketua Tim'));

        Livewire::test(PengaturanWhatsApp::class)->assertForbidden();
    }

    public function test_halaman_kelola_pengguna_tampil_tanpa_memanggil_gateway(): void
    {
        Http::fake();
        $this->actingAs($this->buatUser('Tim SAKIP'));

        $this->get(route('verifikasi-akun.index'))
            ->assertOk()
            ->assertSeeLivewire('pengaturan-whats-app')
            ->assertDontSee(self::TOKEN);

        Http::assertNothingSent();
    }

    public function test_menampilkan_qr_dari_gateway_tanpa_membocorkan_token(): void
    {
        Http::fake([
            self::URL.'/status' => Http::response($this->statusGateway('waiting_qr')),
            self::URL.'/qr' => Http::response(['status' => 'waiting_qr', 'qr_data_url' => 'data:image/png;base64,QUJD', 'qr_age_seconds' => 3]),
        ]);
        $this->actingAs($this->buatUser('Tim SAKIP'));

        Livewire::test(PengaturanWhatsApp::class)
            ->call('periksa')
            ->assertSee('Menunggu QR dipindai')
            ->assertSee('data:image/png;base64,QUJD', false)
            ->assertDontSee(self::TOKEN);
    }

    public function test_status_terhubung_dan_nomor_tersamarkan(): void
    {
        Http::fake([self::URL.'/status' => Http::response($this->statusGateway('connected'))]);
        $this->actingAs($this->buatUser('Tim SAKIP'));

        Livewire::test(PengaturanWhatsApp::class)
            ->call('periksa')
            ->assertSee('Terhubung')
            ->assertSee('6281****890')
            ->assertSet('qrDataUrl', null);
    }

    public function test_gateway_tidak_terjangkau_menampilkan_pesan_ramah(): void
    {
        Http::fake([self::URL.'/*' => Http::failedConnection()]);
        $this->actingAs($this->buatUser('Tim SAKIP'));

        Livewire::test(PengaturanWhatsApp::class)
            ->call('periksa')
            ->assertSee('tidak dapat dihubungi');
    }

    public function test_hubungkan_dan_putuskan_memanggil_endpoint_yang_benar(): void
    {
        Http::fake([
            self::URL.'/connect' => Http::response($this->statusGateway('connecting')),
            self::URL.'/logout' => Http::response($this->statusGateway('logged_out')),
            self::URL.'/status' => Http::response($this->statusGateway('connecting')),
        ]);
        $this->actingAs($this->buatUser('Tim SAKIP'));

        Livewire::test(PengaturanWhatsApp::class)
            ->call('hubungkan')
            ->call('mintaPutus')
            ->assertSet('konfirmasiPutus', true)
            ->call('putuskan')
            ->assertSet('konfirmasiPutus', false);

        Http::assertSent(fn (Request $r) => $r->url() === self::URL.'/connect' && $r->method() === 'POST');
        Http::assertSent(fn (Request $r) => $r->url() === self::URL.'/logout' && $r->method() === 'POST');
    }

    public function test_kirim_uji_ditolak_ke_nomor_yang_belum_setuju(): void
    {
        Http::fake();
        $this->actingAs($this->buatUser('Tim SAKIP'));
        $this->buatUser('Ketua Tim', setuju: false, nomor: '081299998888');

        Livewire::test(PengaturanWhatsApp::class)
            ->set('nomorTes', '081299998888')
            ->call('kirimTes')
            ->assertHasErrors('nomorTes');

        Livewire::test(PengaturanWhatsApp::class)
            ->set('nomorTes', '081277776666')
            ->call('kirimTes')
            ->assertHasErrors('nomorTes');

        Http::assertNothingSent();
        $this->assertSame(0, RiwayatPengirimanWa::count());
    }

    public function test_kirim_uji_ke_pengguna_yang_setuju_diserahkan_ke_gateway(): void
    {
        Http::fake([self::URL.'/send-message' => Http::response(['status' => 'queued', 'created' => true], 202)]);
        $this->actingAs($this->buatUser('Tim SAKIP'));
        $penerima = $this->buatUser('Ketua Tim', setuju: true, nomor: '+62 812-9999-8888');

        Livewire::test(PengaturanWhatsApp::class)
            ->set('nomorTes', '081299998888')
            ->call('kirimTes')
            ->assertHasNoErrors();

        $baris = RiwayatPengirimanWa::sole();
        $this->assertSame($penerima->id, $baris->user_id);
        $this->assertSame(RiwayatPengirimanWa::STATUS_DI_GATEWAY, $baris->status);
        Http::assertSent(fn (Request $r) => $r['phone'] === '6281299998888' && str_starts_with($r['idempotency_key'], 'tes:'));
    }

    public function test_profil_menyimpan_dan_mencabut_persetujuan_wa(): void
    {
        $user = $this->buatUser('Ketua Tim');
        $this->actingAs($user);
        $data = ['nama' => $user->nama, 'username' => $user->username, 'email' => $user->email, 'nomor_telepon' => $user->nomor_telepon];

        $this->patch(route('profile.update'), $data + ['wa_opt_in' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->wa_opt_in);
        $this->assertNotNull($user->fresh()->wa_opt_in_at);

        $this->patch(route('profile.update'), $data + ['wa_opt_in' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->wa_opt_in);
        $this->assertNull($user->fresh()->wa_opt_in_at);
    }
}
