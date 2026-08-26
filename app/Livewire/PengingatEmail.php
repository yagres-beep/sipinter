<?php

namespace App\Livewire;

use App\Models\RiwayatPengirimanEmail;
use App\Services\EmailPengingatService;
use Livewire\Component;

/**
 * Kartu "Kirim Email Tes" & "Riwayat Pengiriman Email" di halaman Pengingat —
 * pengingat otomatis (tenggat IKU, verifikasi, notula, dsb) sekarang dikirim
 * lewat email (lihat App\Services\EmailPengingatService), menggantikan
 * gateway WA (App\Livewire\WhatsAppGateway) yang sering tidur/putus di
 * hosting gratis.
 */
class PengingatEmail extends Component
{
    public string $emailTes = '';

    public string $subjekTes = 'Tes Pengingat SIPINTER';

    public string $pesanTes = 'Tes pengingat email SIPINTER.';

    public function mount(): void
    {
        $this->emailTes = (string) (auth()->user()->email ?? '');
    }

    /**
     * Kirim satu email langsung (tidak lewat antrean KirimPengingatEmailJob)
     * supaya hasilnya langsung diketahui saat itu juga — dipakai Tim SAKIP
     * untuk memastikan email pengingat benar-benar sampai, tanpa perlu
     * menunggu kejadian pengingat sungguhan.
     */
    public function kirimTes(): void
    {
        $this->validate([
            'emailTes' => ['required', 'email'],
            'subjekTes' => ['required', 'string', 'max:255'],
            'pesanTes' => ['required', 'string', 'max:2000'],
        ], [], ['emailTes' => 'alamat email', 'subjekTes' => 'subjek', 'pesanTes' => 'isi pesan']);

        $hasil = app(EmailPengingatService::class)->kirimDenganAlasan($this->emailTes, $this->subjekTes, $this->pesanTes);

        session()->flash(
            $hasil['berhasil'] ? 'status' : 'error',
            $hasil['berhasil']
                ? "Email tes berhasil dikirim ke {$this->emailTes}."
                : "Gagal mengirim email tes: {$hasil['alasan']}"
        );
    }

    public function render()
    {
        return view('livewire.pengingat-email', [
            'riwayat' => RiwayatPengirimanEmail::latest()->limit(20)->get(),
        ]);
    }
}
