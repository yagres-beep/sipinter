<?php

namespace App\Livewire;

use App\Exceptions\WhatsAppGatewayException;
use App\Models\PengaturanPengingat;
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
 * tidak boleh menahan pemuatan halaman. Status diperiksa otomatis saat tab ini
 * terlihat (x-intersect) atau saat tombol ditekan.
 */
class PengaturanWhatsApp extends Component
{
    public const STATUS_TRANSISI = ['starting', 'waiting_lock', 'connecting', 'waiting_qr', 'reconnecting'];

    public ?array $statusGateway = null;

    public ?string $galatGateway = null;

    public ?string $qrDataUrl = null;

    public bool $sudahDiperiksa = false;

    public string $metodeSambung = 'qr';

    public string $nomorTes = '';

    public string $pesanTes = 'Tes pengingat WhatsApp SIPINTER.';

    public string $nomorPairing = '';

    public ?string $kodePairing = null;

    public bool $konfirmasiPutus = false;

    public string $gatewayUrlInput = '';

    public string $gatewayTokenInput = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->namaRole() === 'Tim SAKIP', 403);
    }

    public function mount(): void
    {
        $nomorUser = (string) (auth()->user()->nomor_telepon ?? '');
        $this->nomorTes = $nomorUser;
        $this->nomorPairing = $nomorUser;

        $pengaturan = PengaturanPengingat::ambilAman();
        $this->gatewayUrlInput = (string) ($pengaturan?->wa_gateway_url ?? '');
    }

    public function saatTabTerlihat(): void
    {
        if (! $this->sudahDiperiksa) {
            $this->periksa();
        }
    }

    public function pilihMetode(string $metode): void
    {
        if (in_array($metode, ['qr', 'pairing'], true)) {
            $this->metodeSambung = $metode;
        }
    }

    /** Tombol "Periksa Koneksi": timeout panjang supaya sempat membangunkan gateway. */
    public function periksa(): void
    {
        $this->ambilStatus(WhatsAppGatewayClient::TIMEOUT_BANGUN);
    }

    /** Dipanggil wire:poll selama status transisi (menunggu QR, menyambung). */
    public function segarkan(): void
    {
        $this->ambilStatus(WhatsAppGatewayClient::TIMEOUT_SINGKAT);
    }

    public function hubungkan(): void
    {
        $this->metodeSambung = 'qr';

        $this->jalankan(function (WhatsAppGatewayClient $gateway) {
            $gateway->hubungkan();
            $this->kodePairing = null;
            $this->ambilStatus(WhatsAppGatewayClient::TIMEOUT_SINGKAT);
        });
    }

    public function mintaKodePairing(): void
    {
        $this->metodeSambung = 'pairing';
        $this->resetErrorBag('nomorPairing');
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

    public function togglePengingatOtomatis(): void
    {
        $pengaturan = PengaturanPengingat::ambil();
        $statusBaru = ! PengaturanPengingat::waPengingatAktif();

        $pengaturan->update(['wa_pengingat_aktif' => $statusBaru]);
        PengaturanPengingat::lupakanCache();

        session()->flash(
            'status-wa',
            $statusBaru
                ? 'Pengingat otomatis WhatsApp berhasil diaktifkan.'
                : 'Pengingat otomatis WhatsApp dinonaktifkan.'
        );
    }

    public function simpanKonfigurasiGateway(): void
    {
        $this->validate([
            'gatewayUrlInput' => ['nullable', 'url', 'max:500'],
            'gatewayTokenInput' => ['nullable', 'string', 'min:16', 'max:500'],
        ], [
            'gatewayUrlInput.url' => 'URL Gateway harus diawali http:// atau https:// yang valid.',
            'gatewayTokenInput.min' => 'Token Gateway minimal 16 karakter.',
        ]);

        $pengaturan = PengaturanPengingat::ambil();
        $urlBersih = trim($this->gatewayUrlInput);
        $tokenBersih = trim($this->gatewayTokenInput);

        $dataUpdate = [
            'wa_gateway_url' => $urlBersih !== '' ? rtrim($urlBersih, '/') : null,
        ];

        if ($urlBersih === '') {
            $dataUpdate['wa_gateway_token'] = null;
        } elseif ($tokenBersih !== '') {
            $dataUpdate['wa_gateway_token'] = $tokenBersih;
        }

        $pengaturan->update($dataUpdate);
        PengaturanPengingat::lupakanCache();
        $this->gatewayTokenInput = '';

        session()->flash('status-wa', 'Pengaturan koneksi server gateway WhatsApp berhasil disimpan.');
        $this->periksa();
    }

    public function gunakanGatewayBawaan(): void
    {
        PengaturanPengingat::ambil()->update([
            'wa_gateway_url' => null,
            'wa_gateway_token' => null,
        ]);
        PengaturanPengingat::lupakanCache();
        $this->gatewayUrlInput = '';
        $this->gatewayTokenInput = '';

        session()->flash('status-wa', 'Dikembalikan ke Gateway WhatsApp bawaan server SIPINTER.');
        $this->periksa();
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
            session()->flash('status-wa', 'Koneksi WhatsApp diputus dan sesi dihapus. Silakan tautkan nomor lagi bila diperlukan.');
        });
    }

    public function pilihPenerimaTes(string $nomor): void
    {
        $this->nomorTes = $nomor;
        $this->resetErrorBag('nomorTes');
    }

    public function aktifkanWaSayaDanKirimTes(WhatsAppPengingatService $layanan): void
    {
        $this->resetErrorBag('nomorTes');
        $nomor = NomorWhatsApp::normalisasi($this->nomorTes);

        if ($nomor === null) {
            $this->addError('nomorTes', 'Nomor tujuan tidak valid. Gunakan format 08xxxxxxxxxx atau 628xxxxxxxxxx.');

            return;
        }

        /** @var User $user */
        $user = auth()->user();
        $user->forceFill([
            'nomor_telepon' => $this->nomorTes,
            'wa_opt_in' => true,
            'wa_opt_in_at' => $user->wa_opt_in_at ?? now(),
        ])->save();

        $this->kirimTes($layanan);
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
            $statusSekarang = $this->statusGateway['status'] ?? null;

            $this->qrDataUrl = $statusSekarang === 'waiting_qr'
                ? ($gateway->qr($timeout)['qr_data_url'] ?? null)
                : null;

            if ($statusSekarang === 'connected') {
                $this->kodePairing = null;
                $pengaturan = PengaturanPengingat::ambilAman();
                if ($pengaturan && $pengaturan->wa_pengingat_aktif === null) {
                    $pengaturan->update(['wa_pengingat_aktif' => true]);
                    PengaturanPengingat::lupakanCache();
                }
            }
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
        }
    }

    public function render()
    {
        $status = $this->statusGateway['status'] ?? null;
        $gateway = app(WhatsAppGatewayClient::class);
        $penggunaSetuju = User::where('wa_opt_in', true)
            ->whereNotNull('nomor_telepon')
            ->orderBy('nama')
            ->get(['id', 'nama', 'nomor_telepon']);

        return view('livewire.pengaturan-whats-app', [
            'status' => $status,
            'perluPolling' => in_array($status, self::STATUS_TRANSISI, true),
            'terkonfigurasi' => $gateway->terkonfigurasi(),
            'memakaiGatewayBawaan' => $gateway->memakaiGatewayBawaan(),
            'urlGatewayAktif' => $gateway->url(),
            'pengingatAktif' => PengaturanPengingat::waPengingatAktif(),
            'jumlahSetuju' => User::where('wa_opt_in', true)->count(),
            'penggunaSetuju' => $penggunaSetuju,
            'riwayat' => RiwayatPengirimanWa::with('user:id,nama')->latest('id')->limit(20)->get(),
        ]);
    }
}
