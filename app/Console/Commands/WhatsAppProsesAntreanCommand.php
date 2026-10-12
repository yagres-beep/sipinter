<?php

namespace App\Console\Commands;

use App\Services\WhatsAppPengingatService;
use Illuminate\Console\Command;

/**
 * Menyerahkan pengingat WhatsApp berstatus 'antre' ke gateway dan
 * menyinkronkan status pesan yang sudah diserahkan. Tidak memanggil gateway
 * sama sekali bila tidak ada pekerjaan, supaya gateway di Render Free tidak
 * dibangunkan tanpa perlu (jam instance gratis dibagi semua layanan).
 */
class WhatsAppProsesAntreanCommand extends Command
{
    protected $signature = 'whatsapp:proses-antrean {--batas=20 : Jumlah maksimum pesan yang diserahkan per putaran}';

    protected $description = 'Serahkan antrean pengingat WhatsApp ke gateway dan sinkronkan statusnya';

    public function handle(WhatsAppPengingatService $layanan): int
    {
        $hasil = $layanan->prosesAntrean(max(1, (int) $this->option('batas')));

        $this->line(sprintf(
            'diserahkan=%d gagal=%d kedaluwarsa=%d disinkronkan=%d%s',
            $hasil['diserahkan'],
            $hasil['gagal'],
            $hasil['kedaluwarsa'],
            $hasil['disinkronkan'],
            $hasil['gateway_tidak_siap'] ? ' (gateway belum siap, dicoba lagi nanti)' : ''
        ));

        return self::SUCCESS;
    }
}
