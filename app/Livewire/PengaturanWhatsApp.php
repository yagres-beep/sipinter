<?php

namespace App\Livewire;

use App\Exceptions\WhatsAppGatewayException;
use App\Models\RiwayatPengirimanWa;
use App\Models\User;
use App\Services\WhatsAppGatewayClient;
use App\Services\WhatsAppPengingatService;
use App\Support\NomorWhatsApp;
use DomainException;
use Livewire\Component;

/**
 * Kelola koneksi gateway WhatsApp (tab Pengingat di Kelola Pengguna).
 *
 * render() sengaja TIDAK memanggil gateway: membuka halaman Kelola Pengguna
 * tidak boleh membangunkan gateway Render Free atau menunggu ±1 menit. Status
 * baru diperiksa saat tab ini terlihat (x-intersect) atau tombol ditekan.
 */
class PengaturanWhatsApp extends Component
{
    public const STATUS_TRANSISI = ['starting', 'waiting_lock', 'connecting', 'waiting_qr', 'reconnecting'];

    public ?array $statusGateway = null;

    public ?string $galatGateway = null;

    public ?string $qrDataUrl = null;

    public bool $sudahDiperiksa = false;

    public string $nomorTes = '';

    public string $pesanTes = 'Tes pengingat WhatsApp SIPINTER.';

    public string $nomorPairing = '';

    public ?string $kodePairing = null;

    public bool $konfirmasiPutus = false;

    public function boot(): void
    {
        abort_unless(auth()->user()?->namaRole() === 'Tim SAKIP', 403);
    }

    public function mount(): void
    {
        $this->nomorTes = (string) (auth()->user()->nomor_telepon ?? '');
    }

    public function saatTabTerlihat(): void
    {
        if (! $this->sudahDiperiksa) {
            $this->periksa();
        }
    }

    /** Tombol "Periksa Koneksi": timeout panjang supaya sempat membangunkan gateway. */
    public function periksa(): void
    {
        $this->ambilStatus(WhatsAppGatewayClient::TIMEOUT_BANGUN);
    }

    /** Dipanggil wire:poll hanya selama status transisi (menunggu QR, menyambung). */
    public function segarkan(): void
    {
        $this->ambilStatus(WhatsAppGatewayClient::TIMEOUT_SINGKAT);
    }

    public function hubungkan(): void
    {
        $this->jalankan(function (WhatsAppGatewayClient $gateway) {
            $gateway->hubungkan();
            $this->kodePairing = null;
            $this->ambilStatus(WhatsAppGatewayClient::TIMEOUT_SINGKAT);
        });
    }

    public function mintaKodePairing(): void
    {
        $nomor = NomorWhatsApp::normalisasi($this->nomorPairing);

        if ($nomor === null) {
            $this->addError('nomorPairing', 'Nomor WhatsApp kantor tidak valid. Gunakan format 08xxxxxxxxxx atau 628xxxxxxxxxx.');

            return;
        }

        $this->jalankan(function (WhatsAppGatewayClient $gateway) use ($nomor) {
            $this->kodePairing = $gateway->kodePairing($nomor);
            $this->ambilStatus(WhatsAppGatewayClient::TIMEOUT_SINGKAT);
        });
    }

    public function mintaPutus(): void
    {
        $this->konfirmasiPutus = true;
    }

    public function batalPutus(): void
    {
        $this->konfirmasiPutus = false;
    }

    public function putuskan(): void
    {
        $this->konfirmasiPutus = false;

        $this->jalankan(function (WhatsAppGatewayClient $gateway) {
            $gateway->putuskan();
            $this->qrDataUrl = null;
            $this->kodePairing = null;
            $this->ambilStatus(WhatsAppGatewayClient::TIMEOUT_SINGKAT);
            session()->flash('status-wa', 'Koneksi WhatsApp diputus dan sesi dihapus. Tekan "Hubungkan WhatsApp" untuk menautkan nomor lagi.');
        });
    }

    public function kirimTes(WhatsAppPengingatService $layanan): void
    {
        $this->validate([
            'nomorTes' => ['required', 'string', 'max:30'],
            'pesanTes' => ['required', 'string', 'max:1000'],
        ], [], ['nomorTes' => 'nomor tujuan', 'pesanTes' => 'isi pesan']);

        try {
            $baris = $layanan->kirimTes($this->nomorTes, $this->pesanTes);
        } catch (DomainException $e) {
            $this->addError('nomorTes', $e->getMessage());

            return;
        }

        if ($baris->status === RiwayatPengirimanWa::STATUS_ANTRE) {
            session()->flash('error-wa', "Pesan tes dicatat tetapi gateway belum bisa dihubungi: {$baris->alasan_gagal} Scheduler akan mencoba lagi otomatis.");
        } elseif ($baris->status === RiwayatPengirimanWa::STATUS_GAGAL) {
            session()->flash('error-wa', "Pesan tes ditolak: {$baris->alasan_gagal}");
        } else {
            session()->flash('status-wa', 'Pesan tes diterima gateway ('.$baris->labelStatus().'). Status akhirnya muncul di Riwayat Pengiriman.');
        }
    }

    protected function ambilStatus(int $timeout): void
    {
        $this->sudahDiperiksa = true;
        $gateway = app(WhatsAppGatewayClient::class);

        try {
            $this->statusGateway = $gateway->status($timeout);
            $this->galatGateway = null;
            $this->qrDataUrl = ($this->statusGateway['status'] ?? null) === 'waiting_qr'
                ? ($gateway->qr($timeout)['qr_data_url'] ?? null)
                : null;
        } catch (WhatsAppGatewayException $e) {
            $this->galatGateway = $e->getMessage();
            $this->qrDataUrl = null;
        }
    }

    protected function jalankan(callable $aksi): void
    {
        try {
            $aksi(app(WhatsAppGatewayClient::class));
        } catch (WhatsAppGatewayException $e) {
            $this->galatGateway = $e->getMessage();
            session()->flash('error-wa', $e->getMessage());
        }
    }

    public function render()
    {
        $status = $this->statusGateway['status'] ?? null;

        return view('livewire.pengaturan-whats-app', [
            'status' => $status,
            'perluPolling' => in_array($status, self::STATUS_TRANSISI, true),
            'terkonfigurasi' => app(WhatsAppGatewayClient::class)->terkonfigurasi(),
            'pengingatAktif' => (bool) config('services.whatsapp.pengingat_aktif'),
            'jumlahSetuju' => User::where('wa_opt_in', true)->count(),
            'riwayat' => RiwayatPengirimanWa::with('user:id,nama')->latest('id')->limit(20)->get(),
        ]);
    }
}
