<?php

namespace App\Http\Controllers;

use App\Models\Capaian;
use Illuminate\View\View;

class VerifikasiController extends Controller
{
    /**
     * Terbuka untuk isian berstatus apa pun (diajukan/diverifikasi/dikembalikan/
     * disetujui) — VerifikasiList kini juga menautkan riwayat ke sini, bukan cuma
     * worklist "diajukan". Livewire\VerifikasiCapaian sendiri yang membedakan mode
     * bisa-diubah vs cuma-lihat lewat bisaDiverifikasi() (dicek ulang di tiap method
     * pengubah juga, bukan cuma disembunyikan di tampilan).
     */
    public function show(Capaian $capaian): View
    {
        return view('verifikasi.show', [
            'capaian' => $capaian,
        ]);
    }
}
