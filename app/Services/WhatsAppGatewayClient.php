<?php

namespace App\Services;

use App\Exceptions\WhatsAppGatewayException;
use App\Models\PengaturanPengingat;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Klien HTTP ke gateway WhatsApp (bawaan di wa-gateway/ atau eksternal).
 * Hanya dipanggil dari sisi server; token tidak pernah dikirim ke browser.
 */
class WhatsAppGatewayClient
{
    public const URL_BAWAAN = 'http://127.0.0.1:3001';

    public const TIMEOUT_SINGKAT = 10;

    public const TIMEOUT_BANGUN = 75;

    public function url(): string
    {
        $pengaturan = PengaturanPengingat::ambilAman();
        if (filled($pengaturan?->wa_gateway_url)) {
            return rtrim(trim((string) $pengaturan->wa_gateway_url), '/');
        }

        $envUrl = trim((string) config('services.whatsapp.gateway_url'));
        if (filled($envUrl)) {
            return rtrim($envUrl, '/');
        }

        return self::URL_BAWAAN;
    }

    public function token(): string
    {
        $pengaturan = PengaturanPengingat::ambilAman();
        if (filled($pengaturan?->wa_gateway_token)) {
            return trim((string) $pengaturan->wa_gateway_token);
        }

        $envToken = trim((string) config('services.whatsapp.gateway_token'));
        if (filled($envToken)) {
            return $envToken;
        }

        if ($this->memakaiGatewayBawaan()) {
            $appKey = trim((string) config('app.key')) ?: 'sipinter-wa-gateway-default-key';

            return hash_hmac('sha256', 'sipinter-wa-gateway-token', $appKey);
        }

        return '';
    }

    public function memakaiGatewayBawaan(): bool
    {
        return in_array($this->url(), ['http://127.0.0.1:3001', 'http://localhost:3001'], true);
    }

    public function terkonfigurasi(): bool
    {
        return filled($this->url()) && filled($this->token());
    }

    public function status(int $timeout = self::TIMEOUT_SINGKAT): array
    {
        return $this->permintaan('get', '/status', [], $timeout);
    }

    public function qr(int $timeout = self::TIMEOUT_SINGKAT): array
    {
        return $this->permintaan('get', '/qr', [], $timeout);
    }

    public function hubungkan(int $timeout = self::TIMEOUT_BANGUN): array
    {
        return $this->permintaan('post', '/connect', [], $timeout);
    }

    public function kodePairing(string $nomor, int $timeout = self::TIMEOUT_BANGUN): string
    {
        return (string) ($this->permintaan('post', '/pairing-code', ['phone' => $nomor], $timeout)['pairing_code'] ?? '');
    }

    public function putuskan(int $timeout = 30): array
    {
        return $this->permintaan('post', '/logout', [], $timeout);
    }

    public function kirim(string $kunci, string $nomor, string $pesan, int $timeout = self::TIMEOUT_BANGUN): array
    {
        return $this->permintaan('post', '/send-message', [
            'idempotency_key' => $kunci,
            'phone' => $nomor,
            'message' => $pesan,
        ], $timeout);
    }

    /**
     * @param  list<string>  $kunci
     * @return list<array<string, mixed>>
     */
    public function cariStatus(array $kunci, int $timeout = 30): array
    {
        return $this->permintaan('post', '/messages/lookup', ['keys' => array_values($kunci)], $timeout)['messages'] ?? [];
    }

    protected function permintaan(string $metode, string $path, array $data, int $timeout): array
    {
        if (! $this->terkonfigurasi()) {
            throw new WhatsAppGatewayException('Gateway WhatsApp belum dikonfigurasi (URL atau Token gateway kosong).');
        }

        $http = $this->buatHttp($timeout);

        try {
            $respons = $metode === 'get' ? $http->get($path) : $http->post($path, $data);
        } catch (ConnectionException) {
            if ($this->cobaNyalakanGatewayLokal()) {
                try {
                    $httpUlang = $this->buatHttp($timeout);
                    $respons = $metode === 'get' ? $httpUlang->get($path) : $httpUlang->post($path, $data);
                } catch (ConnectionException) {
                    throw $this->galatKoneksi();
                }
            } else {
                throw $this->galatKoneksi();
            }
        }

        if ($respons->successful()) {
            return (array) $respons->json();
        }

        throw $this->galatDariRespons($respons);
    }

    protected function buatHttp(int $timeout)
    {
        return Http::baseUrl($this->url())
            ->withToken($this->token())
            ->acceptJson()
            ->connectTimeout(min(15, $timeout))
            ->timeout($timeout);
    }

    protected function galatKoneksi(): WhatsAppGatewayException
    {
        return new WhatsAppGatewayException(
            'Gateway WhatsApp tidak dapat dihubungi (mungkin sedang menyala/bangun). Coba lagi sebentar.',
            bisaDiulang: true,
        );
    }

    protected function cobaNyalakanGatewayLokal(): bool
    {
        if (app()->runningUnitTests() || ! $this->memakaiGatewayBawaan()) {
            return false;
        }

        $script = base_path('wa-gateway/src/index.js');
        if (! is_file($script)) {
            return false;
        }

        try {
            $cwd = base_path('wa-gateway');
            $logPath = storage_path('logs/wa-gateway.log');
            if (PHP_OS_FAMILY === 'Windows') {
                $cmd = 'start /B "" node '.escapeshellarg($script).' >> '.escapeshellarg($logPath).' 2>&1';
                pclose(popen('cd /d '.escapeshellarg($cwd).' && '.$cmd, 'r'));
            } else {
                $nodeBin = is_executable('/usr/local/bin/node')
                    ? '/usr/local/bin/node'
                    : (is_executable('/usr/bin/node') ? '/usr/bin/node' : 'node');
                $cmd = 'nohup '.escapeshellarg($nodeBin).' '.escapeshellarg($script).' >> '.escapeshellarg($logPath).' 2>&1 &';
                exec('cd '.escapeshellarg($cwd).' && '.$cmd);
            }

            // Tunggu hingga port 3001 siap menerima koneksi (maks 4 detik)
            for ($i = 0; $i < 16; $i++) {
                usleep(250_000);
                $fp = @fsockopen('127.0.0.1', 3001, $errno, $errstr, 0.2);
                if (is_resource($fp)) {
                    fclose($fp);

                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    protected function galatDariRespons(Response $respons): WhatsAppGatewayException
    {
        $status = $respons->status();
        $kode = $respons->json('error');
        $pesanGateway = $respons->json('message');

        return match (true) {
            $status === 401 => new WhatsAppGatewayException('Token gateway ditolak. Samakan token gateway di pengaturan dengan GATEWAY_API_TOKEN.', false, $status, $kode),
            $status === 422 => new WhatsAppGatewayException('Gateway menolak data: '.collect((array) $respons->json('errors'))->flatten()->implode(' '), false, $status, $kode),
            $status === 409 => new WhatsAppGatewayException($pesanGateway ?: 'Permintaan ditolak gateway.', false, $status, $kode),
            $status === 429 || $status >= 500 => new WhatsAppGatewayException($pesanGateway ?: "Gateway sedang tidak siap (HTTP {$status}). Coba lagi.", true, $status, $kode),
            default => new WhatsAppGatewayException($pesanGateway ?: "Gateway mengembalikan HTTP {$status}.", false, $status, $kode),
        };
    }
}
