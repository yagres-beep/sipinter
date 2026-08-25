<?php

namespace App\Listeners;

use App\Events\CapaianStatusDiubah;
use App\Jobs\KirimPengingatWhatsAppJob;
use App\Models\Capaian;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Pengingat WA real-time untuk 2 kejadian alur verifikasi IKU (lihat plan
 * "Pengingat WhatsApp Gratis untuk SIPINTER"):
 * - diajukan     -> Tim SAKIP perlu memeriksa.
 * - dikembalikan -> Ketua Tim penanggung jawab IKU tsb perlu memperbaiki.
 */
class KirimPengingatStatusCapaian implements ShouldQueue
{
    public function handle(CapaianStatusDiubah $event): void
    {
        $riwayat = $event->riwayat;
        $capaian = $riwayat->capaian()->with(['masterIku', 'periode'])->first();

        if (! $capaian || ! $capaian->masterIku) {
            return;
        }

        match ($riwayat->status) {
            Capaian::STATUS_DIAJUKAN => $this->kirimKeTimSakip($capaian),
            Capaian::STATUS_DIKEMBALIKAN => $this->kirimKePenanggungJawab($capaian, $riwayat->catatan),
            default => null,
        };
    }

    protected function kirimKeTimSakip(Capaian $capaian): void
    {
        $pesan = sprintf(
            "Pengingat SIPINTER:\nIKU \"%s\" (%s) sudah diajukan Ketua Tim, perlu diperiksa.",
            $capaian->masterIku->indikator,
            $this->labelPeriode($capaian)
        );

        foreach (User::olehRole('Tim SAKIP') as $user) {
            KirimPengingatWhatsAppJob::dispatch($user->nomor_telepon, $pesan);
        }
    }

    protected function kirimKePenanggungJawab(Capaian $capaian, ?string $catatan): void
    {
        $pesan = sprintf(
            "Pengingat SIPINTER:\nIKU \"%s\" (%s) dikembalikan Tim SAKIP, perlu diperbaiki.%s",
            $capaian->masterIku->indikator,
            $this->labelPeriode($capaian),
            $catatan ? "\nCatatan: {$catatan}" : ''
        );

        foreach ($capaian->masterIku->semuaPenanggungJawab() as $user) {
            KirimPengingatWhatsAppJob::dispatch($user->nomor_telepon, $pesan);
        }
    }

    protected function labelPeriode(Capaian $capaian): string
    {
        if (! $capaian->periode) {
            return '-';
        }

        return Carbon::create($capaian->periode->tahun, $capaian->periode->bulan, 1)
            ->locale('id')
            ->translatedFormat('F Y');
    }
}
