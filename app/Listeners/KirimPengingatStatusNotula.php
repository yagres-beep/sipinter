<?php

namespace App\Listeners;

use App\Events\NotulaStatusDiubah;
use App\Jobs\KirimPengingatEmailJob;
use App\Models\Notula;
use App\Models\PengaturanPenerimaPengingat;
use App\Models\PengaturanTemplatePengingat;
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
        $pesan = PengaturanTemplatePengingat::render('notula_menunggu_persetujuan', [
            'triwulan_label' => $this->labelTriwulan($notula),
        ]);

        $subjek = 'SIPINTER — '.PengaturanTemplatePengingat::JENIS['notula_menunggu_persetujuan']['label'];

        foreach (PengaturanPenerimaPengingat::resolveUsers('notula_menunggu_persetujuan') as $user) {
            KirimPengingatEmailJob::dispatch($user->email, $subjek, $pesan);
        }
    }

    protected function kirimKeTimSakip(Notula $notula): void
    {
        $pesan = PengaturanTemplatePengingat::render('notula_dikembalikan', [
            'triwulan_label' => $this->labelTriwulan($notula),
        ]).($notula->catatan_pengembalian ? "\nCatatan: {$notula->catatan_pengembalian}" : '');

        $subjek = 'SIPINTER — '.PengaturanTemplatePengingat::JENIS['notula_dikembalikan']['label'];

        foreach (PengaturanPenerimaPengingat::resolveUsers('notula_dikembalikan') as $user) {
            KirimPengingatEmailJob::dispatch($user->email, $subjek, $pesan);
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
