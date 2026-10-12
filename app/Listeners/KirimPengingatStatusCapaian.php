<?php

namespace App\Listeners;

use App\Events\CapaianStatusDiubah;
use App\Jobs\KirimPengingatEmailJob;
use App\Models\Capaian;
use App\Models\PengaturanPenerimaPengingat;
use App\Models\PengaturanTemplatePengingat;
use App\Services\WhatsAppPengingatService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Pengingat email real-time untuk 2 kejadian alur verifikasi IKU:
 * - diajukan     -> Tim SAKIP perlu memeriksa.
 * - dikembalikan -> Ketua Tim penanggung jawab IKU tsb perlu memperbaiki.
 */
class KirimPengingatStatusCapaian implements ShouldQueue
{
    public function __construct(protected WhatsAppPengingatService $whatsApp) {}

    public function handle(CapaianStatusDiubah $event): void
    {
        $riwayat = $event->riwayat;
        $capaian = $riwayat->capaian()->with(['masterIku', 'periode'])->first();

        if (! $capaian || ! $capaian->masterIku) {
            return;
        }

        // Satu baris riwayat status = satu kejadian; dipakai sebagai kunci anti pesan ganda WA.
        $kunciKejadian = "riwayat{$riwayat->id}";

        match ($riwayat->status) {
            Capaian::STATUS_DIAJUKAN => $this->kirimKeTimSakip($capaian, $kunciKejadian),
            Capaian::STATUS_DIKEMBALIKAN => $this->kirimKePenanggungJawab($capaian, $riwayat->catatan, $kunciKejadian),
            default => null,
        };
    }

    protected function kirimKeTimSakip(Capaian $capaian, string $kunciKejadian): void
    {
        $pesan = PengaturanTemplatePengingat::render('iku_diajukan', [
            'indikator' => $capaian->masterIku->indikator,
            'periode' => $this->labelPeriode($capaian),
        ]);

        $subjek = 'SIPINTER — '.PengaturanTemplatePengingat::JENIS['iku_diajukan']['label'];

        foreach (PengaturanPenerimaPengingat::resolveUsers('iku_diajukan') as $user) {
            KirimPengingatEmailJob::dispatch($user->email, $subjek, $pesan);
            $this->whatsApp->antrekan($user, 'iku_diajukan', $subjek, $pesan, $kunciKejadian);
        }
    }

    protected function kirimKePenanggungJawab(Capaian $capaian, ?string $catatan, string $kunciKejadian): void
    {
        $pesan = PengaturanTemplatePengingat::render('iku_dikembalikan', [
            'indikator' => $capaian->masterIku->indikator,
            'periode' => $this->labelPeriode($capaian),
        ]).($catatan ? "\nCatatan: {$catatan}" : '');

        $subjek = 'SIPINTER — '.PengaturanTemplatePengingat::JENIS['iku_dikembalikan']['label'];

        $penerima = PengaturanPenerimaPengingat::resolveUsers('iku_dikembalikan', $capaian->masterIku->semuaPenanggungJawab());

        foreach ($penerima as $user) {
            KirimPengingatEmailJob::dispatch($user->email, $subjek, $pesan);
            $this->whatsApp->antrekan($user, 'iku_dikembalikan', $subjek, $pesan, $kunciKejadian);
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
