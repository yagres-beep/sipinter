<?php

namespace App\Jobs;

use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class KirimPengingatWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        protected ?string $nomorTelepon,
        protected string $pesan,
    ) {}

    public function handle(WhatsAppService $whatsapp): void
    {
        $whatsapp->kirim($this->nomorTelepon, $this->pesan);
    }
}
