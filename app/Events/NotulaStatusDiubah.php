<?php

namespace App\Events;

use App\Models\Notula;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dipicu dari Notula::kirimKePersetujuan()/kembalikan() SETELAH status baru
 * tersimpan — listener (App\Listeners\KirimPengingatStatusNotula) cukup baca
 * $notula->status untuk tahu status barunya, tidak perlu parameter terpisah.
 */
class NotulaStatusDiubah
{
    use Dispatchable;

    public function __construct(
        public Notula $notula,
    ) {}
}
