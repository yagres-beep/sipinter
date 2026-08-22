<?php

namespace App\Livewire;

use App\Models\Capaian;
use App\Models\Kegiatan;
use Livewire\Component;

/**
 * Daftar isian per status alur verifikasi Tim SAKIP — dengan filter periode
 * bulanan & triwulanan (RF-48) dan penanda periode aktif yang jelas.
 *
 * Satu baris mewakili satu IKU pada satu periode (RF-36), bukan satu kegiatan —
 * karena satu IKU boleh punya banyak kegiatan (RF-19) yang diverifikasi bersama
 * lewat satu Capaian (lihat VerifikasiCapaian).
 *
 * Memilih bulan tertentu = filter BULANAN; membiarkan bulan kosong ("semua bulan
 * triwulan ini") = filter TRIWULANAN yang merekap ketiga bulan sekaligus (RF-48).
 *
 * Default status TETAP "diajukan" saat halaman dibuka (worklist verifikasi
 * sehari-hari) — status lain (diverifikasi/dikembalikan/disetujui) cuma untuk
 * menelusuri riwayat, dipilih manual lewat filter, tidak pernah jadi default.
 */
class VerifikasiList extends Component
{
    public int $tahun;

    public int $triwulan;

    public $bulan = '';

    /**
     * String kosong = "Semua Status" (gabungan 4 status di statusTersedia(), TIDAK
     * termasuk "draft" karena itu belum diajukan Ketua Tim sama sekali — di luar
     * lingkup pemantauan Tim SAKIP).
     */
    public string $status = Kegiatan::STATUS_DIAJUKAN;

    public string $cari = '';

    public string $urutanKolom = 'periode';

    public string $urutanArah = 'asc';

    /**
     * @return array<int, string>
     */
    public static function statusTersedia(): array
    {
        return [
            Kegiatan::STATUS_DIAJUKAN,
            Kegiatan::STATUS_DIVERIFIKASI,
            Kegiatan::STATUS_DIKEMBALIKAN,
            Kegiatan::STATUS_DISETUJUI,
        ];
    }

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
        $this->status = Kegiatan::STATUS_DIAJUKAN;
        $this->urutanKolom = 'periode';
        $this->urutanArah = 'asc';
    }

    /**
     * @return \Illuminate\Support\Collection<int, Capaian>
     */
    protected function daftarCapaian()
    {
        // Status kosong ("Semua Status") mencakup keempat status alur verifikasi
        // sekaligus (bukan tanpa syarat sama sekali) — draft sengaja dikecualikan
        // karena belum diajukan Ketua Tim, di luar lingkup pemantauan Tim SAKIP.
        $statusDicari = filled($this->status) ? [$this->status] : self::statusTersedia();

        $pasanganTersaring = Kegiatan::whereIn('status_dokumen', $statusDicari)
            ->whereHas('periode', function ($q) {
                $q->where('tahun', $this->tahun)->where('triwulan', $this->triwulan);

                if (filled($this->bulan)) {
                    $q->where('bulan', $this->bulan);
                }
            })
            ->get(['iku_id', 'periode_id'])
            ->unique(fn ($k) => $k->iku_id.'-'.$k->periode_id);

        if ($pasanganTersaring->isEmpty()) {
            return collect();
        }

        $daftar = Capaian::with(['masterIku', 'periode'])
            ->where(function ($q) use ($pasanganTersaring) {
                foreach ($pasanganTersaring as $pasangan) {
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
     * Kegiatan pendukung per Capaian (RF-37), dihitung SEKALI untuk seluruh daftar
     * dalam SATU query (bukan satu query per baris lewat $capaian->kegiatanList() —
     * N+1) — dipakai untuk turunkan jumlah kegiatan MAUPUN status asli tiap kegiatan
     * (lihat render(): satu Capaian bisa punya kegiatan berstatus campuran, mis.
     * sebagian "dikembalikan" sebagian "diverifikasi", jadi kolom Status & tombol
     * Tindakan di tabel tidak bisa asal mengasumsikan status filter yang sedang
     * dipilih — terutama saat filter "Semua Status").
     *
     * @param  \Illuminate\Support\Collection<int, Capaian>  $daftarCapaian
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, Kegiatan>>
     */
    protected function kegiatanPerCapaian($daftarCapaian)
    {
        if ($daftarCapaian->isEmpty()) {
            return collect();
        }

        $perPasangan = Kegiatan::whereIn('iku_id', $daftarCapaian->pluck('iku_id'))
            ->whereIn('periode_id', $daftarCapaian->pluck('periode_id'))
            ->get(['iku_id', 'periode_id', 'status_dokumen'])
            ->groupBy(fn ($k) => $k->iku_id.'-'.$k->periode_id);

        return $daftarCapaian->mapWithKeys(
            fn ($c) => [$c->id => $perPasangan->get($c->iku_id.'-'.$c->periode_id, collect())]
        );
    }

    public function render()
    {
        $daftarCapaian = $this->daftarCapaian();
        $kegiatanPerCapaian = $this->kegiatanPerCapaian($daftarCapaian);

        return view('livewire.verifikasi-list', [
            'daftarCapaian' => $daftarCapaian,
            'jumlahKegiatan' => $kegiatanPerCapaian->map->count(),
            // Status DISTINCT yang benar-benar dimiliki kegiatan Capaian ini — dipakai
            // di tabel supaya baris dengan kegiatan berstatus campuran tetap ditampilkan
            // jujur (badge lebih dari satu), bukan diasumsikan sama dengan filter aktif.
            'statusPerCapaian' => $kegiatanPerCapaian->map(fn ($g) => $g->pluck('status_dokumen')->unique()->values()),
            'statusTersedia' => self::statusTersedia(),
        ]);
    }
}
