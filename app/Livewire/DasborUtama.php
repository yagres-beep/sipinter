<?php

namespace App\Livewire;

use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\RtlEvaluasi;
use Livewire\Component;

/**
 * Dasbor ringkas (RF-47) — dibagikan ketiga peran, satu tempat memantau status
 * pengisian & capaian secara menyeluruh. Tabel "Status Pengisian per IKU" bisa
 * dicari, difilter triwulan/bulan, diurutkan per kolom, dan tiap baris bisa
 * diklik menuju halaman yang relevan sesuai peran yang sedang login.
 */
class DasborUtama extends Component
{
    public string $cari = '';

    public string $filterTriwulan = '';

    public string $filterBulan = '';

    public string $urutanKolom = 'periode';

    public string $urutanArah = 'desc';

    public function urutkan(string $kolom): void
    {
        if ($this->urutanKolom === $kolom) {
            $this->urutanArah = $this->urutanArah === 'asc' ? 'desc' : 'asc';
        } else {
            $this->urutanKolom = $kolom;
            $this->urutanArah = 'asc';
        }
    }

    public function updatedFilterTriwulan(): void
    {
        $this->filterBulan = '';
    }

    /**
     * Sinkron dua arah: begitu bulan dipilih langsung (tanpa lebih dulu memilih
     * triwulan), triwulan ikut menyesuaikan supaya daftar bulan yang tampil tetap
     * konsisten dengan triwulan yang sedang aktif.
     */
    public function updatedFilterBulan(): void
    {
        if (filled($this->filterBulan)) {
            $this->filterTriwulan = (string) (int) ceil(((int) $this->filterBulan) / 3);
        }
    }

    public function resetFilter(): void
    {
        $this->cari = '';
        $this->filterTriwulan = '';
        $this->filterBulan = '';
        $this->urutanKolom = 'periode';
        $this->urutanArah = 'desc';
    }

    /**
     * IKU yang SAMA SEKALI belum punya kegiatan pada triwulan berjalan (tahun & triwulan
     * saat ini) — bukan pemblokiran (satu IKU boleh saja tidak diisi tiap bulan), hanya
     * pengingat visual karena tiap IKU tetap diharapkan terisi minimal sekali per triwulan.
     *
     * @return \Illuminate\Support\Collection<int, MasterIku>
     */
    protected function ikuBelumTerisiTriwulanIni()
    {
        $triwulanIni = (int) ceil(now()->month / 3);
        $tahunIni = now()->year;

        $ikuSudahTerisi = Kegiatan::whereHas(
            'periode',
            fn ($q) => $q->where('tahun', $tahunIni)->where('triwulan', $triwulanIni)
        )->pluck('iku_id')->unique();

        return MasterIku::whereNotIn('id', $ikuSudahTerisi)->orderBy('kode')->get();
    }

    protected function ringkasan(): array
    {
        $lewatTenggat = RtlEvaluasi::whereNotNull('batas_waktu')
            ->where('batas_waktu', '<', now())
            ->where(fn ($q) => $q->whereNull('realisasi')->orWhere('realisasi', ''))
            ->count();

        // Satu query dengan agregat bersyarat (FILTER, sintaks Postgres) alih-alih dua
        // query Kegiatan::count() terpisah untuk status yang berbeda.
        $statusKegiatan = Kegiatan::selectRaw(
            "count(*) filter (where status_dokumen in ('diverifikasi','disetujui')) as sudah_diverifikasi,
             count(*) filter (where status_dokumen = 'diajukan') as menunggu_verifikasi"
        )->first();

        return [
            'total_iku' => MasterIku::count(),
            'sudah_diverifikasi' => (int) $statusKegiatan->sudah_diverifikasi,
            'menunggu_verifikasi' => (int) $statusKegiatan->menunggu_verifikasi,
            'lewat_tenggat' => $lewatTenggat,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Kegiatan>
     */
    protected function daftarKegiatan()
    {
        $query = Kegiatan::with(['masterIku', 'periode']);

        if (filled($this->filterTriwulan)) {
            $query->whereHas('periode', fn ($q) => $q->where('triwulan', $this->filterTriwulan));
        }

        if (filled($this->filterBulan)) {
            $query->whereHas('periode', fn ($q) => $q->where('bulan', $this->filterBulan));
        }

        $daftar = $query->get();

        if (filled($this->cari)) {
            $kataKunci = mb_strtolower($this->cari);

            $daftar = $daftar->filter(function ($kegiatan) use ($kataKunci) {
                return str_contains(mb_strtolower($kegiatan->masterIku->kode ?? ''), $kataKunci)
                    || str_contains(mb_strtolower($kegiatan->masterIku->indikator ?? ''), $kataKunci)
                    || str_contains(mb_strtolower($kegiatan->masterIku->tim ?? ''), $kataKunci);
            });
        }

        $pengurut = match ($this->urutanKolom) {
            'kode' => fn ($k) => $k->masterIku->kode ?? '',
            'tim' => fn ($k) => $k->masterIku->tim ?? '',
            'status' => fn ($k) => $k->status_dokumen,
            default => fn ($k) => $k->periode_id,
        };

        $daftar = $this->urutanArah === 'asc' ? $daftar->sortBy($pengurut) : $daftar->sortByDesc($pengurut);

        return $daftar->take(20)->values();
    }

    /**
     * Tautan baris sesuai peran yang login — Tim SAKIP menuju detail verifikasi
     * (bila masih diajukan), Ketua Tim &amp; Kepala menuju halaman kerja utama mereka
     * (tidak ada halaman detail per kegiatan untuk kedua peran ini).
     *
     * Untuk Tim SAKIP, seluruh Capaian yang dibutuhkan diambil SEKALI (bukan satu
     * query per baris kegiatan — N+1) lalu dicocokkan di PHP.
     *
     * @param  \Illuminate\Support\Collection<int, Kegiatan>  $daftarKegiatan
     * @return \Illuminate\Support\Collection<int, ?string>
     */
    protected function tautanSemuaBaris($daftarKegiatan, string $role)
    {
        if ($role === 'Ketua Tim') {
            return $daftarKegiatan->mapWithKeys(fn ($k) => [$k->id => route('pengisian.index')]);
        }

        if ($role === 'Kepala') {
            return $daftarKegiatan->mapWithKeys(fn ($k) => [$k->id => route('persetujuan.index')]);
        }

        if ($role === 'Tim SAKIP') {
            $capaianPerPasangan = Capaian::whereIn('periode_id', $daftarKegiatan->pluck('periode_id')->unique())
                ->get()
                ->keyBy(fn ($c) => $c->iku_id.'-'.$c->periode_id);

            return $daftarKegiatan->mapWithKeys(function ($k) use ($capaianPerPasangan) {
                $capaian = $capaianPerPasangan->get($k->iku_id.'-'.$k->periode_id);

                return [$k->id => $capaian ? route('verifikasi.show', $capaian) : null];
            });
        }

        return $daftarKegiatan->mapWithKeys(fn ($k) => [$k->id => null]);
    }

    public function render()
    {
        $role = auth()->user()->namaRole();
        $daftarKegiatan = $this->daftarKegiatan();

        return view('livewire.dasbor-utama', [
            'role' => $role,
            'ringkasan' => $this->ringkasan(),
            'daftarKegiatan' => $daftarKegiatan,
            'tautanBaris' => $this->tautanSemuaBaris($daftarKegiatan, $role),
            'ikuBelumTerisiTriwulanIni' => $this->ikuBelumTerisiTriwulanIni(),
            'triwulanBerjalan' => (int) ceil(now()->month / 3),
        ]);
    }
}
