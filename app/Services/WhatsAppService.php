<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pengirim pesan pengingat lewat gateway WhatsApp self-host (Baileys, lihat
 * whatsapp-gateway/ & config/services.php 'whatsapp'). Mengikuti pola
 * GoogleDriveService: kegagalan layanan eksternal TIDAK BOLEH menghentikan
 * alur utama aplikasi (ajukan/verifikasi/dsb tetap harus berhasil walau WA
 * gagal terkirim) — semua kegagalan ditelan jadi false + Log::warning.
 */
class WhatsAppService
{
    /**
     * Kirim satu pesan WA. Mengembalikan false (tanpa exception) bila nomor
     * kosong atau gateway gagal/tidak terkonfigurasi — dipanggil dari job
     * antrian (App\Jobs\KirimPengingatWhatsAppJob), bukan langsung dari
     * request pengguna, jadi aman untuk retry di sana bila perlu.
     */
    public function kirim(?string $nomorTelepon, string $pesan): bool
    {
        $nomor = $this->normalisasiNomor($nomorTelepon);

        if ($nomor === null) {
            return false;
        }

        if (! $this->terkonfigurasi()) {
            return false;
        }

        try {
            $respons = $this->http()->post('/send', [
                'nomor' => $nomor,
                'pesan' => $pesan,
            ]);

            if ($respons->failed()) {
                Log::warning('WhatsAppService: gateway menolak pengiriman.', [
                    'nomor' => $nomor,
                    'status' => $respons->status(),
                    'body' => $respons->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('WhatsAppService: gagal menghubungi gateway.', [
                'nomor' => $nomor,
                'pesan_error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Status koneksi gateway + QR (bila sedang menunggu discan), dipakai
     * App\Livewire\WhatsAppGateway untuk menampilkan halaman kelola tautan
     * WhatsApp langsung di SIPINTER. 'status' bernilai 'error' (bukan salah
     * satu status asli gateway) bila gateway tidak terkonfigurasi/tidak
     * bisa dihubungi sama sekali.
     *
     * @return array{status: string, qrDataUrl: ?string}
     */
    public function statusGateway(): array
    {
        if (! $this->terkonfigurasi()) {
            return ['status' => 'error', 'qrDataUrl' => null];
        }

        try {
            $respons = $this->http()->get('/qr-data');

            if ($respons->failed()) {
                return ['status' => 'error', 'qrDataUrl' => null];
            }

            return [
                'status' => $respons->json('status', 'error'),
                'qrDataUrl' => $respons->json('qrDataUrl'),
            ];
        } catch (\Throwable $e) {
            Log::warning('WhatsAppService: gagal mengambil status gateway.', ['pesan_error' => $e->getMessage()]);

            return ['status' => 'error', 'qrDataUrl' => null];
        }
    }

    /**
     * Putuskan nomor yang sedang tertaut & hapus sesi tersimpan di gateway,
     * supaya QR baru bisa discan untuk menautkan nomor/perangkat lain.
     */
    public function resetGateway(): bool
    {
        if (! $this->terkonfigurasi()) {
            return false;
        }

        try {
            return $this->http()->post('/reset')->successful();
        } catch (\Throwable $e) {
            Log::warning('WhatsAppService: gagal reset sesi gateway.', ['pesan_error' => $e->getMessage()]);

            return false;
        }
    }

    protected function terkonfigurasi(): bool
    {
        if (blank(config('services.whatsapp.api_url')) || blank(config('services.whatsapp.api_token'))) {
            Log::warning('WhatsAppService: WHATSAPP_API_URL/WHATSAPP_API_TOKEN belum diset.');

            return false;
        }

        return true;
    }

    protected function http(): PendingRequest
    {
        $apiUrl = rtrim((string) config('services.whatsapp.api_url'), '/');

        return Http::withToken(config('services.whatsapp.api_token'))
            ->timeout(10)
            ->baseUrl($apiUrl);
    }

    /**
     * Normalisasi nomor Indonesia ke format 62xxxxxxxxxx yang dipakai Baileys
     * (WhatsApp ID). Kolom users.nomor_telepon bebas format (mis. "0812-3456-
     * 7890", "+62 812 3456 7890"), jadi buang semua karakter non-digit dulu.
     */
    protected function normalisasiNomor(?string $nomorTelepon): ?string
    {
        if (blank($nomorTelepon)) {
            return null;
        }

        $digit = preg_replace('/\D/', '', $nomorTelepon);

        if (blank($digit)) {
            return null;
        }

        if (str_starts_with($digit, '0')) {
            $digit = '62'.substr($digit, 1);
        }

        return $digit;
    }
}
