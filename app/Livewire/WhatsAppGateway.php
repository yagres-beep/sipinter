<?php

namespace App\Livewire;

use App\Models\RiwayatPengirimanWa;
use App\Services\WhatsAppService;
use Livewire\Component;

/**
 * Modul Tim SAKIP untuk memantau & mengelola tautan gateway WhatsApp
 * (whatsapp-gateway/, lihat App\Services\WhatsAppService) — melihat status
 * koneksi, scan QR saat pertama kali/ganti nomor, dan memutus tautan lama.
 */
class WhatsAppGateway extends Component
{
    public string $nomorTes = '';

    public string $pesanTes = 'Tes pengingat WhatsApp SIPINTER.';

    public bool $showResetConfirm = false;

    public function mount(): void
    {
        $this->nomorTes = (string) (auth()->user()->nomor_telepon ?? '');
    }

    public function bukaKonfirmasiReset(): void
    {
        $this->showResetConfirm = true;
    }

    public function batalkanReset(): void
    {
        $this->showResetConfirm = false;
    }

    public function resetSesi(): void
    {
        $this->showResetConfirm = false;

        $berhasil = app(WhatsAppService::class)->resetGateway();

        session()->flash(
            $berhasil ? 'status' : 'error',
            $berhasil
                ? 'Nomor lama diputus & sesi dihapus. Scan QR baru di bawah untuk menautkan nomor/perangkat lain.'
                : 'Gagal menghubungi gateway WhatsApp — pastikan gateway sedang menyala, lalu coba lagi.'
        );
    }

    /**
     * Kirim satu pesan langsung (tidak lewat antrean KirimPengingatWhatsAppJob)
     * supaya hasilnya (berhasil/gagal) langsung diketahui saat itu juga — dipakai
     * Tim SAKIP untuk memastikan nomor yang tertaut benar-benar bisa mengirim,
     * tanpa perlu menunggu kejadian pengingat sungguhan.
     */
    public function kirimTes(): void
    {
        $this->validate([
            'nomorTes' => ['required', 'string'],
            'pesanTes' => ['required', 'string', 'max:500'],
        ], [], ['nomorTes' => 'nomor telepon', 'pesanTes' => 'isi pesan']);

        $hasil = app(WhatsAppService::class)->kirimDenganAlasan($this->nomorTes, $this->pesanTes);

        session()->flash(
            $hasil['berhasil'] ? 'status' : 'error',
            $hasil['berhasil']
                ? "Pesan tes berhasil dikirim ke {$this->nomorTes}."
                : "Gagal mengirim pesan tes: {$hasil['alasan']}"
        );
    }

    public function render()
    {
        $gateway = app(WhatsAppService::class)->statusGateway();

        return view('livewire.whats-app-gateway', [
            'gatewayStatus' => $gateway['status'],
            'qrDataUrl' => $gateway['qrDataUrl'],
            'riwayat' => RiwayatPengirimanWa::latest()->limit(20)->get(),
        ]);
    }
}
