<?php

namespace App\Services;

use App\Exceptions\WhatsAppGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Klien HTTP ke gateway WhatsApp (repo sipinter-wa-gateway). Hanya dipanggil
 * dari sisi server; token tidak pernah dikirim ke browser.
 *
 * Gateway di Render Free bisa sedang tidur: permintaan pertama ditahan Render
 * sampai layanan bangun (sekitar satu menit). Karena itu aksi yang memang
 * ditunggu admin atau dijalankan scheduler memakai timeout panjang, sedangkan
 * polling status di halaman memakai timeout pendek.
 */
class WhatsAppGatewayClient
{
    public const TIMEOUT_SINGKAT = 10;

    public const TIMEOUT_BANGUN = 75;

    public function terkonfigurasi(): bool
    {
        return filled(config('services.whatsapp.gateway_url')) && filled(config('services.whatsapp.gateway_token'));
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
            throw new WhatsAppGatewayException('Gateway WhatsApp belum dikonfigurasi (WHATSAPP_GATEWAY_URL/WHATSAPP_GATEWAY_TOKEN kosong).');
        }

        $http = Http::baseUrl(rtrim((string) config('services.whatsapp.gateway_url'), '/'))
            ->withToken((string) config('services.whatsapp.gateway_token'))
            ->acceptJson()
            ->connectTimeout(min(15, $timeout))
            ->timeout($timeout);

        try {
            $respons = $metode === 'get' ? $http->get($path) : $http->post($path, $data);
        } catch (ConnectionException) {
            throw new WhatsAppGatewayException(
                'Gateway WhatsApp tidak dapat dihubungi (mungkin sedang tidur/bangun di Render). Coba lagi sebentar.',
                bisaDiulang: true,
            );
        }

        if ($respons->successful()) {
            return (array) $respons->json();
        }

        throw $this->galatDariRespons($respons);
    }

    protected function galatDariRespons(Response $respons): WhatsAppGatewayException
    {
        $status = $respons->status();
        $kode = $respons->json('error');
        $pesanGateway = $respons->json('message');

        return match (true) {
            $status === 401 => new WhatsAppGatewayException('Token gateway ditolak. Samakan WHATSAPP_GATEWAY_TOKEN dengan GATEWAY_API_TOKEN di gateway.', false, $status, $kode),
            $status === 422 => new WhatsAppGatewayException('Gateway menolak data: '.collect((array) $respons->json('errors'))->flatten()->implode(' '), false, $status, $kode),
            $status === 409 => new WhatsAppGatewayException($pesanGateway ?: 'Permintaan ditolak gateway.', false, $status, $kode),
            $status === 429 || $status >= 500 => new WhatsAppGatewayException($pesanGateway ?: "Gateway sedang tidak siap (HTTP {$status}). Coba lagi.", true, $status, $kode),
            default => new WhatsAppGatewayException($pesanGateway ?: "Gateway mengembalikan HTTP {$status}.", false, $status, $kode),
        };
    }
}
