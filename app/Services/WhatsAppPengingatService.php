<?php

namespace App\Services;

use App\Exceptions\WhatsAppGatewayException;
use App\Models\RiwayatPengirimanWa;
use App\Models\User;
use App\Support\NomorWhatsApp;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pengingat WhatsApp, berdampingan dengan EmailPengingatService (email tetap
 * jalan apa pun yang terjadi di sini).
 *
 * Alur: antrekan() hanya menulis baris 'antre' ke riwayat_pengiriman_wa (cepat,
 * tanpa panggilan jaringan, aman di dalam request pengguna). Command
 * `whatsapp:proses-antrean` lalu menyerahkannya ke gateway dan menyinkronkan
 * status. idempotency_key unik per kejadian+penerima, sehingga listener atau
 * command yang berjalan dua kali tidak menghasilkan pesan ganda.
 */
class WhatsAppPengingatService
{
    public const BATAS_JAM_ANTRE = 24;

    public function __construct(protected WhatsAppGatewayClient $gateway) {}

    public function antrekan(User $user, string $jenis, string $subjek, string $pesan, string $kunciKejadian): void
    {
        try {
            if (! config('services.whatsapp.pengingat_aktif') || ! $user->wa_opt_in) {
                return;
            }

            $nomor = NomorWhatsApp::normalisasi($user->nomor_telepon);

            // Savepoint: bila insert gagal, transaksi pemanggil (penyimpanan data
            // utama SIPINTER) tidak ikut batal.
            DB::transaction(fn () => RiwayatPengirimanWa::createOrFirst(
                ['idempotency_key' => $this->kunci("{$jenis}:{$kunciKejadian}:u{$user->id}")],
                [
                    'user_id' => $user->id,
                    'jenis' => $jenis,
                    'nomor_telepon' => $nomor ?? (string) $user->nomor_telepon,
                    'pesan' => $this->teks($subjek, $pesan),
                    'berhasil' => false,
                    'status' => $nomor ? RiwayatPengirimanWa::STATUS_ANTRE : RiwayatPengirimanWa::STATUS_GAGAL,
                    'alasan_gagal' => $nomor ? null : 'Nomor telepon di profil tidak valid untuk WhatsApp.',
                ]
            ));
        } catch (\Throwable $e) {
            Log::warning('WhatsAppPengingatService: gagal mengantrekan pengingat.', [
                'jenis' => $jenis,
                'user_id' => $user->id,
                'pesan_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pesan uji dari halaman pengaturan. Tujuan wajib milik pengguna yang sudah
     * menyetujui pesan WhatsApp, supaya fitur ini tidak bisa dipakai mengirim
     * pesan ke nomor sembarang. Langsung diserahkan ke gateway (admin menunggu).
     *
     * @throws DomainException bila nomor tidak valid / belum menyetujui.
     */
    public function kirimTes(string $nomorInput, string $pesan): RiwayatPengirimanWa
    {
        $nomor = NomorWhatsApp::normalisasi($nomorInput);

        if ($nomor === null) {
            throw new DomainException('Nomor tujuan tidak valid. Gunakan format 08xxxxxxxxxx atau 628xxxxxxxxxx.');
        }

        $penerima = User::where('wa_opt_in', true)
            ->whereNotNull('nomor_telepon')
            ->get()
            ->first(fn (User $user) => NomorWhatsApp::normalisasi($user->nomor_telepon) === $nomor);

        if (! $penerima) {
            throw new DomainException('Nomor tujuan harus milik pengguna SIPINTER yang sudah menyetujui pesan WhatsApp di halaman Profil.');
        }

        $baris = RiwayatPengirimanWa::create([
            'user_id' => $penerima->id,
            'jenis' => 'tes',
            'idempotency_key' => 'tes:'.Str::uuid(),
            'nomor_telepon' => $nomor,
            'pesan' => Str::limit($pesan, 4000, ''),
            'berhasil' => false,
            'status' => RiwayatPengirimanWa::STATUS_ANTRE,
        ]);

        $this->serahkan($baris);

        return $baris->refresh();
    }

    /**
     * @return array{diserahkan: int, gagal: int, kedaluwarsa: int, disinkronkan: int, gateway_tidak_siap: bool}
     */
    public function prosesAntrean(int $batas = 20): array
    {
        $hasil = ['diserahkan' => 0, 'gagal' => 0, 'kedaluwarsa' => 0, 'disinkronkan' => 0, 'gateway_tidak_siap' => false];

        $hasil['kedaluwarsa'] = RiwayatPengirimanWa::where('status', RiwayatPengirimanWa::STATUS_ANTRE)
            ->where('created_at', '<', now()->subHours(self::BATAS_JAM_ANTRE))
            ->update([
                'status' => RiwayatPengirimanWa::STATUS_KEDALUWARSA,
                'alasan_gagal' => 'Tidak berhasil diserahkan ke gateway dalam '.self::BATAS_JAM_ANTRE.' jam.',
                'updated_at' => now(),
            ]);

        if (! $this->gateway->terkonfigurasi()) {
            return $hasil;
        }

        $antrean = RiwayatPengirimanWa::where('status', RiwayatPengirimanWa::STATUS_ANTRE)
            ->whereNotNull('idempotency_key')
            ->orderBy('id')
            ->limit($batas)
            ->get();

        foreach ($antrean as $baris) {
            $hasilSerah = $this->serahkan($baris);

            if ($hasilSerah === 'ok') {
                $hasil['diserahkan']++;
            } elseif ($hasilSerah === 'gagal') {
                $hasil['gagal']++;
            } else {
                // Gateway tidak terjangkau: hentikan putaran ini, coba lagi menit berikutnya.
                $hasil['gateway_tidak_siap'] = true;

                break;
            }
        }

        if (! $hasil['gateway_tidak_siap']) {
            $hasil['disinkronkan'] = $this->sinkronkanStatus();
        }

        return $hasil;
    }

    /**
     * @return 'ok'|'gagal'|'tunda'
     */
    public function serahkan(RiwayatPengirimanWa $baris, int $timeout = WhatsAppGatewayClient::TIMEOUT_BANGUN): string
    {
        $baris->percobaan_kirim++;

        try {
            $respons = $this->gateway->kirim($baris->idempotency_key, $baris->nomor_telepon, $baris->pesan, $timeout);
            $baris->diserahkan_ke_gateway_at ??= now();
            $baris->alasan_gagal = null;
            $this->terapkanStatusGateway($baris, $respons);
            $baris->save();

            return 'ok';
        } catch (WhatsAppGatewayException $e) {
            $baris->alasan_gagal = $e->getMessage();

            // Token salah = masalah konfigurasi, bukan masalah pesan ini: tunda
            // (kedaluwarsa sendiri setelah 24 jam bila tidak diperbaiki).
            if ($e->bisaDiulang || $e->statusHttp === 401) {
                $baris->save();

                return 'tunda';
            }

            $baris->status = RiwayatPengirimanWa::STATUS_GAGAL;
            $baris->save();

            return 'gagal';
        }
    }

    public function sinkronkanStatus(): int
    {
        $baris = RiwayatPengirimanWa::whereIn('status', [RiwayatPengirimanWa::STATUS_DI_GATEWAY, RiwayatPengirimanWa::STATUS_TERKIRIM])
            ->where('diserahkan_ke_gateway_at', '>=', now()->subDays(3))
            ->orderBy('id')
            ->limit(100)
            ->get();

        if ($baris->isEmpty()) {
            return 0;
        }

        try {
            $dariGateway = collect($this->gateway->cariStatus($baris->pluck('idempotency_key')->all()))->keyBy('idempotency_key');
        } catch (WhatsAppGatewayException $e) {
            Log::info('WhatsAppPengingatService: sinkron status ditunda.', ['pesan_error' => $e->getMessage()]);

            return 0;
        }

        $berubah = 0;

        foreach ($baris as $item) {
            $data = $dariGateway->get($item->idempotency_key);

            if ($data === null) {
                if ($item->status === RiwayatPengirimanWa::STATUS_DI_GATEWAY && $item->diserahkan_ke_gateway_at->lt(now()->subDay())) {
                    $item->status = RiwayatPengirimanWa::STATUS_TIDAK_PASTI;
                    $item->alasan_gagal = 'Pesan tidak ditemukan lagi di gateway.';
                }
            } else {
                $this->terapkanStatusGateway($item, $data);
            }

            if ($item->isDirty()) {
                $item->save();
                $berubah++;
            }
        }

        return $berubah;
    }

    protected function terapkanStatusGateway(RiwayatPengirimanWa $baris, array $data): void
    {
        $status = match ($data['status'] ?? null) {
            'queued', 'sending' => RiwayatPengirimanWa::STATUS_DI_GATEWAY,
            'sent' => RiwayatPengirimanWa::STATUS_TERKIRIM,
            'delivered', 'read' => RiwayatPengirimanWa::STATUS_DITERIMA,
            'failed' => RiwayatPengirimanWa::STATUS_GAGAL,
            'expired' => RiwayatPengirimanWa::STATUS_KEDALUWARSA,
            'unknown' => RiwayatPengirimanWa::STATUS_TIDAK_PASTI,
            default => null,
        };

        if ($status === null) {
            return;
        }

        $baris->status = $status;
        $baris->berhasil = in_array($status, [RiwayatPengirimanWa::STATUS_TERKIRIM, RiwayatPengirimanWa::STATUS_DITERIMA], true);

        if (! empty($data['sent_at'])) {
            $baris->terkirim_at ??= $data['sent_at'];
        }

        if (! empty($data['delivered_at'])) {
            $baris->diterima_at ??= $data['delivered_at'];
        }

        if (in_array($status, [RiwayatPengirimanWa::STATUS_GAGAL, RiwayatPengirimanWa::STATUS_KEDALUWARSA, RiwayatPengirimanWa::STATUS_TIDAK_PASTI], true)) {
            $baris->alasan_gagal = $data['error_message'] ?? $baris->alasan_gagal;
        }
    }

    protected function kunci(string $mentah): string
    {
        $kunci = preg_replace('/[^A-Za-z0-9:._-]/', '-', $mentah);

        return strlen($kunci) <= 191 ? $kunci : 'h:'.hash('sha256', $mentah);
    }

    protected function teks(string $subjek, string $pesan): string
    {
        return Str::limit("*{$subjek}*\n\n{$pesan}", 4000, '');
    }
}
