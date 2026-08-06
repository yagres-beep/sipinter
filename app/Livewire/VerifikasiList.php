<?php

namespace App\Livewire;

use App\Models\Capaian;
use App\Models\Kegiatan;
use Livewire\Component;

/**
 * Daftar isian berstatus "diajukan" menunggu verifikasi Tim SAKIP — dengan filter
 * periode bulanan & triwulanan (RF-48) dan penanda periode aktif yang jelas.
 *
 * Satu baris mewakili satu IKU pada satu periode (RF-36), bukan satu kegiatan —
 * karena satu IKU boleh punya banyak kegiatan (RF-19) yang diverifikasi bersama
 * lewat satu Capaian (lihat VerifikasiCapaian).
 *
 * Memilih bulan tertentu = filter BULANAN; membiarkan bulan kosong ("semua bulan
 * triwulan ini") = filter TRIWULANAN yang merekap ketiga bulan sekaligus (RF-48).
 */
class VerifikasiList extends Component
{
    public int $tahun;

    public int $triwulan;

    public $bulan = '';

    public string $cari = '';

    public string $urutanKolom = 'periode';

    public string $urutanArah = 'asc';

    public function mount(): void
    {
        $this->tahun = (int) now()->year;
        $this->triwulan = (int) ceil(((int) now()->month) / 3);
    }

    /**
     * Bulan hanya valid untuk triwulan yang sedang dipilih — bila Tim SAKIP
     * mengganti triwulan, filter bulan lama (mis. Agustus) tidak lagi relevan
     * untuk triwulan baru (mis. Triwulan I), jadi dikembalikan ke "semua bulan".
     */
    public function updatedTriwulan(): void
    {
        $this->bulan = '';
    }

    /**
     * Sinkron dua arah: begitu bulan dipilih langsung, triwulan ikut menyesuaikan
     * supaya daftar bulan yang tampil di <x-filter-periode> tetap konsisten dengan
     * triwulan yang sedang aktif (sama seperti DasborUtama).
     */
    public function updatedBulan(): void
    {
        if (filled($this->bulan)) {
            $this->triwulan = (int) ceil(((int) $this->bulan) / 3);
        }
    }

    public function urutkan(string $kolom): void
    {
        if ($this->urutanKolom === $kolom) {
            $this->urutanArah = $this->urutanArah === 'asc' ? 'desc' : 'asc';
        } else {
            $this->urutanKolom = $kolom;
            $this->urutanArah = 'asc';
        }
    }

    public function resetFilter(): void
    {
        $this->cari = '';
        $this->bulan = '';
        $this->tahun = (int) now()->year;
        $this->triwulan = (int) ceil(((int) now()->month) / 3);
        $this->urutanKolom = 'periode';
        $this->urutanArah = 'asc';
    }

    /**
     * @return \Illuminate\Support\Collection<int, Capaian>
     */
    protected function daftarMenunggu()
    {
        $pasanganMenunggu = Kegiatan::where('status_dokumen', Kegiatan::STATUS_DIAJUKAN)
            ->whereHas('periode', function ($q) {
                $q->where('tahun', $this->tahun)->where('triwulan', $this->triwulan);

                if (filled($this->bulan)) {
                    $q->where('bulan', $this->bulan);
                }
            })
            ->get(['iku_id', 'periode_id'])
            ->unique(fn ($k) => $k->iku_id.'-'.$k->periode_id);

        if ($pasanganMenunggu->isEmpty()) {
            return collect();
        }

        $daftar = Capaian::with(['masterIku', 'periode'])
            ->where(function ($q) use ($pasanganMenunggu) {
                foreach ($pasanganMenunggu as $pasangan) {
                    $q->orWhere(function ($sub) use ($pasangan) {
                        $sub->where('iku_id', $pasangan->iku_id)->where('periode_id', $pasangan->periode_id);
                    });
                }
            })
            ->get();

        if (filled($this->cari)) {
            $kataKunci = mb_strtolower($this->cari);

            $daftar = $daftar->filter(function ($capaian) use ($kataKunci) {
                return str_contains(mb_strtolower($capaian->masterIku->kode), $kataKunci)
                    || str_contains(mb_strtolower($capaian->masterIku->indikator), $kataKunci)
                    || str_contains(mb_strtolower($capaian->masterIku->tim), $kataKunci);
            });
        }

        $pengurut = match ($this->urutanKolom) {
            'kode' => fn ($c) => $c->masterIku->kode,
            'indikator' => fn ($c) => $c->masterIku->indikator,
            'tim' => fn ($c) => $c->masterIku->tim,
            default => fn ($c) => $c->periode_id,
        };

        $daftar = $this->urutanArah === 'asc' ? $daftar->sortBy($pengurut) : $daftar->sortByDesc($pengurut);

        return $daftar->values();
    }

    /**
     * Jumlah kegiatan pendukung per Capaian, dihitung SEKALI untuk seluruh daftar
     * (bukan satu query per baris lewat $capaian->kegiatanList()->count() — N+1).
     *
     * @param  \Illuminate\Support\Collection<int, Capaian>  $daftarCapaian
     * @return \Illuminate\Support\Collection<int, int>
     */
    protected function jumlahKegiatanPerCapaian($daftarCapaian)
    {
        if ($daftarCapaian->isEmpty()) {
            return collect();
        }

        $perPasangan = Kegiatan::whereIn('iku_id', $daftarCapaian->pluck('iku_id'))
            ->whereIn('periode_id', $daftarCapaian->pluck('periode_id'))
            ->get(['iku_id', 'periode_id'])
            ->groupBy(fn ($k) => $k->iku_id.'-'.$k->periode_id);

        return $daftarCapaian->mapWithKeys(
            fn ($c) => [$c->id => $perPasangan->get($c->iku_id.'-'.$c->periode_id, collect())->count()]
        );
    }

    public function render()
    {
        $daftarCapaian = $this->daftarMenunggu();

        return view('livewire.verifikasi-list', [
            'daftarCapaian' => $daftarCapaian,
            'jumlahKegiatan' => $this->jumlahKegiatanPerCapaian($daftarCapaian),
        ]);
    }
}
