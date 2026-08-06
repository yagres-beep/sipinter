<?php

namespace App\Http\Controllers;

use App\Models\Capaian;
use App\Models\Kegiatan;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class VerifikasiController extends Controller
{
    public function show(Capaian $capaian): View|RedirectResponse
    {
        $adaYangDiajukan = Kegiatan::where('iku_id', $capaian->iku_id)
            ->where('periode_id', $capaian->periode_id)
            ->where('status_dokumen', Kegiatan::STATUS_DIAJUKAN)
            ->exists();

        if (! $adaYangDiajukan) {
            return redirect()->route('verifikasi.index')
                ->with('status', 'Isian ini sudah tidak berstatus "diajukan" dan tidak lagi dapat diverifikasi dari sini.');
        }

        return view('verifikasi.show', [
            'capaian' => $capaian,
        ]);
    }
}
