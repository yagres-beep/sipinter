<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected string $token)
    {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage)
            ->subject('Permintaan Reset Kata Sandi — SIPINTER BPS Kabupaten Buton Utara')
            ->greeting('Yth. Bapak/Ibu '.$notifiable->nama.',')
            ->line('Kami menerima permintaan reset kata sandi untuk akun SIPINTER (Sistem Informasi Pelaporan Kinerja Terpadu) Bapak/Ibu pada BPS Kabupaten Buton Utara.')
            ->line('Untuk melanjutkan, silakan klik tombol di bawah ini untuk membuat kata sandi baru.')
            ->action('Reset Kata Sandi', $url)
            ->line('Tautan ini berlaku selama 60 menit sejak email ini dikirim dan hanya dapat digunakan satu kali.')
            ->line('Apabila Bapak/Ibu tidak pernah mengajukan permintaan ini, mohon abaikan email ini. Kata sandi akun Bapak/Ibu tidak akan berubah tanpa mengakses tautan di atas.')
            ->salutation("Hormat kami,\nTim SAKIP BPS Kabupaten Buton Utara");
    }
}
