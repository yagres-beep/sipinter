<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Email pengingat SIPINTER (tenggat IKU, verifikasi, notula, dsb) — pengganti
 * KirimPengingatWhatsAppJob sejak gateway WA (Baileys, tidak resmi & sering
 * putus di hosting gratis) dialihkan sepenuhnya ke email lewat
 * App\Services\EmailPengingatService.
 */
class PengingatMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjek,
        public string $pesan,
    ) {}

    public function build(): self
    {
        return $this->subject($this->subjek)->view('emails.pengingat');
    }
}
