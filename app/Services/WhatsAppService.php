<?php

namespace App\Services;

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

        $apiUrl = config('services.whatsapp.api_url');
        $apiToken = config('services.whatsapp.api_token');

        if (blank($apiUrl) || blank($apiToken)) {
            Log::warning('WhatsAppService: WHATSAPP_API_URL/WHATSAPP_API_TOKEN belum diset, pengiriman dilewati.');

            return false;
        }

        try {
            $respons = Http::withToken($apiToken)
                ->timeout(10)
                ->post(rtrim($apiUrl, '/').'/send', [
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
