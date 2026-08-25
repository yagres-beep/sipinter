<?php

namespace App\Livewire;

use App\Services\WhatsAppService;
use Livewire\Component;

/**
 * Modul Tim SAKIP untuk memantau & mengelola tautan gateway WhatsApp
 * (whatsapp-gateway/, lihat App\Services\WhatsAppService) — melihat status
 * koneksi, scan QR saat pertama kali/ganti nomor, dan memutus tautan lama.
 */
class WhatsAppGateway extends Component
{
    public function resetSesi(): void
    {
        $berhasil = app(WhatsAppService::class)->resetGateway();

        session()->flash(
            $berhasil ? 'status' : 'error',
            $berhasil
                ? 'Nomor lama diputus & sesi dihapus. Scan QR baru di bawah untuk menautkan nomor/perangkat lain.'
                : 'Gagal menghubungi gateway WhatsApp — pastikan gateway sedang menyala, lalu coba lagi.'
        );
    }

    public function render()
    {
        $gateway = app(WhatsAppService::class)->statusGateway();

        return view('livewire.whats-app-gateway', [
            'gatewayStatus' => $gateway['status'],
            'qrDataUrl' => $gateway['qrDataUrl'],
        ]);
    }
}
