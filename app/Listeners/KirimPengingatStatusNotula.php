<?php

namespace App\Listeners;

use App\Events\NotulaStatusDiubah;
use App\Jobs\KirimPengingatWhatsAppJob;
use App\Models\Notula;
use App\Models\PengaturanPenerimaPengingat;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Pengingat WA real-time untuk 2 kejadian alur persetujuan Notula:
 * - menunggu_persetujuan -> Kepala perlu menandatangani/menyetujui.
 * - dikembalikan         -> Tim SAKIP perlu memperbaiki.
 */
class KirimPengingatStatusNotula implements ShouldQueue
{
    public function handle(NotulaStatusDiubah $event): void
    {
        $notula = $event->notula->loadMissing('periode');

        match ($notula->status) {
            Notula::STATUS_MENUNGGU_PERSETUJUAN => $this->kirimKeKepala($notula),
            Notula::STATUS_DIKEMBALIKAN => $this->kirimKeTimSakip($notula),
            default => null,
        };
    }

    protected function kirimKeKepala(Notula $notula): void
    {
        $pesan = sprintf(
            "Pengingat SIPINTER:\nNotula %s sudah digabungkan Tim SAKIP dan siap ditandatangani/disetujui.",
            $this->labelTriwulan($notula)
        );

        foreach (PengaturanPenerimaPengingat::resolveUsers('notula_menunggu_persetujuan') as $user) {
            KirimPengingatWhatsAppJob::dispatch($user->nomor_telepon, $pesan);
        }
    }

    protected function kirimKeTimSakip(Notula $notula): void
    {
        $pesan = sprintf(
            "Pengingat SIPINTER:\nNotula %s dikembalikan Kepala, perlu diperbaiki.%s",
            $this->labelTriwulan($notula),
            $notula->catatan_pengembalian ? "\nCatatan: {$notula->catatan_pengembalian}" : ''
        );

        foreach (PengaturanPenerimaPengingat::resolveUsers('notula_dikembalikan') as $user) {
            KirimPengingatWhatsAppJob::dispatch($user->nomor_telepon, $pesan);
        }
    }

    protected function labelTriwulan(Notula $notula): string
    {
        if (! $notula->periode) {
            return '-';
        }

        return "Triwulan {$notula->periode->triwulan} Tahun {$notula->periode->tahun}";
    }
}
