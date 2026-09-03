<?php

namespace App\Livewire;

use App\Models\IkuPengecualian;
use App\Models\IkuPenugasan;
use App\Models\IkuTim;
use App\Models\MasterIku;
use App\Models\User;
use App\Models\UserTim;
use Livewire\Component;

/**
 * Penugasan IKU — tabel datar (bukan dikelompokkan per tim, lihat perbaikan
 * tampilan) menampilkan penanggung jawab otomatis (via keanggotaan tim, chip
 * abu-abu, bisa dikecualikan per-IKU tanpa mengeluarkan dari tim) dan penanggung
 * jawab manual (tambahan/override, chip biru yang bisa dihapus, ditambah satu
 * per satu lewat dropdown ramping). Satu IKU boleh ditugaskan ke LEBIH DARI SATU
 * tim sekaligus (lihat App\Models\IkuTim) — kolom Tim jadi chip yang bisa
 * ditambah/dihapus satu per satu, sama pola UX-nya dengan chip PJ manual di
 * bawahnya dan dengan keanggotaan tim di App\Livewire\AkunAktif.
 */
class PenugasanIku extends Component
{
    public string $cari = '';

    /** '' = semua, 'ada' = sudah ada PJ, 'belum' = belum ada PJ. */
    public string $filterStatus = '';

    public string $urutanKolom = 'kode';

    public string $urutanArah = 'asc';

    /**
     * Input "tambah tim" per IKU, dikunci pada id IKU — mengikuti pola
     * App\Livewire\AkunAktif::$timBaru.
     *
     * @var array<int, string>
     */
    public array $timBaru = [];

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
        $this->filterStatus = '';
        $this->urutanKolom = 'kode';
        $this->urutanArah = 'asc';
    }

    /**
     * Tambah satu tim penanggung jawab ke IKU ini — begitu tim ditambah, chip PJ
     * "via tim" otomatis langsung ikut mengikuti keanggotaan tim tersebut. Satu
     * IKU boleh punya lebih dari satu tim, tambahkan satu per satu lewat ini.
     * Mengikuti pola App\Livewire\AkunAktif::tambahTim() -- nilai tim diambil dari
     * $timBaru[$ikuId] (wire:model), bukan lewat parameter, supaya tombol ＋ dan
     * Enter di kotak input sama-sama bisa memicunya tanpa mengetik ulang nilainya.
     */
    public function tambahTim(int $ikuId): void
    {
        $tim = trim($this->timBaru[$ikuId] ?? '');

        if ($tim === '') {
            return;
        }

        IkuTim::firstOrCreate(['iku_id' => $ikuId, 'tim' => $tim]);
        $this->timBaru[$ikuId] = '';

        MasterIku::lupakanCache();

        session()->flash('status', 'Tim ditambahkan ke IKU ini.');
    }

    public function hapusTim(int $ikuTimId): void
    {
        IkuTim::whereKey($ikuTimId)->delete();

        MasterIku::lupakanCache();

        session()->flash('status', 'Tim dihapus dari IKU ini.');
    }

    public function tambahManual(int $ikuId, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        IkuPenugasan::firstOrCreate(['iku_id' => $ikuId, 'user_id' => $userId]);

        session()->flash('status', 'Penanggung jawab berhasil ditambahkan.');
    }

    public function hapusManual(int $penugasanId): void
    {
        IkuPenugasan::whereKey($penugasanId)->delete();

        session()->flash('status', 'Penugasan IKU dihapus.');
    }

    /**
     * Kecualikan satu anggota tim dari penanggung jawab OTOMATIS IKU ini saja —
     * TIDAK mengeluarkannya dari keanggotaan tim (tetap dihitung untuk IKU lain
     * di tim yang sama).
     */
    public function kecualikanOtomatis(int $ikuId, int $userId): void
    {
        IkuPengecualian::firstOrCreate(['iku_id' => $ikuId, 'user_id' => $userId]);

        session()->flash('status', 'Orang tersebut dikecualikan dari penanggung jawab otomatis IKU ini.');
    }

    public function sertakanKembali(int $pengecualianId): void
    {
        IkuPengecualian::whereKey($pengecualianId)->delete();

        session()->flash('status', 'Penanggung jawab otomatis disertakan kembali.');
    }

    public function render()
    {
        $ikuList = MasterIku::with(['penugasanManual.user', 'pengecualianOtomatis', 'timList'])->get();

        $ketuaTimList = User::whereHas('role', fn ($q) => $q->where('nama', 'Ketua Tim'))
            ->where('status_verifikasi', 'terverifikasi')
            ->orderBy('nama')
            ->get();

        // Satu query untuk anggota tim SELURUH tim yang muncul di $ikuList (satu IKU
        // boleh punya lebih dari satu tim, lihat App\Models\IkuTim), dikelompokkan
        // per tim di PHP — bukan satu query per baris IKU.
        $semuaNamaTim = $ikuList->flatMap(fn ($iku) => $iku->timList->pluck('tim'))->unique();

        $anggotaPerTim = UserTim::with('user')
            ->whereIn('tim', $semuaNamaTim)
            ->get()
            ->groupBy('tim')
            ->map(fn ($baris) => $baris->pluck('user')->filter()->unique('id')->values());

        // Satu query untuk keanggotaan tim SEMUA ketua tim, dikelompokkan per user di PHP —
        // dipakai untuk urutan & label kandidat PJ manual. JANGAN panggil User::namaTimList()
        // di dalam loop/komparator di bawah: method itu selalu query ulang ke DB (bukan
        // memakai relasi yang sudah di-eager-load), jadi dipanggil ratusan kali per render
        // (tiap perbandingan sortBy × tiap kandidat dirender) dan bikin halaman ini lambat.
        $timPerUser = UserTim::whereIn('user_id', $ketuaTimList->pluck('id'))
            ->get(['user_id', 'tim'])
            ->groupBy('user_id')
            ->map(fn ($baris) => $baris->pluck('tim')->all());

        // Susun data per-IKU sekali di sini (otomatis dikurangi pengecualian, kandidat
        // manual, dst) supaya blade tidak menghitung ulang & supaya pencarian/filter
        // status bisa memakainya langsung.
        $data = $ikuList->map(function (MasterIku $iku) use ($anggotaPerTim, $ketuaTimList, $timPerUser) {
            $namaTimIku = $iku->timList->pluck('tim')->all();

            $anggotaTim = collect($namaTimIku)
                ->flatMap(fn ($tim) => $anggotaPerTim->get($tim, collect()))
                ->unique('id')
                ->values();
            $idDikecualikan = $iku->pengecualianOtomatis->pluck('user_id');

            $otomatis = $anggotaTim->whereNotIn('id', $idDikecualikan)->values();
            $dikecualikan = $iku->pengecualianOtomatis
                ->map(fn ($p) => ['pengecualian_id' => $p->id, 'user' => $anggotaTim->firstWhere('id', $p->user_id)])
                ->filter(fn ($p) => $p['user'] !== null)
                ->values();
            $manual = $iku->penugasanManual;

            $sudahDitugaskan = $manual->pluck('user_id')->merge($otomatis->pluck('id'));
            $kandidat = $ketuaTimList->whereNotIn('id', $sudahDitugaskan)
                ->sortBy([
                    fn ($a, $b) => (array_intersect($namaTimIku, $timPerUser->get($b->id, [])) !== []) <=> (array_intersect($namaTimIku, $timPerUser->get($a->id, [])) !== []),
                    fn ($a, $b) => $a->nama <=> $b->nama,
                ])
                ->values();

            return [
                'iku' => $iku,
                'namaTimIku' => $namaTimIku,
                'otomatis' => $otomatis,
                'dikecualikan' => $dikecualikan,
                'manual' => $manual,
                'kandidat' => $kandidat,
                'punya_pj' => $otomatis->isNotEmpty() || $manual->isNotEmpty(),
            ];
        });

        $totalTanpaPenanggungJawab = $data->filter(fn ($baris) => ! $baris['punya_pj'])->count();

        if (filled($this->cari)) {
            $kataKunci = mb_strtolower($this->cari);

            $data = $data->filter(function ($baris) use ($kataKunci) {
                $iku = $baris['iku'];

                if (str_contains(mb_strtolower($iku->kode), $kataKunci)
                    || str_contains(mb_strtolower($iku->indikator), $kataKunci)
                    || collect($baris['namaTimIku'])->contains(fn ($tim) => str_contains(mb_strtolower($tim), $kataKunci))) {
                    return true;
                }

                $namaTerkait = $baris['otomatis']->pluck('nama')
                    ->merge($baris['manual']->pluck('user.nama'));

                return $namaTerkait->contains(fn ($nama) => str_contains(mb_strtolower((string) $nama), $kataKunci));
            })->values();
        }

        if ($this->filterStatus === 'ada') {
            $data = $data->filter(fn ($baris) => $baris['punya_pj'])->values();
        } elseif ($this->filterStatus === 'belum') {
            $data = $data->filter(fn ($baris) => ! $baris['punya_pj'])->values();
        }

        $data = $data->sortBy(
            fn ($baris) => $this->urutanKolom === 'indikator' ? $baris['iku']->indikator : $baris['iku']->kode,
            SORT_REGULAR,
            $this->urutanArah === 'desc'
        )->values();

        return view('livewire.penugasan-iku', [
            'data' => $data,
            'timPerUser' => $timPerUser,
            'totalIku' => $ikuList->count(),
            'totalTim' => $semuaNamaTim->count(),
            'daftarTim' => MasterIku::daftarTimGabungan(),
            'totalTanpaPenanggungJawab' => $totalTanpaPenanggungJawab,
        ]);
    }
}
