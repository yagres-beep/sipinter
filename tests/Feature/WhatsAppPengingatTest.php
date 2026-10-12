<?php

namespace Tests\Feature;

use App\Jobs\KirimPengingatEmailJob;
use App\Models\RiwayatPengirimanWa;
use App\Models\Role;
use App\Models\User;
use App\Services\WhatsAppPengingatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppPengingatTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://gateway.contoh.test';

    private const TOKEN = 'token-rahasia-gateway-uji-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.gateway_url' => self::URL,
            'services.whatsapp.gateway_token' => self::TOKEN,
            'services.whatsapp.pengingat_aktif' => true,
        ]);
    }

    protected function buatUser(string $peran = 'Tim SAKIP', bool $setuju = true, string $nomor = '0812-3456-7890'): User
    {
        $role = Role::firstOrCreate(['nama' => $peran]);
        $user = User::create([
            'nama' => 'Pengguna '.uniqid(), 'username' => uniqid('u'), 'email' => uniqid().'@example.test',
            'password' => 'password', 'role_id' => $role->id, 'status_verifikasi' => 'terverifikasi',
            'nomor_telepon' => $nomor,
        ]);
        $user->forceFill(['wa_opt_in' => $setuju, 'wa_opt_in_at' => $setuju ? now() : null])->save();

        return $user;
    }

    protected function layanan(): WhatsAppPengingatService
    {
        return app(WhatsAppPengingatService::class);
    }

    public function test_antrekan_membuat_baris_antre_dengan_nomor_ternormalisasi(): void
    {
        $user = $this->buatUser();

        $this->layanan()->antrekan($user, 'iku_diajukan', 'SIPINTER — IKU Diajukan', 'IKU X diajukan.', 'riwayat5');

        $baris = RiwayatPengirimanWa::sole();
        $this->assertSame(RiwayatPengirimanWa::STATUS_ANTRE, $baris->status);
        $this->assertSame('6281234567890', $baris->nomor_telepon);
        $this->assertSame("iku_diajukan:riwayat5:u{$user->id}", $baris->idempotency_key);
        $this->assertStringContainsString('*SIPINTER — IKU Diajukan*', $baris->pesan);
        $this->assertFalse($baris->berhasil);
    }

    public function test_antrekan_dua_kali_untuk_kejadian_sama_tidak_menggandakan(): void
    {
        $user = $this->buatUser();

        $this->layanan()->antrekan($user, 'iku_diajukan', 'S', 'P', 'riwayat5');
        $this->layanan()->antrekan($user, 'iku_diajukan', 'S', 'P', 'riwayat5');

        $this->assertSame(1, RiwayatPengirimanWa::count());
    }

    public function test_antrekan_dilewati_bila_belum_setuju_atau_saklar_mati(): void
    {
        $this->layanan()->antrekan($this->buatUser(setuju: false), 'iku_diajukan', 'S', 'P', 'riwayat1');

        config(['services.whatsapp.pengingat_aktif' => false]);
        $this->layanan()->antrekan($this->buatUser(), 'iku_diajukan', 'S', 'P', 'riwayat2');

        $this->assertSame(0, RiwayatPengirimanWa::count());
    }

    public function test_nomor_tidak_valid_dicatat_gagal_dan_tidak_pernah_dikirim(): void
    {
        Http::fake();
        $this->layanan()->antrekan($this->buatUser(nomor: '021-555'), 'iku_diajukan', 'S', 'P', 'riwayat1');

        $baris = RiwayatPengirimanWa::sole();
        $this->assertSame(RiwayatPengirimanWa::STATUS_GAGAL, $baris->status);

        $this->artisan('whatsapp:proses-antrean')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_kegagalan_wa_tidak_membatalkan_transaksi_pemanggil(): void
    {
        $user = $this->buatUser();
        DB::statement('DROP TABLE riwayat_pengiriman_wa');

        DB::transaction(function () use ($user) {
            $user->update(['nama' => 'Nama Baru']);
            $this->layanan()->antrekan($user, 'iku_diajukan', 'S', 'P', 'riwayat1');
        });

        $this->assertSame('Nama Baru', $user->fresh()->nama);
    }

    public function test_proses_antrean_tidak_memanggil_gateway_bila_tidak_ada_pekerjaan(): void
    {
        Http::fake();

        $this->artisan('whatsapp:proses-antrean')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_proses_antrean_menyerahkan_ke_gateway_dengan_token_dan_kunci_idempotensi(): void
    {
        Http::fake([self::URL.'/send-message' => Http::response(['idempotency_key' => 'x', 'status' => 'queued', 'created' => true], 202)]);
        $user = $this->buatUser();
        $this->layanan()->antrekan($user, 'iku_diajukan', 'S', 'P', 'riwayat9');

        $this->artisan('whatsapp:proses-antrean')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->url() === self::URL.'/send-message'
            && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $r['idempotency_key'] === "iku_diajukan:riwayat9:u{$user->id}"
            && $r['phone'] === '6281234567890');

        $baris = RiwayatPengirimanWa::sole();
        $this->assertSame(RiwayatPengirimanWa::STATUS_DI_GATEWAY, $baris->status);
        $this->assertNotNull($baris->diserahkan_ke_gateway_at);
        $this->assertSame(1, $baris->percobaan_kirim);
    }

    public function test_gateway_tidur_atau_token_salah_menunda_tanpa_menandai_gagal(): void
    {
        $this->layanan()->antrekan($this->buatUser(), 'iku_diajukan', 'S', 'P', 'riwayat1');
        $this->layanan()->antrekan($this->buatUser(), 'iku_diajukan', 'S', 'P', 'riwayat2');

        Http::fake([self::URL.'/*' => Http::failedConnection()]);
        $this->artisan('whatsapp:proses-antrean')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame(2, RiwayatPengirimanWa::where('status', RiwayatPengirimanWa::STATUS_ANTRE)->count());
        $this->assertStringContainsString('tidak dapat dihubungi', RiwayatPengirimanWa::orderBy('id')->first()->alasan_gagal);

        Http::fake([self::URL.'/*' => Http::response(['error' => 'unauthorized'], 401)]);
        $this->artisan('whatsapp:proses-antrean')->assertSuccessful();
        $this->assertSame(2, RiwayatPengirimanWa::where('status', RiwayatPengirimanWa::STATUS_ANTRE)->count());
        $this->assertStringNotContainsString(self::TOKEN, (string) RiwayatPengirimanWa::orderBy('id')->first()->alasan_gagal);
    }

    public function test_ditolak_validasi_gateway_ditandai_gagal(): void
    {
        Http::fake([self::URL.'/send-message' => Http::response(['error' => 'validation_failed', 'errors' => ['phone' => 'Nomor salah.']], 422)]);
        $this->layanan()->antrekan($this->buatUser(), 'iku_diajukan', 'S', 'P', 'riwayat1');

        $this->artisan('whatsapp:proses-antrean')->assertSuccessful();

        $baris = RiwayatPengirimanWa::sole();
        $this->assertSame(RiwayatPengirimanWa::STATUS_GAGAL, $baris->status);
        $this->assertStringContainsString('Nomor salah.', $baris->alasan_gagal);
    }

    public function test_antrean_lebih_dari_24_jam_kedaluwarsa_tanpa_dikirim(): void
    {
        Http::fake();
        $this->layanan()->antrekan($this->buatUser(), 'iku_diajukan', 'S', 'P', 'riwayat1');
        RiwayatPengirimanWa::query()->update(['created_at' => now()->subHours(25)]);

        $this->artisan('whatsapp:proses-antrean')->assertSuccessful();

        $this->assertSame(RiwayatPengirimanWa::STATUS_KEDALUWARSA, RiwayatPengirimanWa::sole()->status);
        Http::assertNothingSent();
    }

    public function test_sinkron_status_dari_gateway(): void
    {
        $user = $this->buatUser();
        foreach (['a', 'b', 'c', 'd'] as $i => $huruf) {
            RiwayatPengirimanWa::create([
                'user_id' => $user->id, 'jenis' => 'tes', 'idempotency_key' => "kunci-{$huruf}-uji",
                'nomor_telepon' => '6281234567890', 'pesan' => 'P', 'berhasil' => false,
                'status' => RiwayatPengirimanWa::STATUS_DI_GATEWAY, 'diserahkan_ke_gateway_at' => now(),
            ]);
        }

        Http::fake([self::URL.'/messages/lookup' => Http::response(['messages' => [
            ['idempotency_key' => 'kunci-a-uji', 'status' => 'sent', 'sent_at' => now()->toIso8601String()],
            ['idempotency_key' => 'kunci-b-uji', 'status' => 'delivered', 'sent_at' => now()->toIso8601String(), 'delivered_at' => now()->toIso8601String()],
            ['idempotency_key' => 'kunci-c-uji', 'status' => 'failed', 'error_message' => 'Nomor tujuan tidak terdaftar di WhatsApp.'],
            ['idempotency_key' => 'kunci-d-uji', 'status' => 'unknown', 'error_message' => 'Status pesan tidak pasti.'],
        ]])]);

        $this->artisan('whatsapp:proses-antrean')->assertSuccessful();

        $hasil = RiwayatPengirimanWa::pluck('status', 'idempotency_key');
        $this->assertSame(RiwayatPengirimanWa::STATUS_TERKIRIM, $hasil['kunci-a-uji']);
        $this->assertSame(RiwayatPengirimanWa::STATUS_DITERIMA, $hasil['kunci-b-uji']);
        $this->assertSame(RiwayatPengirimanWa::STATUS_GAGAL, $hasil['kunci-c-uji']);
        $this->assertSame(RiwayatPengirimanWa::STATUS_TIDAK_PASTI, $hasil['kunci-d-uji']);
        $this->assertTrue(RiwayatPengirimanWa::where('idempotency_key', 'kunci-b-uji')->value('berhasil'));
        $this->assertFalse(RiwayatPengirimanWa::where('idempotency_key', 'kunci-c-uji')->value('berhasil'));
    }

    public function test_baris_arsip_gateway_lama_tidak_pernah_diproses(): void
    {
        Http::fake();
        DB::table('riwayat_pengiriman_wa')->insert([
            'nomor_telepon' => '6281234567890', 'pesan' => 'lama', 'berhasil' => false,
            'status' => RiwayatPengirimanWa::STATUS_ARSIP, 'created_at' => now(),
        ]);

        $this->artisan('whatsapp:proses-antrean')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_command_pengingat_terjadwal_mengantrekan_wa_sekali_per_hari_dan_email_tetap_jalan(): void
    {
        Bus::fake([KirimPengingatEmailJob::class]);
        Http::fake();
        $setuju = $this->buatUser();
        $this->buatUser(setuju: false);
        DB::table('storage_account')->insert([
            'email_gmail_institusi' => 'kantor@gmail.test', 'status' => 'aktif',
            'google_refresh_token' => 'bukan-ciphertext-valid', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('pengingat:google-reconnect')->assertSuccessful();
        $this->artisan('pengingat:google-reconnect')->assertSuccessful();

        Bus::assertDispatchedTimes(KirimPengingatEmailJob::class, 4);
        $baris = RiwayatPengirimanWa::sole();
        $this->assertSame($setuju->id, $baris->user_id);
        $this->assertSame('google_reconnect', $baris->jenis);
        Http::assertNothingSent();
    }
}
