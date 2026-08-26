<?php

namespace App\Jobs;

use App\Services\EmailPengingatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class KirimPengingatEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        protected ?string $email,
        protected string $subjek,
        protected string $pesan,
    ) {}

    public function handle(EmailPengingatService $emailPengingat): void
    {
        $emailPengingat->kirim($this->email, $this->subjek, $this->pesan);
    }
}
