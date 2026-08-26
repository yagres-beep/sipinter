<?php

namespace App\Console\Commands;

use App\Jobs\KirimPengingatWhatsAppJob;
use App\Models\Capaian;
use App\Models\PengaturanPengingat;
use App\Models\Periode;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Cek harian: IKU yang tenggat pengajuannya (akhir bulan periode berjalan)
 * sudah dekat (H- sesuai PengaturanPengingat::deadline_h_minus, bisa diubah Tim
 * SAKIP lewat halaman Pengingat WA) s.d. hari-H, atau sudah lewat tapi belum
 * diajukan.
 *
 * Catatan lingkup: hanya melihat baris Capaian yang SUDAH ADA untuk periode
 * berjalan (dibuat otomatis saat Ketua Tim mulai mengisi, lihat
 * PengisianKegiatan::simpanKegiatan()) — IKU yang belum pernah disentuh sama
 * sekali bulan ini (belum ada baris Capaian) tidak tercakup di versi awal ini.
 */
class PengingatDeadlineIkuCommand extends Command
{
    protected $signature = 'pengingat:deadline-iku';

    protected $description = 'Kirim pengingat WA untuk IKU yang mendekati/melewati tenggat pengajuan bulan berjalan';

    public function handle(): int
    {
        $periode = Periode::where('tahun', now()->year)->where('bulan', now()->month)->first();

        if (! $periode) {
            return self::SUCCESS;
        }

        $hariIni = now()->startOfDay();
        $akhirBulan = Carbon::create($periode->tahun, $periode->bulan, 1)->endOfMonth()->startOfDay()->locale('id');
        $lewatTenggat = $hariIni->gt($akhirBulan);
        $sisaHari = $hariIni->diffInDays($akhirBulan);
        $hMinus = PengaturanPengingat::ambil()->deadline_h_minus;

        if (! $lewatTenggat && $sisaHari > $hMinus) {
            return self::SUCCESS;
        }

        $capaianBelumDiajukan = Capaian::with('masterIku')
            ->where('periode_id', $periode->id)
            ->whereIn('status', [Capaian::STATUS_DRAFT, Capaian::STATUS_SEDANG_DITANGANI])
            ->get();

        foreach ($capaianBelumDiajukan as $capaian) {
            if (! $capaian->masterIku) {
                continue;
            }

            $pesan = $lewatTenggat
                ? sprintf(
                    "Pengingat SIPINTER:\nIKU \"%s\" belum diisi padahal tenggat pengajuan (akhir %s) sudah lewat.",
                    $capaian->masterIku->indikator,
                    $akhirBulan->translatedFormat('F Y')
                )
                : sprintf(
                    "Pengingat SIPINTER:\nTenggat pengajuan IKU \"%s\" tanggal %s, segera ajukan.",
                    $capaian->masterIku->indikator,
                    $akhirBulan->translatedFormat('d F Y')
                );

            foreach ($capaian->masterIku->semuaPenanggungJawab() as $user) {
                KirimPengingatWhatsAppJob::dispatch($user->nomor_telepon, $pesan);
            }
        }

        return self::SUCCESS;
    }
}
