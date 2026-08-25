<?php

namespace App\Console\Commands;

use App\Jobs\KirimPengingatWhatsAppJob;
use App\Models\Notula;
use App\Models\Periode;
use App\Models\User;
use App\Services\NotulaService;
use Illuminate\Console\Command;

/**
 * Cek harian: begitu SELURUH kegiatan triwulan berjalan sudah terverifikasi
 * (App\Services\NotulaService::semuaBuktiTerverifikasi()) tapi Notula-nya
 * belum mulai disusun, ingatkan Tim SAKIP.
 */
class PengingatIkuLengkapCommand extends Command
{
    protected $signature = 'pengingat:iku-lengkap';

    protected $description = 'Kirim pengingat WA ke Tim SAKIP saat seluruh IKU triwulan berjalan sudah lengkap dan siap disusun jadi Notula';

    public function handle(NotulaService $notulaService): int
    {
        $periode = Periode::where('tahun', now()->year)->where('bulan', now()->month)->first();

        if (! $periode || ! $notulaService->semuaBuktiTerverifikasi($periode)) {
            return self::SUCCESS;
        }

        $notula = Notula::whereHas(
            'periode',
            fn ($q) => $q->where('tahun', $periode->tahun)->where('triwulan', $periode->triwulan)
        )->first();

        if ($notula && $notula->status !== Notula::STATUS_DRAFT) {
            return self::SUCCESS;
        }

        $pesan = sprintf(
            "Pengingat SIPINTER:\nSeluruh IKU Triwulan %d Tahun %d sudah lengkap (terverifikasi), silakan susun Notula.",
            $periode->triwulan,
            $periode->tahun
        );

        foreach (User::olehRole('Tim SAKIP') as $user) {
            KirimPengingatWhatsAppJob::dispatch($user->nomor_telepon, $pesan);
        }

        return self::SUCCESS;
    }
}
