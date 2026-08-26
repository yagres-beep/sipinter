<?php

namespace App\Listeners;

use App\Events\CapaianStatusDiubah;
use App\Jobs\KirimPengingatEmailJob;
use App\Models\Capaian;
use App\Models\PengaturanPenerimaPengingat;
use App\Models\PengaturanTemplatePengingat;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Pengingat email real-time untuk 2 kejadian alur verifikasi IKU:
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
        $pesan = PengaturanTemplatePengingat::render('iku_diajukan', [
            'indikator' => $capaian->masterIku->indikator,
            'periode' => $this->labelPeriode($capaian),
        ]);

        $subjek = 'SIPINTER — '.PengaturanTemplatePengingat::JENIS['iku_diajukan']['label'];

        foreach (PengaturanPenerimaPengingat::resolveUsers('iku_diajukan') as $user) {
            KirimPengingatEmailJob::dispatch($user->email, $subjek, $pesan);
        }
    }

    protected function kirimKePenanggungJawab(Capaian $capaian, ?string $catatan): void
    {
        $pesan = PengaturanTemplatePengingat::render('iku_dikembalikan', [
            'indikator' => $capaian->masterIku->indikator,
            'periode' => $this->labelPeriode($capaian),
        ]).($catatan ? "\nCatatan: {$catatan}" : '');

        $subjek = 'SIPINTER — '.PengaturanTemplatePengingat::JENIS['iku_dikembalikan']['label'];

        $penerima = PengaturanPenerimaPengingat::resolveUsers('iku_dikembalikan', $capaian->masterIku->semuaPenanggungJawab());

        foreach ($penerima as $user) {
            KirimPengingatEmailJob::dispatch($user->email, $subjek, $pesan);
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
