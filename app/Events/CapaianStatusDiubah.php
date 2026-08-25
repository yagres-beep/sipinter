<?php

namespace App\Events;

use App\Models\RiwayatStatusCapaian;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dipicu sekali per aksi dari Capaian::catatStatus() (satu-satunya jalur
 * perubahan status Capaian) — dipakai App\Listeners\KirimPengingatStatusCapaian
 * untuk mengirim pengingat WA real-time tanpa mengotori model Capaian dengan
 * urusan pengiriman notifikasi.
 */
class CapaianStatusDiubah
{
    use Dispatchable;

    public function __construct(
        public RiwayatStatusCapaian $riwayat,
    ) {}
}
