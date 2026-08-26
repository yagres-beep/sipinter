<?php

namespace App\Services;

use App\Mail\PengingatMail;
use App\Models\RiwayatPengirimanEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Pengirim pesan pengingat lewat email — pengganti WhatsAppService untuk
 * pengingat otomatis (tenggat IKU, verifikasi, notula, dsb), karena gateway WA
 * (Baileys, tidak resmi) di hosting gratis sering tidur/putus sendiri.
 * Mengikuti pola WhatsAppService: kegagalan layanan email TIDAK BOLEH
 * menghentikan alur utama aplikasi — semua kegagalan ditelan jadi false +
 * Log::warning.
 */
class EmailPengingatService
{
    /**
     * Kirim satu email. Mengembalikan false (tanpa exception) bila alamat
     * kosong/tidak valid atau pengiriman gagal — dipanggil dari job antrian
     * (App\Jobs\KirimPengingatEmailJob), bukan langsung dari request
     * pengguna, jadi aman untuk retry di sana bila perlu.
     */
    public function kirim(?string $email, string $subjek, string $pesan): bool
    {
        return $this->kirimDenganAlasan($email, $subjek, $pesan)['berhasil'];
    }

    /**
     * Sama seperti kirim(), tapi juga mengembalikan alasan gagal & mencatat
     * setiap percobaan (berhasil maupun gagal) ke tabel
     * riwayat_pengiriman_email — dipakai fitur "Kirim Email Tes" & kartu
     * "Riwayat Pengiriman Email" supaya Tim SAKIP tahu persis apa yang
     * terjadi, bukan cuma "gagal" tanpa detail.
     *
     * @return array{berhasil: bool, alasan: ?string}
     */
    public function kirimDenganAlasan(?string $email, string $subjek, string $pesan): array
    {
        if (blank($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->catat($email ?? '', $subjek, $pesan, false, 'Alamat email kosong/tidak valid.');
        }

        try {
            Mail::to($email)->send(new PengingatMail($subjek, $pesan));

            return $this->catat($email, $subjek, $pesan, true, null);
        } catch (\Throwable $e) {
            Log::warning('EmailPengingatService: gagal mengirim email pengingat.', [
                'email' => $email,
                'pesan_error' => $e->getMessage(),
            ]);

            return $this->catat($email, $subjek, $pesan, false, 'Gagal mengirim email: '.$e->getMessage());
        }
    }

    /**
     * @return array{berhasil: bool, alasan: ?string}
     */
    protected function catat(string $email, string $subjek, string $pesan, bool $berhasil, ?string $alasan): array
    {
        try {
            RiwayatPengirimanEmail::create([
                'email' => $email,
                'subjek' => $subjek,
                'pesan' => $pesan,
                'berhasil' => $berhasil,
                'alasan_gagal' => $alasan,
            ]);
        } catch (\Throwable $e) {
            // Riwayat cuma catatan tambahan — gagal mencatat tidak boleh mengubah
            // hasil pengiriman yang sesungguhnya.
            Log::warning('EmailPengingatService: gagal mencatat riwayat pengiriman.', ['pesan_error' => $e->getMessage()]);
        }

        return ['berhasil' => $berhasil, 'alasan' => $alasan];
    }
}
