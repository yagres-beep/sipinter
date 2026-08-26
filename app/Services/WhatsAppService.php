<?php

namespace App\Services;

use App\Models\RiwayatPengirimanWa;
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
        return $this->kirimDenganAlasan($nomorTelepon, $pesan)['berhasil'];
    }

    /**
     * Sama seperti kirim(), tapi juga mengembalikan alasan gagal & mencatat
     * setiap percobaan (berhasil maupun gagal) ke tabel riwayat_pengiriman_wa
     * — dipakai fitur "Kirim Pesan Tes" & kartu "Riwayat Pengiriman" di
     * halaman Pengingat WA supaya Tim SAKIP tahu persis apa yang terjadi,
     * bukan cuma "gagal" tanpa detail.
     *
     * @return array{berhasil: bool, alasan: ?string}
     */
    public function kirimDenganAlasan(?string $nomorTelepon, string $pesan): array
    {
        $nomor = $this->normalisasiNomor($nomorTelepon);

        if ($nomor === null) {
            return $this->catat($nomorTelepon ?? '', $pesan, false, 'Nomor telepon kosong/tidak valid.');
        }

        if (! $this->terkonfigurasi()) {
            return $this->catat($nomor, $pesan, false, 'Gateway belum dikonfigurasi (WHATSAPP_API_URL/WHATSAPP_API_TOKEN belum diisi di server).');
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

                $alasan = $respons->json('pesan') ?: "Gateway menolak permintaan (HTTP {$respons->status()}).";

                return $this->catat($nomor, $pesan, false, $alasan);
            }

            return $this->catat($nomor, $pesan, true, null);
        } catch (\Throwable $e) {
            Log::warning('WhatsAppService: gagal menghubungi gateway.', [
                'nomor' => $nomor,
                'pesan_error' => $e->getMessage(),
            ]);

            return $this->catat($nomor, $pesan, false, 'Tidak bisa menghubungi gateway: '.$e->getMessage());
        }
    }

    /**
     * @return array{berhasil: bool, alasan: ?string}
     */
    protected function catat(string $nomor, string $pesan, bool $berhasil, ?string $alasan): array
    {
        try {
            RiwayatPengirimanWa::create([
                'nomor_telepon' => $nomor,
                'pesan' => $pesan,
                'berhasil' => $berhasil,
                'alasan_gagal' => $alasan,
            ]);
        } catch (\Throwable $e) {
            // Riwayat cuma catatan tambahan — gagal mencatat tidak boleh mengubah
            // hasil pengiriman yang sesungguhnya.
            Log::warning('WhatsAppService: gagal mencatat riwayat pengiriman.', ['pesan_error' => $e->getMessage()]);
        }

        return ['berhasil' => $berhasil, 'alasan' => $alasan];
    }

    /**
     * Status koneksi gateway + QR (bila sedang menunggu discan), dipakai
     * App\Livewire\WhatsAppGateway untuk menampilkan halaman kelola tautan
     * WhatsApp langsung di SIPINTER. 'status' bernilai 'error' (bukan salah
     * satu status asli gateway) bila gateway tidak terkonfigurasi/tidak
     * bisa dihubungi sama sekali.
     *
     * $timeoutDetik dibuat bisa diatur karena gateway di-hosting di Render
     * free tier yang "tidur" setelah idle & butuh puluhan detik untuk
     * bangun saat menerima request pertama — polling otomatis (wire:poll)
     * pakai timeout pendek (default) supaya halaman tetap responsif, tapi
     * aksi manual "Coba Sambungkan Ulang" (lihat sambungkanUlang() di
     * WhatsAppGateway) butuh timeout panjang supaya sempat menunggu gateway
     * selesai bangun sebelum dianggap gagal.
     *
     * @return array{status: string, qrDataUrl: ?string}
     */
    public function statusGateway(int $timeoutDetik = 10): array
    {
        if (! $this->terkonfigurasi()) {
            return ['status' => 'error', 'qrDataUrl' => null];
        }

        try {
            $respons = $this->http($timeoutDetik)->get('/qr-data');

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
     * Minta gateway mencoba menyambungkan ulang sesi WhatsApp yang nyangkut
     * di status 'connecting'/'disconnected' TANPA menghapus sesi tersimpan
     * (beda dari resetGateway() yang selalu logout + minta QR baru). Dipakai
     * tombol "Coba Sambungkan Ulang" saat gateway sudah bisa dihubungi tapi
     * belum 'connected'/'waiting_for_qr'.
     */
    public function reconnectGateway(): bool
    {
        if (! $this->terkonfigurasi()) {
            return false;
        }

        try {
            return $this->http(45)->post('/reconnect')->successful();
        } catch (\Throwable $e) {
            Log::warning('WhatsAppService: gagal menyambungkan ulang gateway.', ['pesan_error' => $e->getMessage()]);

            return false;
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

    protected function http(int $timeoutDetik = 10): PendingRequest
    {
        $apiUrl = rtrim((string) config('services.whatsapp.api_url'), '/');

        return Http::withToken(config('services.whatsapp.api_token'))
            ->timeout($timeoutDetik)
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
