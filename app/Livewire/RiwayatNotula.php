<?php

namespace App\Livewire;

use App\Models\Notula;
use Livewire\Component;

/**
 * Riwayat Notula (baca saja) untuk Ketua Tim &amp; Kepala (RF-44a) — memantau status
 * notula tiap triwulan dan membuka versi final ber-TTD setelah disetujui. Tim SAKIP
 * memakai halaman Kompilasi Notula terpisah yang punya kendali penuh (unggah,
 * gabung, kirim), jadi komponen ini sengaja tidak punya aksi apa pun selain "Buka".
 */
class RiwayatNotula extends Component
{
    public function render()
    {
        $daftarNotula = Notula::with('periode')
            ->get()
            ->sortByDesc(fn ($n) => $n->periode->tahun * 10 + $n->periode->triwulan)
            ->values();

        return view('livewire.riwayat-notula', [
            'daftarNotula' => $daftarNotula,
        ]);
    }
}
