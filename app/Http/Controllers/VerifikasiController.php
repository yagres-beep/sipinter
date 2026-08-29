<?php

namespace App\Http\Controllers;

use App\Models\Capaian;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class VerifikasiController extends Controller
{
    /**
     * Terbuka untuk isian berstatus apa pun (diajukan/diverifikasi/dikembalikan/
     * disetujui) — VerifikasiList kini juga menautkan riwayat ke sini, bukan cuma
     * worklist "diajukan". Livewire\VerifikasiCapaian sendiri yang membedakan mode
     * bisa-diubah vs cuma-lihat lewat bisaDiverifikasi() (dicek ulang di tiap method
     * pengubah juga, bukan cuma disembunyikan di tampilan).
     *
     * KECUALI "draft" — isian itu milik Ketua Tim yang belum pernah diajukan sama
     * sekali (lihat Capaian::STATUS_DRAFT), jadi ditolak di sini juga (bukan cuma
     * disembunyikan dari tabel dasbor di App\Livewire\DasborUtama::daftarCapaian(),
     * defense in depth — pola yang sama dipakai di seluruh App\Livewire\
     * VerifikasiCapaian untuk gerbang lain).
     */
    public function show(Capaian $capaian): View|RedirectResponse
    {
        if ($capaian->status === Capaian::STATUS_DRAFT) {
            return redirect()->route('verifikasi.index')
                ->with('status', 'Isian ini masih berupa draft milik Ketua Tim dan belum diajukan — belum bisa dibuka.');
        }

        return view('verifikasi.show', [
            'capaian' => $capaian,
        ]);
    }
}
