<?php

namespace App\Livewire;

use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\RtlEvaluasi;
use Illuminate\Support\Collection;
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
 * Default status TETAP "diajukan" + "sedang ditangani" saat halaman dibuka (worklist
 * verifikasi sehari-hari — kedua status ini sama-sama masih butuh perhatian Tim
 * SAKIP) — status lain (diverifikasi/dikembalikan/disetujui) cuma untuk menelusuri
 * riwayat, dipilih manual lewat filter, tidak pernah jadi default. Filter status
 * bisa memilih lebih dari satu sekaligus (RF-48 lanjutan).
 */
class VerifikasiList extends Component
{
    public int $tahun;

    public int $triwulan;

    public $bulan = '';

    /**
     * Array kosong = "Semua Status" (gabungan 5 status di statusTersedia(), TIDAK
     * termasuk "draft" karena itu belum diajukan Ketua Tim sama sekali — di luar
     * lingkup pemantauan Tim SAKIP). Multi-pilih: checkbox di toolbar berbagi
     * wire:model="status" yang sama, Livewire otomatis mengumpulkannya jadi array.
     *
     * @var array<int, string>
     */
    public array $status = [Capaian::STATUS_DIAJUKAN, Capaian::STATUS_SEDANG_DITANGANI];

    public string $cari = '';

    public string $urutanKolom = 'periode';

    public string $urutanArah = 'asc';

    /**
     * @return array<int, string>
     */
    public static function statusTersedia(): array
    {
        return [
            Capaian::STATUS_DIAJUKAN,
            Capaian::STATUS_SEDANG_DITANGANI,
            Capaian::STATUS_DIVERIFIKASI,
            Capaian::STATUS_DIKEMBALIKAN,
            Capaian::STATUS_DISETUJUI,
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
        $this->status = [Capaian::STATUS_DIAJUKAN, Capaian::STATUS_SEDANG_DITANGANI];
        $this->urutanKolom = 'periode';
        $this->urutanArah = 'asc';
    }

    /**
     * @return Collection<int, Capaian>
     */
    protected function daftarCapaian()
    {
        // Status kosong ("Semua Status", tidak ada checkbox tercentang) mencakup
        // kelima status alur verifikasi sekaligus (bukan tanpa syarat sama sekali) —
        // draft sengaja dikecualikan karena belum diajukan Ketua Tim, di luar lingkup
        // pemantauan Tim SAKIP. Selain itu, $this->status adalah array multi-pilih
        // (lihat properti di atas) — langsung dipakai apa adanya di whereIn().
        //
        // Difilter langsung dari Capaian::status (SATU status per IKU+bulan, lihat
        // App\Models\Capaian) — bukan lagi menyimpulkan pasangan iku_id+periode_id
        // dari Kegiatan::status_dokumen seperti sebelumnya, karena status_dokumen tiap
        // kegiatan bisa berbeda-beda dalam satu Capaian yang sama (kegiatan diajukan
        // bertahap) sehingga query lama bisa salah menampilkan Capaian di status yang
        // tidak sedang dicari.
        $statusDicari = filled($this->status) ? $this->status : self::statusTersedia();

        $daftar = Capaian::with(['masterIku.timList', 'periode'])
            ->whereIn('status', $statusDicari)
            ->whereHas('periode', function ($q) {
                $q->where('tahun', $this->tahun)->where('triwulan', $this->triwulan);

                if (filled($this->bulan)) {
                    $q->where('bulan', $this->bulan);
                }
            })
            ->get();

        if (filled($this->cari)) {
            $kataKunci = mb_strtolower($this->cari);

            $daftar = $daftar->filter(function ($capaian) use ($kataKunci) {
                return str_contains(mb_strtolower($capaian->masterIku->kode), $kataKunci)
                    || str_contains(mb_strtolower($capaian->masterIku->indikator), $kataKunci)
                    || str_contains(mb_strtolower($capaian->masterIku->timList->pluck('tim')->implode(' ')), $kataKunci);
            });
        }

        $pengurut = match ($this->urutanKolom) {
            'kode' => fn ($c) => $c->masterIku->kode,
            'indikator' => fn ($c) => $c->masterIku->indikator,
            'tim' => fn ($c) => $c->masterIku->timList->pluck('tim')->implode(', '),
            default => fn ($c) => $c->periode_id,
        };

        $daftar = $this->urutanArah === 'asc' ? $daftar->sortBy($pengurut) : $daftar->sortByDesc($pengurut);

        return $daftar->values();
    }

    /**
     * Kegiatan pendukung per Capaian (RF-37), dihitung SEKALI untuk seluruh daftar
     * dalam SATU query (bukan satu query per baris lewat $capaian->kegiatanList() —
     * N+1) — dipakai untuk turunkan jumlah MAUPUN rincian status kegiatan (lihat
     * Kegiatan::rincianStatus()). Status besar yang ditampilkan tetap $capaian->status
     * (lihat App\Models\Capaian); rincian ini cuma pelengkap supaya campurannya (mis.
     * "3 diverifikasi, 2 dikembalikan") tetap terlihat tanpa buka detail.
     *
     * @param  Collection<int, Capaian>  $daftarCapaian
     * @return Collection<int, Collection<int, Kegiatan>>
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

    /**
     * Kendala &amp; Solusi per Capaian, sama polanya dengan kegiatanPerCapaian() di
     * atas — dipakai SEKALIGUS untuk turunkan total "item" MAUPUN rincian statusnya
     * (lihat KendalaSolusi::rincianStatusVerifikasi()), supaya kendala-solusi yang
     * ditolak Tim SAKIP kelihatan di sini juga, bukan cuma lewat badge status besar.
     *
     * @param  Collection<int, Capaian>  $daftarCapaian
     * @return Collection<int, Collection<int, KendalaSolusi>>
     */
    protected function kendalaSolusiPerCapaian($daftarCapaian)
    {
        if ($daftarCapaian->isEmpty()) {
            return collect();
        }

        $perPasangan = KendalaSolusi::whereIn('iku_id', $daftarCapaian->pluck('iku_id'))
            ->whereIn('periode_id', $daftarCapaian->pluck('periode_id'))
            ->get(['iku_id', 'periode_id', 'status_verifikasi'])
            ->groupBy(fn ($k) => $k->iku_id.'-'.$k->periode_id);

        return $daftarCapaian->mapWithKeys(
            fn ($c) => [$c->id => $perPasangan->get($c->iku_id.'-'.$c->periode_id, collect())]
        );
    }

    /**
     * Poin RTL evaluasi triwulan berjalan per Capaian — sama polanya dengan
     * DasborUtama::rtlPerCapaian(), lihat komentar di sana untuk alasan dicocokkan
     * lewat (iku_id, tahun, triwulan) bukan periode_id, dan kenapa hanya poin yang
     * realisasinya sudah dilaporkan Ketua Tim yang disertakan.
     *
     * @param  Collection<int, Capaian>  $daftarCapaian
     * @return Collection<int, Collection<int, RtlEvaluasi>>
     */
    protected function rtlPerCapaian($daftarCapaian)
    {
        if ($daftarCapaian->isEmpty()) {
            return collect();
        }

        $kunciPasangan = fn ($ikuId, $tahun, $triwulan) => "{$ikuId}-{$tahun}-{$triwulan}";

        $pasanganDicari = $daftarCapaian->map(
            fn ($c) => $kunciPasangan($c->iku_id, $c->periode->tahun, $c->periode->triwulan)
        )->unique();

        $perPasangan = RtlEvaluasi::whereIn('iku_id', $daftarCapaian->pluck('iku_id'))
            ->whereNotNull('realisasi')
            ->where('realisasi', '!=', '')
            ->with('periode:id,tahun,triwulan')
            ->get(['id', 'iku_id', 'periode_id', 'realisasi', 'status_verifikasi'])
            ->filter(fn ($r) => $pasanganDicari->contains($kunciPasangan($r->iku_id, $r->periode->tahun, $r->periode->triwulan)))
            ->groupBy(fn ($r) => $kunciPasangan($r->iku_id, $r->periode->tahun, $r->periode->triwulan));

        return $daftarCapaian->mapWithKeys(
            fn ($c) => [$c->id => $perPasangan->get($kunciPasangan($c->iku_id, $c->periode->tahun, $c->periode->triwulan), collect())]
        );
    }

    /**
     * Poin RTL BARU yang ditetapkan Ketua Tim untuk triwulan BERIKUTNYA — sama
     * polanya dengan DasborUtama::rtlBerikutnyaPerCapaian(), lihat komentar di sana
     * untuk alasan dicocokkan lewat (iku_id, tahun, triwulan) SASARAN (bukan
     * periode_id Capaian ini).
     *
     * @param  Collection<int, Capaian>  $daftarCapaian
     * @return Collection<int, Collection<int, RtlEvaluasi>>
     */
    protected function rtlBerikutnyaPerCapaian($daftarCapaian)
    {
        if ($daftarCapaian->isEmpty()) {
            return collect();
        }

        $kunciPasangan = fn ($ikuId, $tahun, $triwulan) => "{$ikuId}-{$tahun}-{$triwulan}";

        $sasaran = $daftarCapaian->mapWithKeys(function ($c) use ($kunciPasangan) {
            $triwulanBerikutnya = $c->periode->triwulan === 4 ? 1 : $c->periode->triwulan + 1;
            $tahunBerikutnya = $c->periode->triwulan === 4 ? $c->periode->tahun + 1 : $c->periode->tahun;

            return [$c->id => $kunciPasangan($c->iku_id, $tahunBerikutnya, $triwulanBerikutnya)];
        });

        $perPasangan = RtlEvaluasi::whereIn('iku_id', $daftarCapaian->pluck('iku_id'))
            ->with('periode:id,tahun,triwulan')
            ->get(['id', 'iku_id', 'periode_id', 'status_verifikasi'])
            ->filter(fn ($r) => $sasaran->contains($kunciPasangan($r->iku_id, $r->periode->tahun, $r->periode->triwulan)))
            ->groupBy(fn ($r) => $kunciPasangan($r->iku_id, $r->periode->tahun, $r->periode->triwulan));

        return $daftarCapaian->mapWithKeys(
            fn ($c) => [$c->id => $perPasangan->get($sasaran->get($c->id), collect())]
        );
    }

    public function render()
    {
        $daftarCapaian = $this->daftarCapaian();
        $kegiatanPerCapaian = $this->kegiatanPerCapaian($daftarCapaian);
        $kendalaSolusiPerCapaian = $this->kendalaSolusiPerCapaian($daftarCapaian);
        $rtlPerCapaian = $this->rtlPerCapaian($daftarCapaian);
        $rtlBerikutnyaPerCapaian = $this->rtlBerikutnyaPerCapaian($daftarCapaian);

        // "Item" = seluruh jenis isian pendukung satu Capaian (Kegiatan + Kendala &
        // Solusi + evaluasi RTL triwulan sebelumnya + RTL BARU triwulan berikutnya) —
        // lihat DasborUtama::render() untuk alasan yang sama.
        $jumlahItem = $daftarCapaian->mapWithKeys(fn ($c) => [$c->id => (
            $kegiatanPerCapaian->get($c->id, collect())->count()
            + $kendalaSolusiPerCapaian->get($c->id, collect())->count()
            + $rtlPerCapaian->get($c->id, collect())->count()
            + $rtlBerikutnyaPerCapaian->get($c->id, collect())->count()
        )]);

        return view('livewire.verifikasi-list', [
            'daftarCapaian' => $daftarCapaian,
            'timPerCapaian' => Capaian::timTampilBanyak($daftarCapaian),
            'jumlahItem' => $jumlahItem,
            'rincianStatusKegiatan' => $kegiatanPerCapaian->map(fn ($g) => Kegiatan::rincianStatus($g)),
            'rincianStatusKendala' => $kendalaSolusiPerCapaian->map(fn ($g) => KendalaSolusi::rincianStatusVerifikasi($g)),
            'rincianStatusRtl' => $rtlPerCapaian->map(fn ($g) => RtlEvaluasi::rincianStatusVerifikasi($g)),
            'rincianStatusRtlBerikutnya' => $rtlBerikutnyaPerCapaian->map(fn ($g) => RtlEvaluasi::rincianStatusVerifikasi($g)),
            'statusTersedia' => self::statusTersedia(),
        ]);
    }
}
